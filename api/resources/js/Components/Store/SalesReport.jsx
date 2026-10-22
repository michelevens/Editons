import { money } from '@/Components/Store/money';

/**
 * Direct sales from the author's storefront: real orders only.
 *
 * Used on the account-wide Sales page and in a book's Royalties panel. The
 * payment fee is Stripe's standard pricing applied to each sale, so it is
 * labelled an estimate; everything else is what was paid and refunded.
 */
export default function SalesReport({ report, compact = false }) {
    const totals = report.totals ?? [];
    const anything = totals.some((row) => row.units > 0);

    if (!anything) {
        return (
            <p
                data-a="sales:none"
                className="rounded-tile border border-border-default bg-bg-surface p-4 text-center text-[12.5px] text-text-secondary"
            >
                No storefront sales yet. They appear here, from real orders, the moment one
                is paid.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {totals.map((row) => (
                <div
                    key={row.currency}
                    data-a={`sales:totals:${row.currency}`}
                    className={`grid gap-2 ${compact ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6'}`}
                >
                    <Stat label="Copies sold" value={row.units.toLocaleString()} />
                    <Stat label="Gross" value={money(row.gross_cents, row.currency)} />
                    <Stat
                        label="Refunded"
                        value={money(row.refunded_cents, row.currency)}
                        hint={row.refunds ? `${row.refunds} refund${row.refunds === 1 ? '' : 's'}` : null}
                    />
                    <Stat
                        label="Payment fees"
                        value={money(row.fee_cents, row.currency)}
                        hint="estimate"
                    />
                    <Stat label="Net" value={money(row.net_cents, row.currency)} />
                    <Stat
                        label="Your royalty"
                        value={money(row.royalty_cents, row.currency)}
                        strong
                    />
                </div>
            ))}

            <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                    Last 30 days ({String(report.currency).toUpperCase()})
                </h3>
                <DailyChart days={report.daily} currency={report.currency} />
            </section>

            {!compact && report.products.length > 0 && (
                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">By book</h3>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-[12px]">
                            <thead>
                                <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                    <th className="py-1.5 pr-3 font-medium">Book</th>
                                    <th className="py-1.5 pr-3 text-right font-medium">Sold</th>
                                    <th className="py-1.5 pr-3 text-right font-medium">Gross</th>
                                    <th className="py-1.5 pr-3 text-right font-medium">Refunded</th>
                                    <th className="py-1.5 pr-3 text-right font-medium">Royalty</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.products.map((row) => (
                                    <tr
                                        key={`${row.product_id}-${row.title}-${row.currency}`}
                                        className="border-b border-border-default last:border-0"
                                    >
                                        <td className="py-2 pr-3 text-text-primary">
                                            {row.url ? (
                                                <a href={row.url} className="hover:underline">
                                                    {row.title}
                                                </a>
                                            ) : (
                                                row.title
                                            )}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{row.units}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {money(row.gross_cents, row.currency)}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {money(row.refunded_cents, row.currency)}
                                        </td>
                                        <td className="py-2 pr-3 text-right font-semibold tabular-nums text-text-primary">
                                            {money(row.royalty_cents, row.currency)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                <h3 className="mb-2 text-[13px] font-semibold text-text-primary">Recent orders</h3>
                <ul className="divide-y divide-border-default">
                    {report.recent.map((order) => (
                        <li
                            key={`${order.number}-${order.title}`}
                            data-a="sales:order"
                            className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 py-2 text-[12px]"
                        >
                            <span className="min-w-0 text-text-primary">
                                {order.title}
                                <span className="ml-2 text-[11px] text-text-secondary">
                                    {order.number} · {order.date}
                                    {order.country ? ` · ${order.country}` : ''}
                                </span>
                            </span>
                            <span className="tabular-nums text-text-body">
                                {money(order.amount_cents, order.currency)}
                                {order.status !== 'paid' && (
                                    <span className="ml-2 rounded-badge bg-status-warning-bg px-1.5 py-0.5 text-[10.5px] font-semibold text-status-warning">
                                        {order.status === 'refunded' ? 'Refunded' : 'Part refunded'}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            <p className="text-[11px] leading-relaxed text-text-secondary">
                Payment fees are estimated from Stripe&rsquo;s standard card pricing; Stripe does
                not return its fee on a refund. Royalties are what is left after fees and
                refunds.
            </p>
        </div>
    );
}

function Stat({ label, value, hint = null, strong = false }) {
    return (
        <div className="rounded-tile border border-border-default bg-bg-surface px-3 py-2.5">
            <p className="text-[10.5px] uppercase tracking-wide text-text-secondary">{label}</p>
            <p
                className={`mt-0.5 tabular-nums ${
                    strong
                        ? 'text-[17px] font-bold text-text-primary'
                        : 'text-[15px] font-semibold text-text-primary'
                }`}
            >
                {value}
            </p>
            {hint && <p className="text-[10.5px] text-text-secondary">{hint}</p>}
        </div>
    );
}

function DailyChart({ days, currency }) {
    const peak = Math.max(1, ...days.map((day) => day.gross_cents));

    return (
        <>
            <div
                className="flex h-[110px] items-end gap-[3px]"
                role="img"
                aria-label={`Sales per day over 30 days, highest ${money(peak, currency)}`}
            >
                {days.map((day) => (
                    <div key={day.date} className="relative flex flex-1 flex-col justify-end">
                        <div
                            title={`${day.label}: ${day.units} sold, ${money(day.gross_cents, currency)}${
                                day.refunded_cents ? `, ${money(day.refunded_cents, currency)} refunded` : ''
                            }`}
                            className="w-full rounded-t bg-brand-primary/60"
                            style={{
                                height: day.gross_cents
                                    ? `${Math.max(3, (day.gross_cents / peak) * 100)}px`
                                    : '2px',
                            }}
                        />
                        {day.refunded_cents > 0 && (
                            <div
                                className="mt-px h-[3px] w-full rounded-b bg-status-danger"
                                aria-hidden="true"
                            />
                        )}
                    </div>
                ))}
            </div>
            <div className="mt-1.5 flex justify-between text-[10.5px] text-text-secondary">
                <span>{days[0]?.label}</span>
                <span>Today</span>
            </div>
        </>
    );
}
