<?php

namespace App\Mail\Commerce;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A month of sales and royalties, by book and store.
 *
 * Billing, not "sales": a statement is a financial record the author may
 * need for tax, so it is always sent.
 */
class RoyaltyStatementMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    /**
     * @param  list<array{title: string, channel: string, units: int, royalty_cents: int}>  $lines
     */
    public function __construct(
        public User $author,
        public CarbonInterface $month,
        public array $lines,
        public string $currency,
        public ?CarbonInterface $payoutDate = null,
        public ?string $statementUrl = null,
        public ?int $previousTotalCents = null,
    ) {}

    public function account(): ?User
    {
        return $this->author;
    }

    public function letter(): Letter
    {
        $month = $this->month->translatedFormat(__('F Y'));
        $units = array_sum(array_column($this->lines, 'units'));
        $totalCents = array_sum(array_column($this->lines, 'royalty_cents'));
        $total = Money::format($totalCents, $this->currency);

        $change = null;
        if ($this->previousTotalCents !== null && $this->previousTotalCents > 0) {
            $pct = (int) round(($totalCents - $this->previousTotalCents) / $this->previousTotalCents * 100);
            $change = ($pct >= 0 ? '+' : '−').abs($pct).'%';
        }

        $letter = Letter::make(__('Your :month royalty statement', ['month' => $month]))
            ->preheader(trans_choice('{0} No sales in :month.|{1} :count sale, :total in royalties for :month.|[2,*] :count sales, :total in royalties for :month.', $units, [
                'count' => number_format($units), 'total' => $total, 'month' => $month,
            ]))
            ->subtitle(__('Royalty statement'))
            ->eyebrow($month)
            ->severity('info')
            ->headline($units > 0
                ? __(':total earned in :month', ['total' => $total, 'month' => $month])
                : __('No sales in :month', ['month' => $month]))
            ->lede(self::greet($this->author->name, __('here is your statement for :month: every sale, by book and store.', ['month' => $month])))
            ->stats(array_values(array_filter([
                ['label' => __('Copies sold'), 'value' => number_format($units)],
                ['label' => __('Royalties'), 'value' => $total, 'tone' => $totalCents > 0 ? 'success' : 'default'],
                $change !== null ? ['label' => __('vs last month'), 'value' => $change] : null,
            ])));

        if ($this->lines !== []) {
            $letter->table(
                [__('Book'), __('Store'), __('Copies'), __('Royalty')],
                array_map(fn (array $line) => [
                    $line['title'],
                    $line['channel'],
                    number_format($line['units']),
                    Money::format((int) $line['royalty_cents'], $this->currency),
                ], $this->lines),
                [__('Total'), '', number_format($units), $total],
                __('By book and store'),
            );
        }

        return $letter
            ->facts([
                __('Period') => $this->month->copy()->startOfMonth()->translatedFormat(__('M j')).' – '.$this->month->copy()->endOfMonth()->translatedFormat(__('M j, Y')),
                __('Payout date') => $this->payoutDate?->translatedFormat(__('F j, Y')),
            ])
            ->cta(__('View the full statement'), $this->statementUrl ?? route('books.index'))
            ->reason(__('Statements are always sent: they are your record for tax and accounts.'));
    }
}
