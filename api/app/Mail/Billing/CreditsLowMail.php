<?php

namespace App\Mail\Billing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The AI credit balance dropped below the warning line.
 *
 * Sent at most once per calendar month (users.credits_low_notified_at), by
 * App\Observers\CreditTransactionObserver the moment a spend crosses the
 * same 15% line the account menu turns amber at.
 */
class CreditsLowMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    /** Below this share of the monthly allowance, the balance is "low". */
    public const THRESHOLD = 0.15;

    public function __construct(
        public User $user,
        public int $balance,
        public int $monthlyAllowance,
        public ?CarbonInterface $refreshesAt = null,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $costs = config('billing.costs', []);
        $refreshes = ($this->refreshesAt ?? now()->addMonthNoOverflow()->startOfMonth())->translatedFormat('F j');

        $menu = array_filter([
            'chapter_review' => __('Chapter review'),
            'agent_run' => __('One editorial agent'),
            'marketing_kit' => __('Marketing kit'),
            'cover_artwork' => __('Painted cover artwork'),
        ], fn ($label, $key) => isset($costs[$key]), ARRAY_FILTER_USE_BOTH);

        return Letter::make(trans_choice('{0} You are out of AI credits|{1} You have :count AI credit left|[2,*] You have :count AI credits left', $this->balance, ['count' => number_format($this->balance)]))
            ->preheader(__('Your allowance refreshes on :date. Top up if you need more before then.', ['date' => $refreshes]))
            ->subtitle(__('AI credits running low'))
            ->eyebrow(__('Heads-up · Credits'))
            ->severity('heads')
            ->headline(__('Your AI credits are running low'))
            ->lede(self::greet($this->user->name, __('you have used most of this month\'s AI credits. Writing, editing and exporting never use credits — only AI reviews and generation do.')))
            ->stats([
                ['label' => __('Credits left'), 'value' => number_format($this->balance), 'tone' => $this->balance === 0 ? 'danger' : 'warning'],
                ['label' => __('Monthly allowance'), 'value' => number_format($this->monthlyAllowance)],
                ['label' => __('Refreshes'), 'value' => $refreshes],
            ])
            ->table(
                [__('What credits buy'), __('Credits')],
                array_values(array_map(fn ($label, $key) => [$label, (string) $costs[$key]], $menu, array_keys($menu))),
            )
            ->cta(__('Top up or upgrade'), route('billing.show'))
            ->reason(__('Billing emails are always sent. We send this one at most once a month.'));
    }
}
