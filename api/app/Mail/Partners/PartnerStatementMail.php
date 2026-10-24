<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A partner's month: commissions earned, adjustments, and the balance. Sent only for a month with activity. */
class PartnerStatementMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'partners';

    /**
     * @param  list<array{title: string, units: int, amount_cents: int}>  $lines
     */
    public function __construct(
        public User $user,
        public Partner $partner,
        public CarbonInterface $month,
        public array $lines,
        public string $currency,
        public int $clicks,
        public int $balanceCents,
        public int $pendingCents,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $month = $this->month->translatedFormat('F Y');
        $total = array_sum(array_column($this->lines, 'amount_cents'));
        $units = array_sum(array_column($this->lines, 'units'));

        $letter = Letter::make(__('Your :month partner statement', ['month' => $month]))
            ->preheader(__(':total in commissions for :month.', ['total' => Money::format($total, $this->currency), 'month' => $month]))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow($month)
            ->severity('info')
            ->headline(__(':total earned in :month', ['total' => Money::format($total, $this->currency), 'month' => $month]))
            ->lede(self::greet($this->user->name, __('here is what your partner page and links earned in :month.', ['month' => $month])))
            ->stats([
                ['label' => __('Visits'), 'value' => number_format($this->clicks)],
                ['label' => __('Sales'), 'value' => number_format($units)],
                ['label' => __('Balance'), 'value' => Money::format($this->balanceCents, $this->currency), 'tone' => $this->balanceCents > 0 ? 'success' : 'default'],
            ]);

        if ($this->lines !== []) {
            $letter->table(
                [__('Book'), __('Sales'), __('Commission')],
                array_map(fn (array $line) => [$line['title'], number_format($line['units']), Money::format($line['amount_cents'], $this->currency)], $this->lines),
                [__('Total'), number_format($units), Money::format($total, $this->currency)],
            );
        }

        return $letter
            ->facts([__('Still in the hold') => Money::format($this->pendingCents, $this->currency)])
            ->cta(__('Open your partner dashboard'), route('partners.dashboard'))
            ->reason(__('You received this because you are an :brand Partner and your account had activity this month. You can switch partner emails off in your preferences.', ['brand' => $brand]));
    }
}
