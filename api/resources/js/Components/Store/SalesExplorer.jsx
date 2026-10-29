import { money } from '@/Components/Store/money';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * The Sales page's report: a date range (presets or from–to, in the
 * account's timezone), an optional comparison, filters, totals with their
 * change, the chart, breakdowns, CSV downloads and royalty statements.
 *
 * Everything the report shows comes from the server for the range in the
 * URL, so a view can be bookmarked or sent to someone; the controls change
 * the URL and the server answers. Money is never added across currencies:
 * every total, row and chart is per currency (ADR-00XX — Sales reports and
 * royalty statements).
 */

const control =
    'h-[34px] min-w-0 max-w-full rounded-control border border-border-input bg-bg-surface px-2.5 text-[12.5px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20';
const linkButton =
    'tap inline-flex min-h-[34px] items-center rounded-control border border-border-input bg-bg-surface px-3 text-[12.5px] font-semibold text-text-body hover:border-brand-primary/40';

const COLUMNS = [
    ['units', 'Units', false],
    ['gross_cents', 'Gross', true],
    ['discount_cents', 'Discounts', true],
    ['refunded_cents', 'Refunds', true],
    ['fee_cents', 'Fees', true],
    ['net_cents', 'Net', true],
    ['royalty_cents', 'Royalties', true],
];

const DIMENSIONS = [
    ['book', 'By book'],
    ['format', 'By format'],
    ['channel', 'By channel'],
    ['period', 'By period'],
];

const BUCKET = { day: 'day', week: 'week', month: 'month' };

/** The query a view is: empty values left out of the URL. */
function toParams(query) {
    const params = {};

    if (query.scope) params.scope = query.scope;
    if (query.range && query.range !== 'last_30') params.range = query.range;
    if (query.range === 'custom') {
        params.from = query.from;
        params.to = query.to;
    }
    if (query.compare) params.compare = query.compare;
    if (query.books?.length) params.book = query.books[0];
    if (query.format) params.format = query.format;
    if (query.channel) params.channel = query.channel;
    if (query.currency) params.currency = query.currency;

    return params;
}

function qs(params) {
    const search = new URLSearchParams(params).toString();

    return search ? `?${search}` : '';
}

