@php
    $m = fn (int $cents) => ($cents < 0 ? '−' : '').number_format(abs($cents) / 100, 2).' '.strtoupper($s['currency']);
    $house = $s['payee']['house'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Royalty statement — {{ $s['period_label'] }}</title>
<style>
    @page { margin: 54pt 54pt 60pt; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2328; }
    h1 { font-size: 17pt; margin: 0 0 2pt; }
    .muted { color: #5b6470; }
    .head { width: 100%; margin: 14pt 0 16pt; border-collapse: collapse; }
    .head td { vertical-align: top; padding: 0 12pt 0 0; }
    .head .k { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .5pt; color: #5b6470; }
    .head .v { font-size: 11pt; font-weight: bold; }
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #5b6470; border-bottom: 1pt solid #c9ced6; padding: 4pt 4pt; }
    table.lines td { border-bottom: .5pt solid #e3e6ea; padding: 5pt 4pt; }
    table.lines .n { text-align: right; white-space: nowrap; }
    table.lines tfoot td { font-weight: bold; border-top: 1pt solid #1f2328; border-bottom: 0; }
    .summary { width: 52%; margin: 18pt 0 0 auto; border-collapse: collapse; }
    .summary td { padding: 4pt; }
    .summary .n { text-align: right; }
    .summary .total td { border-top: 1pt solid #1f2328; font-weight: bold; font-size: 11pt; }
    .note { margin-top: 22pt; font-size: 8pt; color: #5b6470; line-height: 1.45; }
</style>
</head>
<body>
    <h1>Royalty statement</h1>
    <div class="muted">Écrivain storefront · {{ $s['period_label'] }} ({{ $s['range']['span'] }}, {{ $s['range']['tz'] }})</div>

    <table class="head">
        <tr>
            <td><div class="k">Payee</div><div class="v">{{ $s['payee']['name'] }}</div>@if ($house)<div class="muted">House imprint</div>@endif</td>
            <td><div class="k">Period</div><div class="v">{{ $s['period_label'] }}</div></td>
            <td><div class="k">Books</div><div class="v">{{ count($s['books']) }}</div></td>
            <td><div class="k">Copies</div><div class="v">{{ $s['totals']['units'] }}</div></td>
            <td><div class="k">Currency</div><div class="v">{{ strtoupper($s['currency']) }}</div></td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Book</th>
                <th class="n">Units</th>
                <th class="n">Gross</th>
                <th class="n">Refunds</th>
                <th class="n">Fees (est.)</th>
                <th class="n">Net</th>
                <th class="n">Partners</th>
                <th class="n">Rate</th>
                <th class="n">{{ $house ? 'House share' : 'Royalty' }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($s['books'] as $book)
                <tr>
                    <td>{{ $book['title'] }}</td>
                    <td class="n">{{ $book['units'] }}</td>
                    <td class="n">{{ $m($book['gross_cents']) }}</td>
                    <td class="n">{{ $m($book['refunded_cents']) }}</td>
                    <td class="n">{{ $m($book['fee_cents']) }}</td>
                    <td class="n">{{ $m($book['net_cents']) }}</td>
                    <td class="n">{{ $book['partner_cents'] ? $m(-$book['partner_cents']) : '—' }}</td>
                    <td class="n">{{ $book['effective_rate'] !== null ? rtrim(rtrim(number_format($book['effective_rate'], 1), '0'), '.').' %' : '—' }}</td>
                    <td class="n">{{ $m($book['royalty_cents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No sales in this period.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="n">{{ $s['totals']['units'] }}</td>
                <td class="n">{{ $m($s['totals']['gross_cents']) }}</td>
                <td class="n">{{ $m($s['totals']['refunded_cents']) }}</td>
                <td class="n">{{ $m($s['totals']['fee_cents']) }}</td>
                <td class="n">{{ $m($s['totals']['net_cents']) }}</td>
                <td class="n">{{ $s['totals']['partner_cents'] ? $m(-$s['totals']['partner_cents']) : '—' }}</td>
                <td class="n"></td>
                <td class="n">{{ $m($s['royalty_due_cents']) }}</td>
            </tr>
        </tfoot>
    </table>

    <table class="summary">
        <tr><td>{{ $house ? 'House share this period' : 'Royalty due this period' }}</td><td class="n">{{ $m($s['royalty_due_cents']) }}</td></tr>
        @if ($house)
            <tr class="total"><td>Owed to anybody</td><td class="n">None — platform revenue</td></tr>
        @else
            <tr><td>Royalties earned to {{ $s['range']['to'] }}</td><td class="n">{{ $m($s['earned_to_date_cents']) }}</td></tr>
            <tr><td>Already paid</td><td class="n">{{ $m(-$s['paid_cents']) }}</td></tr>
            <tr class="total"><td>Balance</td><td class="n">{{ $m($s['balance_cents']) }}</td></tr>
        @endif
    </table>

    <p class="note">
        Royalty rate: {{ rtrim(rtrim(number_format($s['royalty_rate'], 2), '0'), '.') }} % of what is left after the payment fee;
        the per-book rate is what the royalty is of the net once refunds and any partner&rsquo;s commission are taken out.
        Payment fees are estimated from Stripe&rsquo;s standard card pricing. Sales count in the month they were paid, in
        {{ $s['range']['tz'] }}; a refund reduces the royalty of the sale it refunds.
        @if ($house)
            This is a house imprint: its royalty share is the platform&rsquo;s revenue, and nothing is paid out.
        @else
            Payouts are made outside Écrivain and recorded by hand; "already paid" counts those dated up to the period&rsquo;s end.
        @endif
        Generated {{ $s['generated_at'] }}.
    </p>
</body>
</html>
