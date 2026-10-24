import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { money as formatMoney } from '@/Components/Store/money';
import { useState } from 'react';

/**
 * The platform at a glance, from the tables that hold it.
 *
 * Every tile is a count of real rows. MRR says "no paid subscriptions yet"
 * rather than $0 while there are none, and the trend table starts the day
 * snapshots began — nothing before it is reconstructed.
 */
export default function Dashboard({ live, signups, trend, maintenance, generated_at: generatedAt }) {
    const exports = live.exports;

    return (
        <AdminLayout title="Dashboard" active="dashboard">
            {maintenance && (
                <p role="status" className="mb-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                    Maintenance mode is on. Writers see the maintenance page; switch it off in Settings.
                </p>
            )}

            <div className="grid grid-cols-2 gap-3 md:grid-cols-4" data-a="admin:tiles">
                <Tile label="Accounts" value={live.accounts_total} detail={`${live.accounts_new_7d} new in 7 days · ${live.accounts_new_30d} in 30`} />
                <Tile label="Active writers, 7 days" value={live.active_writers_7d} detail={`${live.active_writers_30d} in 30 days (saved a version)`} />
                <Tile label="Books" value={live.books} detail={`${live.words.toLocaleString()} words written`} />
                <Tile label="AI reviews run" value={live.ai_reviews} detail={`${live.ai_reviews_30d} in 30 days`} />
                <Tile
                    label="Exports"
                    value={exports.ready}
                    detail={`ready · ${exports.generating} generating · ${exports.expired} expired`}
                    alert={exports.failed > 0 ? `${exports.failed} failed` : null}
                />
                <Tile label="Credits spent" value={live.credits_spent} detail="spends less refunds, all time" />
                <MrrTile mrr={live.mrr} />
                <CommerceTile commerce={live.commerce} />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1.4fr_1fr]">
                <Panel title="Sign-ups, last 30 days" detail="New accounts per day, from when each was created.">
                    <SignupChart series={signups} />
                </Panel>

                <Panel
                    title="Daily snapshots"
                    detail="Written each night by platform:snapshot-metrics. History begins with the first snapshot; nothing earlier is estimated."
                >
                    {trend.length === 0 ? (
                        <p className="text-[13px] text-text-secondary" data-a="admin:trend-empty">
                            No snapshots yet. The first is taken tonight at 23:55, or run
                            <code className="mx-1 rounded bg-bg-subtle px-1">php artisan platform:snapshot-metrics</code>
                            now.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[420px] text-left text-[12.5px]">
                                <thead className="text-text-secondary">
                                    <tr>
                                        <th className="py-1.5 pr-3 font-medium">Day</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">Accounts</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">Writers 7d</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">Words</th>
                                        <th className="py-1.5 text-right font-medium">Failed exports</th>
                                    </tr>
                                </thead>
                                <tbody className="tabular-nums text-text-body">
                                    {[...trend].reverse().map((row) => (
                                        <tr key={row.day} className="border-t border-border-divider">
                                            <td className="py-1.5 pr-3">{formatDate(row.day, false)}</td>
                                            <td className="py-1.5 pr-3 text-right">{row.accounts_total}</td>
                                            <td className="py-1.5 pr-3 text-right">{row.active_writers_7d}</td>
                                            <td className="py-1.5 pr-3 text-right">{Number(row.words).toLocaleString()}</td>
                                            <td className="py-1.5 text-right">{row.exports_failed}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            </div>

            <p className="mt-4 text-[11.5px] text-text-muted">Figures as of {formatDate(generatedAt)}.</p>
        </AdminLayout>
    );
}

function Tile({ label, value, detail, alert = null, children }) {
    return (
        <div className="rounded-panel border border-border-default bg-bg-surface p-4">
            <p className="text-[12px] font-medium text-text-secondary">{label}</p>
            {children ?? (
                <p className="mt-1 font-display text-[26px] font-extrabold tabular-nums tracking-[-0.02em] text-text-primary">
                    {typeof value === 'number' ? value.toLocaleString() : value}
                </p>
            )}
            {detail && <p className="mt-0.5 text-[11.5px] text-text-secondary">{detail}</p>}
            {alert && (
                <p className="mt-1.5 inline-flex rounded-badge bg-status-danger-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-danger">
                    {alert}
                </p>
            )}
        </div>
    );
}

function MrrTile({ mrr }) {
    if (mrr.subscriptions === 0) {
        return (
            <Tile label="MRR" detail={mrr.configured ? 'Stripe is configured.' : 'Stripe is not configured on this deployment.'}>
                <p className="mt-2 text-[14px] font-semibold text-text-primary" data-a="admin:mrr-none">
                    No paid subscriptions yet
                </p>
            </Tile>
        );
    }

    return (
        <Tile
            label="MRR"
            value={formatMoney(mrr.amount * 100, mrr.currency)}
            detail={`${mrr.subscriptions} active subscription${mrr.subscriptions === 1 ? '' : 's'}`}
            alert={mrr.unknown_prices > 0 ? `${mrr.unknown_prices} on a price this deployment does not know` : null}
        />
    );
}

function CommerceTile({ commerce }) {
    if (!commerce) {
        return <Tile label="Storefront" value="Off" detail="Direct sales are not enabled." />;
    }

    return (
        <Tile label="Storefront orders" value={commerce.orders} detail={commerce.enabled ? 'Direct sales are on' : 'Direct sales are off now'}>
            <>
                <p className="mt-1 font-display text-[26px] font-extrabold tabular-nums text-text-primary">{commerce.orders.toLocaleString()}</p>
                {commerce.by_currency.map((row) => (
                    <p key={row.currency} className="text-[11.5px] tabular-nums text-text-secondary">
                        {formatMoney(row.gross_cents, row.currency)} gross
                        {row.refunded_cents > 0 && ` · ${formatMoney(row.refunded_cents, row.currency)} refunded`}
                    </p>
                ))}
            </>
        </Tile>
    );
}

/**
 * One series, so no legend: the panel title names it. Bars rise from a
 * shared baseline with 4px rounded tops and a 2px gap; hovering or focusing
 * a bar shows its day and count, and the same numbers are in a table for
 * screen readers.
 */
function SignupChart({ series }) {
    const [hover, setHover] = useState(null);
    const max = Math.max(1, ...series.map((point) => point.count));
    const total = series.reduce((sum, point) => sum + point.count, 0);
    const width = 600;
    const height = 160;
    const step = width / series.length;
    const bar = Math.max(2, step - 2);

    return (
        <div>
            <p className="mb-2 text-[12.5px] text-text-secondary">
                <span className="font-semibold text-text-primary">{total.toLocaleString()}</span> in 30 days · busiest day {max === 1 && total === 0 ? '—' : max}
            </p>
            <div className="relative">
                <svg viewBox={`0 0 ${width} ${height + 18}`} className="h-auto w-full" role="img" aria-label={`Sign-ups per day, ${total} in total`}>
                    <line x1="0" x2={width} y1={height} y2={height} className="stroke-border-default" strokeWidth="1" />
                    {series.map((point, index) => {
                        const h = point.count === 0 ? 0 : Math.max(4, (point.count / max) * (height - 8));
                        const x = index * step + 1;

                        return (
                            <g
                                key={point.day}
                                tabIndex={0}
                                onMouseEnter={() => setHover(index)}
                                onMouseLeave={() => setHover(null)}
                                onFocus={() => setHover(index)}
                                onBlur={() => setHover(null)}
                                className="outline-none"
                            >
                                {/* Hit target: the full column, not just the bar. */}
                                <rect x={index * step} y="0" width={step} height={height} fill="transparent" />
                                {h > 0 && (
                                    <path
                                        d={roundedTop(x, height - h, bar, h, Math.min(4, bar / 2))}
                                        className={hover === index ? 'fill-brand-primary' : 'fill-brand-fill'}
                                    />
                                )}
                            </g>
                        );
                    })}
                    <text x="0" y={height + 14} className="fill-text-muted text-[11px]">{shortDay(series[0]?.day)}</text>
                    <text x={width} y={height + 14} textAnchor="end" className="fill-text-muted text-[11px]">{shortDay(series[series.length - 1]?.day)}</text>
                </svg>
                {hover !== null && (
                    <div
                        className="pointer-events-none absolute top-0 -translate-x-1/2 rounded-control bg-bg-inverse px-2 py-1 text-[11.5px] text-text-inverse shadow-floating"
                        style={{ left: `${((hover + 0.5) / series.length) * 100}%` }}
                    >
                        {shortDay(series[hover].day)}: {series[hover].count}
                    </div>
                )}
            </div>
            <table className="sr-only">
                <caption>Sign-ups per day</caption>
                <tbody>
                    {series.map((point) => (
                        <tr key={point.day}>
                            <td>{point.day}</td>
                            <td>{point.count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function roundedTop(x, y, w, h, r) {
    return `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${y + h} Z`;
}

function shortDay(day) {
    if (!day) return '';

    return new Date(`${day}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
