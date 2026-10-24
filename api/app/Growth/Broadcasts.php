<?php

namespace App\Growth;

use App\Growth\Jobs\SendBroadcastBatch;
use App\Mail\Growth\BroadcastMail;
use App\Mail\Notify;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\InboxItem;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Broadcasts: one email, composed in the console, to an audience.
 *
 * Owner only, every step audited. The life of one:
 *
 *   draft → scheduled (a time, or now) → sending → sent
 *                     ↘ cancelled (only before it starts)
 *
 * `growth:send-broadcasts` runs every minute. When a broadcast is due it is
 * claimed (scheduled → sending, one UPDATE, so two schedulers cannot both
 * start it), its audience is resolved once into broadcast_recipients — one
 * row per account, unique, so nobody gets it twice whatever retries — and
 * the rows are sent in batches of `growth.broadcasts.per_minute`, each batch
 * a queued job delayed a minute after the last.
 *
 * Only people whose preferences allow "News from Écrivain" are emailed; the
 * check is made when the audience is resolved and again by Notify::send at
 * the moment of sending, so an unsubscribe in between is honoured. With the
 * in-app option, an inbox item goes to everybody in the audience — the
 * inbox is not email, and the preference is about email.
 *
 * Stats are what is known: sent (handed to the mailer), failed, skipped.
 * Opens and clicks are not tracked, and the page says so.
 */
final class Broadcasts
{
    /**
     * @param  array{subject: string, preheader: ?string, body: string, cta_label: ?string, cta_url: ?string, audience_id: ?int, inbox: bool}  $data
     */
    public function save(?Broadcast $broadcast, array $data, User $actor): Broadcast
    {
        if ($broadcast && ! $broadcast->editable()) {
            throw ValidationException::withMessages(['broadcast' => 'A broadcast that has started cannot be changed.']);
        }

        return AdminAudit::transaction(function () use ($broadcast, $data, $actor) {
            $before = $broadcast ? self::auditable($broadcast) : null;
            $broadcast ??= new Broadcast(['status' => 'draft', 'created_by' => $actor->id]);
            $broadcast->fill([
                'subject' => $data['subject'],
                'preheader' => $data['preheader'] ?: null,
                'body' => $data['body'],
                'cta_label' => $data['cta_label'] ?: null,
                'cta_url' => $data['cta_url'] ?: null,
                'audience_id' => $data['audience_id'] ?: null,
                'inbox' => (bool) $data['inbox'],
            ])->save();

            [$from, $to] = AdminAudit::diff($before ?? [], self::auditable($broadcast));
            AdminAudit::record($before ? 'broadcast.updated' : 'broadcast.created', $broadcast, $before ? $from : null, $to, null, $actor);

            return $broadcast;
        });
    }

    /** Send at $at, or as soon as the scheduler next runs. */
    public function schedule(Broadcast $broadcast, ?Carbon $at, string $reason, User $actor): Broadcast
    {
        if (! $broadcast->editable()) {
            throw ValidationException::withMessages(['broadcast' => 'This broadcast has already started.']);
        }

        if ($at && $at->isPast() && $at->diffInMinutes(now()) > 1) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a time in the future, or send now.']);
        }

