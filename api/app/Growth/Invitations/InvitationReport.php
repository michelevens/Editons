<?php

namespace App\Growth\Invitations;

use App\Models\AdminAuditLog;
use App\Models\AuthorInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the console shows about invitations: the filtered list, counts by
 * state, the funnel for the people who accepted, and one invitation's
 * timeline. Counted from the rows, as Growth's funnel is.
 *
 * "Expired" includes pending rows past their date that invitations:expire
 * has not swept yet, so the counts never show a dead link as pending.
 */
final class InvitationReport
{
    public const FUNNEL = [
        'invited' => 'Invited',
        'accepted' => 'Accepted',
        'first_book' => 'First book',
        'first_chapter' => 'First chapter saved',
        'first_export' => 'First export',
    ];

    /** @return array{status: ?string, batch: ?int, inviter: ?int, from: ?string, to: ?string, q: ?string} */
    public static function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;
        $status = (string) $request->query('status');

        return [
            'status' => in_array($status, AuthorInvitation::STATUSES, true) ? $status : null,
            'batch' => ctype_digit((string) $request->query('batch')) ? (int) $request->query('batch') : null,
            'inviter' => ctype_digit((string) $request->query('inviter')) ? (int) $request->query('inviter') : null,
            'from' => $date('from'),
            'to' => $date('to'),
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100) ?: null,
        ];
    }

    /** Every filter but status. */
    private function base(array $filters): Builder
    {
        return AuthorInvitation::query()
            ->when($filters['batch'] ?? null, fn ($q, $id) => $q->where('batch_id', $id))
            ->when($filters['inviter'] ?? null, fn ($q, $id) => $q->where('invited_by', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
                $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'ilike', $like));
            });
    }

    public function query(array $filters): Builder
    {
        $query = $this->base($filters);

        return match ($filters['status'] ?? null) {
            null => $query,
            'pending' => $query->where('status', 'pending')->where('expires_at', '>', now()),
            'expired' => $query->where(fn ($w) => $w->where('status', 'expired')
                ->orWhere(fn ($p) => $p->where('status', 'pending')->where('expires_at', '<=', now()))),
            default => $query->where('status', $filters['status']),
        };
    }

    /** @return array<string, int> */
    public function counts(array $filters): array
    {
        $row = $this->base($filters)->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'pending' and expires_at > now()) as pending")
            ->selectRaw("count(*) filter (where status = 'accepted') as accepted")
            ->selectRaw("count(*) filter (where status = 'expired' or (status = 'pending' and expires_at <= now())) as expired")
            ->selectRaw("count(*) filter (where status = 'revoked') as revoked")
            ->selectRaw("count(*) filter (where status = 'declined') as declined")
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Invited → accepted → first book → first chapter → first export, for
     * the invitations the filters select (status aside: a funnel is about
     * everybody invited).
     *
     * @return array{steps: array<string, string>, counts: array<string, int>}
     */
    public function funnel(array $filters): array
    {
        $row = $this->base($filters)->toBase()
            ->selectRaw('count(*) as invited')
            ->selectRaw('count(accepted_user_id) as accepted')
            ->selectRaw('count(*) filter (where exists (select 1 from books where books.user_id = author_invitations.accepted_user_id)) as first_book')
            ->selectRaw('count(*) filter (where exists (select 1 from manuscript_versions v where v.user_id = author_invitations.accepted_user_id and v.impersonator_id is null)) as first_chapter')
            ->selectRaw('count(*) filter (where exists (select 1 from book_exports e join books b on b.id = e.book_id where b.user_id = author_invitations.accepted_user_id)) as first_export')
            ->first();

        return ['steps' => self::FUNNEL, 'counts' => array_map('intval', (array) $row)];
    }

    /**
     * What happened to one invitation, oldest first: every audited change
     * (sent, resent, revoked, accepted, declined), the expiry, and once
     * accepted, the account's first book, chapter and export.
     *
     * @return list<array{at: string, event: string, detail: ?string, actor: ?string}>
     */
    public function timeline(AuthorInvitation $invitation): array
    {
        $labels = [
            'invitation.sent' => 'Invited',
            'invitation.resent' => 'Sent again with a new link',
            'invitation.revoked' => 'Revoked',
            'invitation.accepted' => 'Accepted',
            'invitation.declined' => 'Declined: asked not to be invited again',
        ];

        $events = AdminAuditLog::with('actor:id,name')
            ->where('subject_type', 'AuthorInvitation')
            ->where('subject_id', $invitation->id)
            ->oldest('id')
            ->get()
            ->filter(fn (AdminAuditLog $log) => isset($labels[$log->action]))
            ->map(fn (AdminAuditLog $log) => [
                'at' => $log->created_at->toIso8601String(),
                'event' => $labels[$log->action],
                'detail' => $log->reason,
                'actor' => $log->action === 'invitation.declined' ? null : ($log->actor?->name ?? 'Ecrivain'),
            ])
            ->values()
            ->all();

        if ($invitation->state() === 'expired') {
            $events[] = ['at' => ($invitation->expired_at ?? $invitation->expires_at)->toIso8601String(), 'event' => 'Expired', 'detail' => null, 'actor' => null];
        }

        if ($invitation->accepted_user_id) {
            $id = $invitation->accepted_user_id;
            $firsts = [
                'First book' => DB::table('books')->where('user_id', $id)->min('created_at'),
                'First chapter saved' => DB::table('manuscript_versions')->where('user_id', $id)->whereNull('impersonator_id')->min('created_at'),
                'First export' => DB::table('book_exports')->join('books', 'books.id', '=', 'book_exports.book_id')->where('books.user_id', $id)->min('book_exports.created_at'),
            ];

            foreach ($firsts as $event => $at) {
                if ($at) {
                    $events[] = ['at' => Carbon::parse($at)->toIso8601String(), 'event' => $event, 'detail' => null, 'actor' => null];
                }
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }
}
