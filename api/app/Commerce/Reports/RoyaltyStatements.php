<?php

namespace App\Commerce\Reports;

use App\Models\Payout;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Royalty statements: one payee, one month or quarter, one currency, as a
 * PDF to download.
 *
 * The figures are the sales report's (SalesExplorer over the period, in the
 * payee's timezone): units, net and royalty per book add up to the
 * statement's totals, and those equal the report for the same range.
 *
 *   royalty due   royalties earned on the period's sales (after refunds,
 *                 fees and partners' commissions)
 *   earned        royalties earned on every sale up to the period's end
 *   already paid  royalty payouts recorded with a date up to the period's end
 *   balance       earned − already paid: what was still owed when the
 *                 period closed
 *
 * A house account (ADR-0035) is the platform's own imprint: its royalty
 * share is platform revenue, never owed or paid, so its statement shows the
 * share and says so, with nothing paid and no balance.
 *
 * Periods are "2026-09" (a month) or "2026-Q3" (a quarter).
 */
final class RoyaltyStatements
{
    /**
     * The local days of a period.
     *
     * @throws ValidationException
     */
    public static function range(string $period, string $tz): ReportRange
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m)) {
            $from = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $tz);

            return ReportRange::between($from, $from->endOfMonth(), $tz);
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) {
            $from = CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1, 0, 0, 0, $tz);

            return ReportRange::between($from, $from->addMonths(2)->endOfMonth(), $tz);
        }

        throw ValidationException::withMessages(['period' => 'A period looks like 2026-09 or 2026-Q3.']);
    }

    /** "September 2026", "Q3 2026". */
    public static function label(string $period): string
    {
        return str_contains($period, 'Q')
            ? substr($period, 5).' '.substr($period, 0, 4)
            : CarbonImmutable::createFromFormat('!Y-m', $period)->format('F Y');
    }

    /**
     * The months and quarters a payee sold in, newest first, with the
     * currencies of each: what the statements list offers.
     *
     * @param  list<int>  $sellerIds
     * @return list<array{period: string, label: string, kind: string, currencies: list<string>}>
     */
    public static function periods(array $sellerIds, string $tz, int $limit = 24): array
    {
        $explorer = new SalesExplorer($sellerIds);
        $months = [];

        // Every paid date, in the payee's zone: cheap columns only.
        foreach ($explorer->query(null)->select(['orders.paid_at', 'orders.currency'])->orderBy('orders.paid_at')->cursor() as $row) {
            $local = CarbonImmutable::parse($row->paid_at, 'UTC')->setTimezone($tz);
            $months[$local->format('Y-m')][$row->currency] = true;
        }

        $out = [];
        $quarters = [];

        foreach ($months as $month => $currencies) {
            $out[] = ['period' => $month, 'label' => self::label($month), 'kind' => 'month', 'currencies' => array_keys($currencies)];
            $q = substr($month, 0, 4).'-Q'.(intdiv((int) substr($month, 5, 2) - 1, 3) + 1);
            $quarters[$q] = array_merge($quarters[$q] ?? [], $currencies);
        }

        foreach ($quarters as $q => $currencies) {
            $out[] = ['period' => $q, 'label' => self::label($q), 'kind' => 'quarter', 'currencies' => array_keys($currencies)];
        }

        // Newest first; a quarter just after the month that closes it.
        $end = fn (array $p) => self::range($p['period'], $tz)->to->format('Y-m-d').($p['kind'] === 'month' ? '1' : '0');
        usort($out, fn ($a, $b) => $end($b) <=> $end($a));

        return array_slice($out, 0, $limit * 2);
    }

    /**
     * A statement's figures.
     *
     * @return array<string, mixed>
     */
    public function build(User $payee, string $period, string $currency): array
    {
        $tz = $payee->reportTimezone();
        $range = self::range($period, $tz);
        $explorer = new SalesExplorer([$payee->id], ['currency' => $currency], $payee->isHouse());
        $now = $explorer->aggregate($range);
        $totals = collect($now['totals'])->firstWhere('currency', $currency) ?? ['currency' => $currency] + Tally::zero();

        // Everything earned up to the period's end, and what was paid by then.
        $first = $explorer->firstSale();
        $toDate = $first && $first->lessThan($range->endUtc())
            ? collect($explorer->aggregate(ReportRange::between($first, $range->to, $tz))['totals'])->firstWhere('currency', $currency)
            : null;
        $earned = (int) ($toDate['royalty_cents'] ?? 0);
        $paid = $payee->isHouse() ? 0 : (int) Payout::where('kind', 'royalty')
            ->where('user_id', $payee->id)
            ->where('currency', $currency)
            ->whereDate('paid_on', '<=', $range->to->toDateString())
            ->sum('amount_cents');

        $rate = round((1 - (float) config('commerce.platform_rate')) * 100, 2);

        return [
            'payee' => [
                'id' => $payee->id,
                'name' => $payee->isHouse() ? $payee->name : ($payee->author_name ?: $payee->name),
                'house' => $payee->isHouse(),
            ],
            'period' => $period,
            'period_label' => self::label($period),
            'range' => $range->toArray(),
            'currency' => $currency,
            'books' => collect($now['breakdowns']['book'])->map(fn (array $row) => [
                'title' => $row['title'],
                'units' => $row['units'],
                'gross_cents' => $row['gross_cents'],
                'refunded_cents' => $row['refunded_cents'],
                'fee_cents' => $row['fee_cents'],
                'net_cents' => $row['net_cents'],
                'partner_cents' => $row['partner_cents'],
                'royalty_cents' => $row['royalty_cents'],
                // What the royalty is of the net, after partners' commissions.
                'effective_rate' => $row['net_cents'] > 0 ? round($row['royalty_cents'] / $row['net_cents'] * 100, 1) : null,
            ])->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'totals' => $totals,
            'royalty_rate' => $rate,
            'royalty_due_cents' => (int) $totals['royalty_cents'],
            'earned_to_date_cents' => $earned,
            'paid_cents' => $paid,
            'balance_cents' => $payee->isHouse() ? 0 : $earned - $paid,
            'generated_at' => now()->setTimezone($tz)->format('j M Y, H:i T'),
        ];
    }

    /** The statement as a PDF (dompdf, no remote fetching). */
    public function pdf(array $statement): string
    {
        $temp = storage_path('app/tmp/statement-'.Str::random(16));
        @mkdir($temp, 0755, true);

        try {
            $dompdf = new Dompdf(new Options([
                'isRemoteEnabled' => false,
                'tempDir' => $temp,
                'isHtml5ParserEnabled' => true,
                // DejaVu ships with dompdf and has é, è, ò.
                'defaultFont' => 'DejaVu Sans',
                'chroot' => [resource_path('views')],
            ]));
            $dompdf->loadHtml(view('pdf.royalty-statement', ['s' => $statement])->render(), 'UTF-8');
            $dompdf->setPaper('letter');
            $dompdf->render();

            return (string) $dompdf->output();
        } finally {
            foreach (glob($temp.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($temp);
        }
    }

    public static function filename(array $statement): string
    {
        return 'royalty-statement-'.Str::slug($statement['payee']['name']).'-'.$statement['period'].'-'.$statement['currency'].'.pdf';
    }
}
