<?php

namespace App\Commerce\Partners;

use App\Inbox\Inbox;
use App\Mail\Notify;
use App\Mail\Partners\PartnerApplicationReceivedMail;
use App\Mail\Partners\PartnerApprovedMail;
use App\Mail\Partners\PartnerDeclinedMail;
use App\Mail\Partners\PartnerFirstSaleMail;
use App\Mail\Partners\PartnerPayoutMail;
use App\Mail\Partners\PartnerSuspendedMail;
use App\Mail\Support\Money;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\Payout;

/**
 * Who hears what about the partner program: an email through Notify (which
 * honours preferences) and an inbox item, the way the rest of Écrivain
 * tells people things. Called after the change is committed, so nobody is
 * told about something that was rolled back.
 */
final class PartnerNotices
{
    public static function applied(Partner $partner): void
    {
        $user = $partner->user;

        Notify::send($user, new PartnerApplicationReceivedMail($user, $partner));
        Inbox::record($user, 'partner', 'Your partner application is in the queue', target: ['url' => route('partners.dashboard')]);
    }

    public static function approved(Partner $partner): void
    {
        $user = $partner->user;

        Notify::send($user, new PartnerApprovedMail($user, $partner));
        Inbox::record($user, 'partner', 'You are an Écrivain Partner: your page is live', target: ['url' => route('partners.dashboard', ['tab' => 'page'])]);
    }

    public static function declined(Partner $partner, string $reason): void
    {
        $user = $partner->user;

        Notify::send($user, new PartnerDeclinedMail($user, $partner, $reason));
        Inbox::record($user, 'partner', 'Your partner application was not approved', body: $reason, target: ['url' => route('partners.apply')]);
    }

    public static function suspended(Partner $partner, string $reason): void
    {
        $user = $partner->user;

        Notify::send($user, new PartnerSuspendedMail($user, $partner, $reason));
        Inbox::record($user, 'partner', 'Your partner account is suspended', body: $reason, target: ['url' => route('partners.dashboard')]);
    }

    public static function payoutRecorded(Partner $partner, Payout $payout): void
    {
        $user = $partner->user;
        $balance = app(Ledger::class)->balance($partner, $payout->currency);

        Notify::send($user, new PartnerPayoutMail($user, $partner, $payout, $balance));
        Inbox::record($user, 'partner', 'Partner payout of '.Money::format($payout->amount_cents, $payout->currency).' recorded', target: ['url' => route('partners.dashboard', ['tab' => 'payouts'])]);
    }

    /**
     * Commissions were just earned: an inbox line each, and the first-sale
     * email the first time a partner earns anything.
     *
     * @param  list<PartnerCommission>  $rows
     */
    public static function earned(array $rows): void
    {
        foreach ($rows as $row) {
            $partner = Partner::with('user')->find($row->partner_id);

            if (! $partner?->user) {
                continue;
            }

            Inbox::record(
                $partner->user,
                'partner',
                'You earned '.Money::format($row->amount_cents, $row->currency).' on “'.$row->title.'”',
                target: ['url' => route('partners.dashboard', ['tab' => 'commissions'])],
            );

            $first = ! PartnerCommission::where('partner_id', $partner->id)->where('kind', 'sale')->where('id', '<', $row->id)->exists();

            if ($first) {
                Notify::send($partner->user, new PartnerFirstSaleMail($partner->user, $partner, (string) $row->title, $row->amount_cents, $row->currency, $row->hold_until ?? now()));
            }
        }
    }
}
