<?php

namespace App\Commerce\Partners;

use App\Commerce\Reports\ReportRange;
use App\Commerce\Reports\SalesExplorer;
use App\Models\Partner;
use App\Models\PartnerCommission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A partner's own commissions over a date range, and as CSV (ADR-0046 —
 * Sales reports and royalty statements; the audit's PP2).
 *
 * Only ever the partner's own rows — every query starts from partner_id,
 * and the controller passes the signed-in account's partner. A commission
 * counts on the day it was written (the sale's payment), in the partner's
 * report timezone, like the author's report. What a row is worth is its
 * net (amount − reversed), the figure the ledger and balances use; buyers
 * are never named, at most "a reader in {country}".
 */
final class Earnings
{
    public function __construct(private readonly Partner $partner) {}

    public function query(ReportRange $range): Builder
    {
        return PartnerCommission::query()
            ->where('partner_id', $this->partner->id)
            ->where('created_at', '>=', $range->startUtc())
            ->where('created_at', '<', $range->endUtc());
    }

    /**
     * Per currency: sales, net, and the net by status.
     *
     * @return list<array{currency: string, sales: int, net_cents: int, pending_cents: int, approved_cents: int, paid_cents: int, reversed_cents: int}>
     */
    public function totals(ReportRange $range): array
    {
        $out = [];

        foreach ($this->query($range)->get(['currency', 'kind', 'status', 'amount_cents', 'reversed_cents']) as $row) {
            $t = &$out[$row->currency];
            $t ??= ['currency' => $row->currency, 'sales' => 0, 'net_cents' => 0, 'pending_cents' => 0, 'approved_cents' => 0, 'paid_cents' => 0, 'reversed_cents' => 0];
            $net = $row->net();
            $t['sales'] += $row->kind === 'sale' ? 1 : 0;
            $t['net_cents'] += $net;
            $t['reversed_cents'] += (int) $row->reversed_cents;

            if (isset($t[$row->status.'_cents']) && $row->status !== 'reversed') {
                $t[$row->status.'_cents'] += $net;
            }

            unset($t);
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> the dashboard's rows, newest first */
    public function rows(ReportRange $range, int $limit = 200): array
    {
        return $this->query($range)->latest('id')->limit($limit)->get()
            ->map(fn (PartnerCommission $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'title' => $row->title ?? ($row->kind === 'forfeit' ? 'Balance forfeited' : '—'),
                'date' => CarbonImmutable::instance($row->created_at)->setTimezone($range->tz)->toDateString(),
                'amount_cents' => $row->amount_cents,
                'reversed_cents' => $row->reversed_cents,
                'net_cents' => $row->net(),
                'currency' => $row->currency,
                'status' => $row->status,
                'rate' => $row->rate_bps / 100,
                'hold_until' => $row->hold_until?->toDateString(),
                'via' => $row->source,
                'reader' => Insights::reader($row->buyer_country),
                'note' => $row->kind === 'sale' ? null : $row->note,
            ])
            ->all();
    }

    /**
     * CSV rows, header first, streamed in id order.
     *
     * @return \Generator<int, list<mixed>>
     */
    public function csv(ReportRange $range): \Generator
    {
        yield ['id', 'date', 'kind', 'book', 'currency', 'rate_percent', 'base', 'amount', 'reversed', 'net', 'status', 'via', 'hold_until', 'paid_at', 'reader'];

        foreach ($this->query($range)->lazyById(500) as $row) {
            yield [
                $row->id,
                CarbonImmutable::instance($row->created_at)->setTimezone($range->tz)->toDateString(),
                $row->kind,
                $row->title,
                strtoupper($row->currency),
                $row->rate_bps / 100,
                SalesExplorer::decimal((int) $row->base_cents),
                SalesExplorer::decimal((int) $row->amount_cents),
                SalesExplorer::decimal((int) $row->reversed_cents),
                SalesExplorer::decimal($row->net()),
                $row->status,
                $row->source,
                $row->hold_until?->toDateString(),
                $row->paid_at ? CarbonImmutable::instance($row->paid_at)->setTimezone($range->tz)->toDateString() : null,
                Insights::reader($row->buyer_country),
            ];
        }
    }
}
