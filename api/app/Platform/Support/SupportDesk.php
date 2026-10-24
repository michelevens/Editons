<?php

namespace App\Platform\Support;

use App\Inbox\Inbox;
use App\Mail\Account\SupportReplyMail;
use App\Mail\Notify;
use App\Models\Book;
use App\Models\ErrorGroup;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Support requests, from the author's Help form to staff's reply.
 *
 * An author writes; the ticket is open (waiting for staff). Staff reply —
 * the author gets an email and an inbox item, and the ticket is pending
 * (waiting for the author) — or add an internal note, which the author
 * never sees and nobody is emailed. An author's reply opens it again.
 * Every staff write is audited, like everything else done from the console.
 */
final class SupportDesk
{
    /**
     * A new request. The context is what support would otherwise ask for
     * first: the plan, the browser, the last error this person hit.
     *
     * @param  array{browser?: ?string, viewport?: ?string, last_error?: ?string, page?: ?string}  $client
     */
    public function open(User $author, string $subject, string $body, ?Book $book, array $client, ?string $userAgent): SupportTicket
    {
        if ($book && ! $author->can('view', $book)) {
            throw ValidationException::withMessages(['book_id' => 'That book is not one of yours.']);
        }

        $context = array_filter([
            'plan' => $author->planLimits()['name'] ?? null,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 300) : null,
            'viewport' => self::clip($client['viewport'] ?? null, 40),
            'page' => self::clip($client['page'] ?? null, 300),
            'last_error' => self::clip($client['last_error'] ?? null, 500),
            // The last server error this account hit in the past day, if any.
            'last_server_error' => ErrorGroup::where('example_user_id', $author->id)
                ->where('source', 'server')
                ->where('last_seen_at', '>=', now()->subDay())
                ->latest('last_seen_at')
                ->value('message'),
        ], fn ($v) => $v !== null && $v !== '');

        return DB::transaction(function () use ($author, $subject, $body, $book, $context) {
            $ticket = SupportTicket::create([
                'user_id' => $author->id,
                'book_id' => $book?->id,
                'subject' => trim($subject),
                'status' => 'open',
                'context' => $context ?: null,
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create(['author_id' => $author->id, 'body' => trim($body)]);

            return $ticket;
        });
    }

    /** The author writes again: waiting for staff once more. */
    public function authorReply(SupportTicket $ticket, User $author, string $body): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $author, $body) {
            $message = $ticket->messages()->create(['author_id' => $author->id, 'body' => trim($body)]);
            $ticket->update(['status' => 'open', 'closed_at' => null, 'last_message_at' => now()]);

            return $message;
        });
    }

    /**
     * Staff answer, or note. A reply is emailed and put in the author's
     * inbox; a note is not, and stays between staff.
     */
    public function staffReply(SupportTicket $ticket, User $staff, string $body, bool $internal, bool $aiDrafted = false): SupportMessage
    {
        $message = AdminAudit::transaction(function () use ($ticket, $staff, $body, $internal, $aiDrafted) {
            $message = $ticket->messages()->create([
                'author_id' => $staff->id,
                'from_staff' => true,
                'internal' => $internal,
                'body' => trim($body),
                'ai_drafted' => $aiDrafted,
            ]);

            if (! $internal) {
                $ticket->update(['status' => 'pending', 'last_message_at' => now(), 'closed_at' => null]);
            }

            // Only the fact, not the text: the ticket holds the words.
            AdminAudit::record($internal ? 'support.noted' : 'support.replied', $ticket, null, ['message_id' => $message->id, 'ai_drafted' => $aiDrafted], null, $staff);

            return $message;
        });

        if (! $internal && $ticket->user) {
            Notify::send($ticket->user, new SupportReplyMail($ticket, $message));

            Inbox::record(
                $ticket->user,
                'support',
                config('platform.staff_name').' replied: '.$ticket->subject,
                target: ['support_ticket_id' => $ticket->id],
                body: $message->body,
                group: "support:{$ticket->id}",
            );
        }

        return $message;
    }

    /** Status and assignee, audited with what changed. */
    public function update(SupportTicket $ticket, User $staff, array $changes): void
    {
        $values = [];

        if (array_key_exists('status', $changes)) {
            if (! in_array($changes['status'], SupportTicket::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Choose open, pending or closed.']);
            }

            $values['status'] = $changes['status'];
            $values['closed_at'] = $changes['status'] === 'closed' ? now() : null;
        }

        if (array_key_exists('assignee_id', $changes)) {
            $id = $changes['assignee_id'] === null ? null : (int) $changes['assignee_id'];

            if ($id !== null && ! User::whereKey($id)->whereNotNull('platform_role')->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'Tickets can be assigned to staff only.']);
            }

            $values['assignee_id'] = $id;
        }

        $before = array_intersect_key($ticket->only(['status', 'assignee_id']), $values);
        [$from, $to] = AdminAudit::diff($before, array_intersect_key($values, $before));

        if ($to === []) {
            return;
        }

        AdminAudit::transaction(function () use ($ticket, $values, $from, $to, $staff) {
            $ticket->update($values);
            AdminAudit::record('support.updated', $ticket, $from, $to, null, $staff);
        });
    }

    private static function clip(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