        return AdminAudit::transaction(function () use ($broadcast, $at, $reason, $actor) {
            $before = ['status' => $broadcast->status, 'scheduled_at' => $broadcast->scheduled_at?->toIso8601String()];
            $broadcast->forceFill(['status' => 'scheduled', 'scheduled_at' => $at ?? now()])->save();

            AdminAudit::record('broadcast.scheduled', $broadcast, $before, [
                'status' => 'scheduled',
                'scheduled_at' => $broadcast->scheduled_at->toIso8601String(),
                'audience' => $broadcast->audience?->name ?? 'Every account',
                'reachable_now' => $this->audienceQuery($broadcast, emailable: true)->count(),
            ], $reason, $actor);

            return $broadcast;
        });
    }

    public function cancel(Broadcast $broadcast, string $reason, User $actor): Broadcast
    {
        return AdminAudit::transaction(function () use ($broadcast, $reason, $actor) {
            // Only while it has not been claimed by the sender.
            $changed = Broadcast::whereKey($broadcast->id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'cancelled']);

            if ($changed === 0) {
                throw ValidationException::withMessages(['broadcast' => 'This broadcast has already started sending.']);
            }

            AdminAudit::record('broadcast.cancelled', $broadcast, ['status' => $broadcast->status], ['status' => 'cancelled'], $reason, $actor);

            return $broadcast->fresh();
        });
    }

    /** The broadcast, to the owner only, now. */
    public function testSend(Broadcast $broadcast, User $actor): bool
    {
        $sent = Notify::send($actor->email, $this->mail($broadcast, $actor, test: true));

        AdminAudit::transaction(fn () => AdminAudit::record('broadcast.test_sent', $broadcast, null, ['to' => $actor->email, 'sent' => $sent], null, $actor));

        return $sent;
    }

    public function mail(Broadcast $broadcast, ?User $user, bool $test = false): BroadcastMail
    {
        return new BroadcastMail(
            user: $user,
            headline: $broadcast->subject,
            body: $broadcast->body,
            preheaderText: $broadcast->preheader,
            ctaLabel: $broadcast->cta_label,
            ctaUrl: $broadcast->cta_url,
            test: $test,
        );
    }

    /**
     * Start every broadcast that is due. Returns how many were started.
     */
    public function dispatchDue(): int
    {
        $due = Broadcast::where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get();
        $started = 0;

        foreach ($due as $broadcast) {
            // The claim: whoever flips the status starts it.
            if (Broadcast::whereKey($broadcast->id)->where('status', 'scheduled')->update(['status' => 'sending', 'started_at' => now()]) === 0) {
                continue;
            }

            $this->start($broadcast->fresh());
            $started++;
        }

        return $started;
    }

    /** Resolve the audience into recipient rows, then queue the batches. */
    public function start(Broadcast $broadcast): void
    {
        $audience = $broadcast->audience;
        $broadcast->forceFill([
            'audience_name' => $audience?->name ?? 'Every account',
            'audience_rules' => AudienceRules::normalize($audience?->rules),
        ])->save();

        $now = now();
        $everyone = AudienceRules::query($broadcast->audience_rules)->select('users.id');
        $emailable = AudienceRules::allowing(AudienceRules::query($broadcast->audience_rules), BroadcastMail::CATEGORY)
            ->pluck('users.id')
            ->flip();

        $everyone->orderBy('users.id')->chunk(1000, function ($users) use ($broadcast, $emailable, $now) {
            BroadcastRecipient::insertOrIgnore($users->map(fn (User $u) => [
                'broadcast_id' => $broadcast->id,
                'user_id' => $u->id,
                'status' => isset($emailable[$u->id]) ? 'queued' : 'skipped',
                'skip_reason' => isset($emailable[$u->id]) ? null : 'preferences',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        $this->refreshCounts($broadcast);

        $perMinute = (int) config('growth.broadcasts.per_minute');
        $ids = BroadcastRecipient::where('broadcast_id', $broadcast->id)
            // Queued for email, and — with the in-app option — those skipped
            // for email who still get the inbox item.
            ->where(fn ($q) => $q->where('status', 'queued')
                ->when($broadcast->inbox, fn ($w) => $w->orWhere(fn ($s) => $s->where('status', 'skipped')->where('inboxed', false))))
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->finishIfDone($broadcast);

            return;
        }

        foreach ($ids->chunk($perMinute)->values() as $i => $chunk) {
            SendBroadcastBatch::dispatch($broadcast->id, $chunk->values()->all())->delay(now()->addMinutes($i));
        }
    }

    /**
     * Send one batch. Called by SendBroadcastBatch; safe to run twice — a
     * recipient row that is no longer queued is not sent again.
     *
     * @param  list<int>  $recipientIds
     */
    public function sendBatch(int $broadcastId, array $recipientIds): void
    {
        $broadcast = Broadcast::find($broadcastId);

        if (! $broadcast || $broadcast->status !== 'sending') {
            return;
        }

        $rows = BroadcastRecipient::with('user')->whereIn('id', $recipientIds)->get();

        foreach ($rows as $row) {
            if (! $row->user) {
                continue;
            }

            if ($broadcast->inbox && ! $row->inboxed) {
                InboxItem::create([
                    'user_id' => $row->user_id,
                    'kind' => 'announcement',
                    'title' => mb_substr($broadcast->subject, 0, 255),
                    'body' => mb_substr(implode("\n\n", BroadcastMail::paragraphs($broadcast->body)), 0, 2000),
                    'target' => $broadcast->cta_url ? ['url' => $broadcast->cta_url, 'label' => $broadcast->cta_label] : null,
                    'group_key' => 'broadcast:'.$broadcast->id,
                    'occurred_at' => now(),
                ]);
                $row->inboxed = true;
            }

            // Claimed per row: a retried batch finds it already taken.
            if ($row->status === 'queued' && BroadcastRecipient::whereKey($row->id)->where('status', 'queued')->update(['status' => 'sending']) === 1) {
                try {
                    $sent = Notify::send($row->user, $this->mail($broadcast, $row->user));
                    $row->status = $sent ? 'sent' : 'skipped';
                    $row->skip_reason = $sent ? null : 'preferences';
                    $row->sent_at = $sent ? now() : null;
                } catch (Throwable $e) {
                    $row->status = 'failed';
                    $row->error = mb_substr($e->getMessage(), 0, 500);
                }
            }

            $row->save();
        }

        $this->refreshCounts($broadcast);
        $this->finishIfDone($broadcast);
    }

    /** The accounts this broadcast is for (now, before it is resolved). */
    public function audienceQuery(Broadcast $broadcast, bool $emailable = false)
    {
        $query = AudienceRules::query($broadcast->audience?->rules);

        return $emailable ? AudienceRules::allowing($query, 'news') : $query;
    }

    private function refreshCounts(Broadcast $broadcast): void
    {
        $counts = BroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        $broadcast->forceFill([
            'recipients_total' => (int) $counts->sum(),
            'sent_count' => (int) ($counts['sent'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
        ])->save();
    }

    private function finishIfDone(Broadcast $broadcast): void
    {
        $waiting = BroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->where(fn ($q) => $q->whereIn('status', ['queued', 'sending'])
                ->when($broadcast->inbox, fn ($w) => $w->orWhere('inboxed', false)))
            ->exists();

        if (! $waiting) {
            Broadcast::whereKey($broadcast->id)->where('status', 'sending')->update(['status' => 'sent', 'finished_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    private static function auditable(Broadcast $b): array
    {
        return [
            'subject' => $b->subject,
            'preheader' => $b->preheader,
            'body_length' => mb_strlen((string) $b->body),
            'cta_label' => $b->cta_label,
            'cta_url' => $b->cta_url,
            'audience_id' => $b->audience_id,
            'inbox' => (bool) $b->inbox,
        ];
    }
}