export default function SalesExplorer({ report, query, options, statements, timezone, scope }) {
    const [state, setState] = useState(query);
    const [custom, setCustom] = useState({ from: query.from, to: query.to });
    const [dimension, setDimension] = useState('book');

    useEffect(() => {
        setState(query);
        setCustom({ from: query.from, to: query.to });
    }, [query]);

    const go = (patch) => {
        const next = { ...state, ...patch };
        setState(next);
        router.get(route('sales.overview'), toParams(next), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const params = toParams(state);
    const house = scope === 'house';
    const anything = report.totals.some((row) => row.units > 0) || (report.compare?.totals ?? []).some((row) => row.units > 0);

    return (
        <div className="space-y-4">
            <section aria-label="Report range and filters" className="rounded-tile border border-border-default bg-bg-surface p-3">
                <div className="flex flex-wrap items-end gap-2">
                    <Field label="Range">
                        <select
                            data-a="sales:range"
                            value={state.range}
                            onChange={(e) =>
                                e.target.value === 'custom' ? setState({ ...state, range: 'custom' }) : go({ range: e.target.value })
                            }
                            className={control}
                        >
                            {options.presets.map((p) => (
                                <option key={p.key} value={p.key}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    {state.range === 'custom' && (
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                go({
                                    range: 'custom',
                                    from: custom.from,
                                    to: custom.to,
                                });
                            }}
                        >
                            <Field label="From">
                                <input
                                    type="date"
                                    data-a="sales:from"
                                    required
                                    value={custom.from}
                                    max={custom.to}
                                    onChange={(e) =>
                                        setCustom({
                                            ...custom,
                                            from: e.target.value,
                                        })
                                    }
                                    className={control}
                                />
                            </Field>
                            <Field label="To">
                                <input
                                    type="date"
                                    data-a="sales:to"
                                    required
                                    value={custom.to}
                                    min={custom.from}
                                    onChange={(e) =>
                                        setCustom({
                                            ...custom,
                                            to: e.target.value,
                                        })
                                    }
                                    className={control}
                                />
                            </Field>
                            <button
                                type="submit"
                                data-a="sales:apply"
                                className="tap h-[34px] rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover"
                            >
                                Apply
                            </button>
                        </form>
                    )}

                    <Field label="Compare with">
                        <select
                            data-a="sales:compare"
                            value={state.compare ?? ''}
                            onChange={(e) => go({ compare: e.target.value || null })}
                            className={control}
                        >
                            <option value="">Nothing</option>
                            <option value="previous">Previous period</option>
                            <option value="year">Same period last year</option>
                        </select>
                    </Field>

                    <Field label="Book">
                        <select
                            data-a="sales:book"
                            value={state.books?.[0] ?? ''}
                            onChange={(e) =>
                                go({
                                    books: e.target.value ? [Number(e.target.value)] : [],
                                })
                            }
                            className={`${control} sm:max-w-[240px]`}
                        >
                            <option value="">All books</option>
                            {options.books.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.title}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Format">
                        <select
                            data-a="sales:format"
                            value={state.format ?? ''}
                            onChange={(e) => go({ format: e.target.value || null })}
                            className={control}
                        >
                            <option value="">All formats</option>
                            {options.formats.map((f) => (
                                <option key={f.key} value={f.key}>
                                    {f.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Channel">
                        <select
                            data-a="sales:channel"
                            value={state.channel ?? ''}
                            onChange={(e) => go({ channel: e.target.value || null })}
                            className={control}
                        >
                            <option value="">All channels</option>
                            {options.channels.map((c) => (
                                <option key={c.key} value={c.key}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    {options.currencies.length > 1 && (
                        <Field label="Currency">
                            <select
                                data-a="sales:currency"
                                value={state.currency ?? ''}
                                onChange={(e) => go({ currency: e.target.value || null })}
                                className={control}
                            >
                                <option value="">Every currency</option>
                                {options.currencies.map((c) => (
                                    <option key={c} value={c}>
                                        {c.toUpperCase()}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                </div>

                <div className="mt-2.5 flex flex-wrap items-center justify-between gap-2 text-[11.5px] text-text-secondary">
                    <span data-a="sales:span">
                        {report.range.span}
                        {report.compare && ` · compared with ${report.compare.range.span}`}
                        {' · '}
                        {house ? `days in ${timezone}` : <TimezonePicker timezone={timezone} zones={options.timezones} />}
                    </span>
                    <span className="flex flex-wrap gap-2">
                        <a href={route('sales.export', { kind: 'lines' }) + qs(params)} data-a="sales:csv" className={linkButton} download>
                            CSV of sales
                        </a>
                        <a
                            href={route('sales.export', { kind: 'summary' }) + qs(params)}
                            data-a="sales:csv-summary"
                            className={linkButton}
                            download
                        >
                            Summary CSV
                        </a>
                    </span>
                </div>
            </section>

            {!anything ? (
                <p
                    data-a="sales:none"
                    className="rounded-tile border border-border-default bg-bg-surface p-4 text-center text-[12.5px] text-text-secondary"
                >
                    No storefront sales from {report.range.span}. They appear here, from real orders, the moment one is paid.
                </p>
            ) : (
                <>
                    {report.totals.map((row) => (
                        <Totals key={row.currency} row={row} delta={report.compare?.deltas?.[row.currency]} house={report.house} />
                    ))}

                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-[13px] font-semibold text-text-primary">
                            <span>
                                Gross per {BUCKET[report.range.bucket]} ({String(report.currency).toUpperCase()})
                            </span>
                            {report.compare && (
                                <span className="flex items-center gap-1.5 text-[11px] font-normal text-text-secondary">
                                    <span className="inline-block h-2.5 w-2.5 rounded-sm bg-brand-primary/60" aria-hidden="true" />{' '}
                                    {report.range.span}
                                    <span
                                        className="ml-2 inline-block h-2.5 w-2.5 rounded-sm border border-text-secondary/60"
                                        aria-hidden="true"
                                    />{' '}
                                    {report.compare.range.span}
                                </span>
                            )}
                        </h3>
                        <Chart
                            series={report.series}
                            compare={report.compare?.series ?? null}
                            currency={report.currency}
                            bucket={report.range.bucket}
                        />
                    </section>

                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5" data-a="sales:breakdown">
                        <div role="tablist" aria-label="Breakdown" className="-mx-1 mb-2 flex flex-wrap gap-1">
                            {DIMENSIONS.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={dimension === key}
                                    data-a={`sales:by:${key}`}
                                    onClick={() => setDimension(key)}
                                    className={`tap min-h-[32px] rounded-control px-2.5 text-[12.5px] font-semibold ${
                                        dimension === key
                                            ? 'bg-brand-primary/10 text-brand-primary'
                                            : 'text-text-secondary hover:text-text-primary'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <Breakdown rows={report.breakdowns[dimension]} totals={report.totals} dimension={dimension} house={report.house} />
                    </section>

                    {report.recent.length > 0 && <Recent orders={report.recent} />}
                </>
            )}

            <Statements statements={statements} scope={scope} />

            <p className="text-[11px] leading-relaxed text-text-secondary">
                Gross is what buyers paid, after any discount code. Payment fees are estimated from Stripe&rsquo;s standard card pricing;
                Stripe does not return its fee on a refund. A sale counts on the day it was paid ({timezone}), with any later refund on the
                same line. {report.house ? 'A house imprint’s royalty share is platform revenue. ' : ''}
                Royalties are what is left after fees, refunds and any partner&rsquo;s commission. Only storefront sales are recorded: other
                stores do not report sales here yet.
            </p>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <label className="flex min-w-0 flex-col gap-0.5 text-[10.5px] font-medium uppercase tracking-wide text-text-secondary">
            {label}
            {children}
        </label>
    );
}

function TimezonePicker({ timezone, zones }) {
    return (
        <label className="inline-flex items-center gap-1">
            <span>days in</span>
            <select
                data-a="sales:timezone"
                aria-label="Timezone for sales reports"
                value={timezone}
                onChange={(e) => router.put(route('sales.timezone'), { timezone: e.target.value }, { preserveScroll: true })}
                className="rounded border border-transparent bg-transparent px-0.5 text-[11.5px] text-text-secondary hover:border-border-input"
            >
                {zones.map((z) => (
                    <option key={z} value={z}>
                        {z}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Totals({ row, delta, house }) {
    const stats = [
        ['units', 'Copies sold', (v) => v.toLocaleString()],
        ['gross_cents', 'Gross', (v) => money(v, row.currency)],
        [
            'refunded_cents',
            'Refunded',
            (v) => money(v, row.currency),
            row.refunds ? `${row.refunds} refund${row.refunds === 1 ? '' : 's'}` : null,
        ],
        ['fee_cents', 'Payment fees', (v) => money(v, row.currency), 'estimate'],
        ['net_cents', 'Net', (v) => money(v, row.currency)],
        ...(row.partner_cents > 0
            ? [['partner_cents', 'Partner commissions', (v) => `−${money(v, row.currency)}`, `${row.partner_units} through partners`]]
            : []),
        ['royalty_cents', house ? 'House share' : 'Your royalty', (v) => money(v, row.currency), null, true],
    ];

    return (
        <div data-a={`sales:totals:${row.currency}`} className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            {stats.map(([field, label, format, hint, strong]) => (
                <div key={field} className="rounded-tile border border-border-default bg-bg-surface px-3 py-2.5">
                    <p className="text-[10.5px] uppercase tracking-wide text-text-secondary">
                        {label}
                        {field === 'units' && <span className="ml-1 normal-case tracking-normal">({row.currency.toUpperCase()})</span>}
                    </p>
                    <p
                        className={`mt-0.5 tabular-nums text-text-primary ${strong ? 'text-[17px] font-bold' : 'text-[15px] font-semibold'}`}
                    >
                        {format(row[field])}
                    </p>
                    {hint && <p className="text-[10.5px] text-text-secondary">{hint}</p>}
                    {delta?.[field] && (
                        <Delta
                            delta={delta[field]}
                            format={field === 'units' ? (v) => v.toLocaleString() : (v) => money(v, row.currency)}
                            invert={field === 'refunded_cents' || field === 'fee_cents'}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

/** "+12.00 (+20 %)", green when it is good news. */
function Delta({ delta, format, invert = false }) {
    const up = delta.abs > 0;
    const good = invert ? !up : up;
    const tone = delta.abs === 0 ? 'text-text-secondary' : good ? 'text-status-success' : 'text-status-danger';
    const sign = delta.abs > 0 ? '+' : '−';

    return (
        <p className={`text-[10.5px] font-semibold tabular-nums ${tone}`} data-a="sales:delta">
            {delta.abs === 0 ? (
                'no change'
            ) : (
                <>
                    {sign}
                    {format(Math.abs(delta.abs))}
                    {delta.pct !== null ? ` (${delta.pct > 0 ? '+' : ''}${delta.pct} %)` : ' (none before)'}
                </>
            )}
        </p>
    );
}

function Chart({ series, compare, currency, bucket }) {
    const peak = Math.max(1, ...series.map((b) => b.gross_cents), ...(compare ?? []).map((b) => b.gross_cents));
    const height = (cents) => (cents ? `${Math.max(3, (cents / peak) * 100)}px` : '2px');

    return (
        <>
            <div
                className="flex h-[110px] items-end gap-[2px]"
                role="img"
                aria-label={`Gross per ${bucket}, highest ${money(peak, currency)}`}
                data-a="sales:chart"
            >
                {series.map((b, i) => {
                    const then = compare?.[i];

                    return (
                        <div key={b.key} className="relative flex h-full min-w-0 flex-1 flex-col justify-end">
                            {then && (
                                <div
                                    className="absolute inset-x-0 bottom-0 rounded-t border border-text-secondary/50"
                                    style={{ height: height(then.gross_cents) }}
                                    title={`${then.label}: ${then.units} sold, ${money(then.gross_cents, currency)}`}
                                    aria-hidden="true"
                                />
                            )}
                            <div
                                title={`${b.label}: ${b.units} sold, ${money(b.gross_cents, currency)}${b.refunded_cents ? `, ${money(b.refunded_cents, currency)} refunded` : ''}`}
                                className={`relative mx-auto rounded-t bg-brand-primary/60 ${then ? 'w-3/5' : 'w-full'}`}
                                style={{ height: height(b.gross_cents) }}
                            />
                        </div>
                    );
                })}
            </div>
            <div className="mt-1.5 flex justify-between text-[10.5px] text-text-secondary">
                <span>{series[0]?.label}</span>
                <span>{series[series.length - 1]?.label}</span>
            </div>
        </>
    );
}

function Breakdown({ rows, totals, dimension, house }) {
    if (rows.length === 0) {
        return <p className="text-[12px] text-text-secondary">Nothing in this range.</p>;
    }

    const currencies = totals.filter((t) => t.units > 0);

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-left text-[12px]" data-a={`sales:table:${dimension}`}>
                <thead>
                    <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                        <th className="py-1.5 pr-3 font-medium">
                            {
                                {
                                    book: 'Book',
                                    format: 'Format',
                                    channel: 'Channel',
                                    period: 'Period',
                                }[dimension]
                            }
                        </th>
                        {COLUMNS.map(([key, label]) => (
                            <th key={key} className="py-1.5 pr-3 text-right font-medium">
                                {key === 'royalty_cents' && house ? 'House share' : label}
                            </th>
                        ))}
                    </tr>
                </thead>
                {currencies.map((total) => (
                    <tbody key={total.currency} className="border-b border-border-default last:border-0">
                        {rows
                            .filter((r) => r.currency === total.currency)
                            .map((row) => (
                                <tr
                                    key={`${row.key}-${row.currency}`}
                                    className="border-b border-border-divider last:border-0"
                                    data-a="sales:row"
                                >
                                    <td className="max-w-[260px] py-2 pr-3 text-text-primary">
                                        {row.url ? (
                                            <a href={row.url} className="hover:underline">
                                                {row.label}
                                            </a>
                                        ) : (
                                            row.label
                                        )}
                                    </td>
                                    {COLUMNS.map(([key, , isMoney]) => (
                                        <td key={key} className="py-2 pr-3 text-right tabular-nums">
                                            {isMoney ? money(row[key], row.currency) : row[key]}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        <tr className="font-semibold text-text-primary" data-a="sales:row:total">
                            <td className="py-2 pr-3">Total {currencies.length > 1 ? total.currency.toUpperCase() : ''}</td>
                            {COLUMNS.map(([key, , isMoney]) => (
                                <td key={key} className="py-2 pr-3 text-right tabular-nums">
                                    {isMoney ? money(total[key], total.currency) : total[key]}
                                </td>
                            ))}
                        </tr>
                    </tbody>
                ))}
            </table>
        </div>
    );
}

function Recent({ orders }) {
    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <h3 className="mb-2 text-[13px] font-semibold text-text-primary">Latest orders in this range</h3>
            <ul className="divide-y divide-border-default">
                {orders.map((order) => (
                    <li
                        key={`${order.number}-${order.title}`}
                        data-a="sales:order"
                        className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 py-2 text-[12px]"
                    >
                        <span className="min-w-0 text-text-primary">
                            {order.title}
                            <span className="ml-2 text-[11px] text-text-secondary">
                                {order.number} · {order.date} · {order.format}
                                {order.country ? ` · ${order.country}` : ''}
                            </span>
                        </span>
                        <span className="tabular-nums text-text-body">
                            {money(order.amount_cents, order.currency)}
                            {order.partner_cents > 0 && (
                                <span className="ml-2 text-[11px] text-text-secondary">
                                    partner −{money(order.partner_cents, order.currency)}
                                </span>
                            )}
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
    );
}

/** Royalty statements: a month or quarter, per payee and currency, as PDF. */
function Statements({ statements, scope }) {
    const [kind, setKind] = useState('month');
    const list = statements.filter((s) => s.kind === kind);

    if (statements.length === 0) {
        return null;
    }

    return (
        <section data-a="sales:statements" className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">Royalty statements</h3>
                <div className="flex gap-1" role="group" aria-label="Statement period">
                    {[
                        ['month', 'Monthly'],
                        ['quarter', 'Quarterly'],
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={kind === key}
                            data-a={`sales:statements:${key}`}
                            onClick={() => setKind(key)}
                            className={`tap min-h-[30px] rounded-control px-2.5 text-[12px] font-semibold ${kind === key ? 'bg-brand-primary/10 text-brand-primary' : 'text-text-secondary hover:text-text-primary'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>
            <ul className="divide-y divide-border-divider">
                {list.map((s) => (
                    <li key={`${s.payee.id}-${s.period}`} className="flex flex-wrap items-center justify-between gap-2 py-2 text-[12.5px]">
                        <span className="min-w-0 text-text-primary">
                            {s.label}
                            {scope === 'house' && <span className="ml-2 text-[11px] text-text-secondary">{s.payee.name}</span>}
                        </span>
                        <span className="flex flex-wrap gap-1.5">
                            {s.currencies.map((currency) => (
                                <a
                                    key={currency}
                                    href={
                                        route('sales.statement', {
                                            period: s.period,
                                        }) +
                                        qs({
                                            currency,
                                            ...(scope === 'house'
                                                ? {
                                                      scope: 'house',
                                                      payee: s.payee.id,
                                                  }
                                                : {}),
                                        })
                                    }
                                    data-a="sales:statement"
                                    className={linkButton}
                                >
                                    PDF · {currency.toUpperCase()}
                                </a>
                            ))}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
