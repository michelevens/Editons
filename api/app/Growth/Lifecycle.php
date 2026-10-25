<?php

namespace App\Growth;

use App\Mail\Growth\LifecycleMail;
use App\Mail\Notify;
use App\Models\LifecycleCampaign;
use App\Models\LifecycleSend;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lifecycle emails: a rule, a delay, a message, on or off.
 *
 *   no_book   signed up N days ago and has not started a book
 *   inactive  was active, and has not signed in or saved for N days
 *
 * `growth:lifecycle` runs hourly. Each campaign sends to an account at most
 * once, ever: the lifecycle_sends row (unique per campaign and account) is
 * written before the email, so a crash between the two can lose an email
 * but never send one twice. Only within `growth.lifecycle.window_days` of
 * the trigger — switching a campaign on emails people it has just become
 * true for, not every dormant account in the table — and only to people
 * whose preferences allow "Getting-started tips". Staff and suspended
 * accounts are left out.
 *
 * Off by default (the migration seeds both switched off).
 */
final class Lifecycle
{
    public const TRIGGERS = [
        'no_book' => 'Days after signing up, with no book',
        'inactive' => 'Days without signing in or writing',
    ];

    /** @return array{sent: int, skipped: int, failed: int} */
    public function run(): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (LifecycleCampaign::where('enabled', true)->get() as $campaign) {
            foreach ($this->eligible($campaign)->limit(1000)->get() as $user) {
                $result = $this->sendOnce($campaign, $user);

                if ($result !== null) {
                    $totals[$result]++;
                }
            }
        }

        return $totals;
    }

    /** Accounts this campaign would email now. */
    public function eligible(LifecycleCampaign $campaign): Builder
    {
        $days = $campaign->delay_days;
        $window = max(1, (int) config('growth.lifecycle.window_days'));
        $due = now()->subDays($days);
        $oldest = now()->subDays($days + $window);

        $query = User::query()
            ->whereNull('suspended_at')
            ->whereNull('platform_role')
            ->exceptHouse()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('lifecycle_sends')
                ->whereColumn('lifecycle_sends.user_id', 'users.id')
                ->where('lifecycle_sends.lifecycle_campaign_id', $campaign->id));

        AudienceRules::allowing($query, LifecycleMail::CATEGORY);

        if ($campaign->trigger === 'no_book') {
            return $query
                ->whereBetween('created_at', [$oldest, $due])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('books')->whereColumn('books.user_id', 'users.id'));
        }

        // inactive: the latest sign-in or save fell between $oldest and $due.
        $last = 'greatest('
            .'(select max(created_at) from user_logins where user_logins.user_id = users.id and user_logins.impersonated = false), '
            .'(select max(created_at) from manuscript_versions where manuscript_versions.user_id = users.id and manuscript_versions.impersonator_id is null))';

        return $query
            ->where('created_at', '<', $due)
            ->whereRaw("{$last} between ? and ?", [$oldest, $due]);
    }

    /** 'sent' | 'skipped' | 'failed', or null when it had already been sent. */
    public function sendOnce(LifecycleCampaign $campaign, User $user): ?string
    {
        // ON CONFLICT DO NOTHING: the unique row is the claim.
        $claimed = DB::table('lifecycle_sends')->insertOrIgnore([
            'lifecycle_campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'sending',
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return null;
        }

        $send = LifecycleSend::where('lifecycle_campaign_id', $campaign->id)->where('user_id', $user->id)->first();

        try {
            $sent = Notify::send($user, $this->mail($campaign, $user));
            $status = $sent ? 'sent' : 'skipped';
        } catch (Throwable $e) {
            report($e);
            $status = 'failed';
        }

        $send->forceFill(['status' => $status])->save();

        return $status;
    }

    public function mail(LifecycleCampaign $campaign, User $user, bool $test = false): LifecycleMail
    {
        return new LifecycleMail($user, $campaign->subject, $campaign->body, $campaign->cta_label, $test);
    }

    /** @param  array{name: string, delay_days: int, subject: string, body: string, cta_label: ?string, enabled: bool}  $data */
    public function update(LifecycleCampaign $campaign, array $data, string $reason, User $actor): LifecycleCampaign
    {
        return AdminAudit::transaction(function () use ($campaign, $data, $reason, $actor) {
            $before = $campaign->only(['name', 'delay_days', 'subject', 'body', 'cta_label', 'enabled']);
            $campaign->fill($data + ['updated_by' => $actor->id])->save();

            [$from, $to] = AdminAudit::diff($before, $campaign->only(['name', 'delay_days', 'subject', 'body', 'cta_label', 'enabled']));
            AdminAudit::record('lifecycle.updated', $campaign, $from, $to, $reason, $actor);

            return $campaign;
        });
    }
}
