import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Pager, Table } from '@/Components/Admin/Kit';
import { AccountLink, BarChart, Field, FormDialog, PaymentsNotice, Tile, Tiles, inputClass, money, shortDay } from '@/Components/Admin/BusinessKit';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Book sales across the platform. Money is per currency, never added
 * across; fees are Stripe's standard pricing, estimated; royalties are the
 * authors' share after refunds. Écrivain pays nobody automatically — Stripe
 * Connect is not built — so payouts are recorded here by hand.
 */
const ORDER_TONE = { paid: 'success', partially_refunded: 'warning', refunded: 'neutral', cancelled: 'neutral', pending: 'info' };

export default function Sales({ enabled, totals, totals_30d: totals30, daily, top, orders, filters, royalties, payouts, methods, currencies, statuses, fee, platform_rate: platformRate, payments, can_edit: canEdit }) {
    return (
        <AdminLayout title="Book sales" active="sales">
            {!enabled && (
                <p role="status" className="mb-4 rounded-tile bg-bg-subtle px-3 py-2 text-[12.5px] text-text-secondary" data-a="admin:sales:off">
                    Direct sales are off on this deployment (Stripe is not configured). Anything below was sold while they were on.
                </p>
            )}

            {totals.length === 0 ? (
                <Tiles dataA="admin:sales:tiles">
                    <Tile label="Storefront sales" none="No sales yet" detail="Paid orders appear here when a reader buys a book." />
                </Tiles>
            ) : (
                totals.map((t) => (
                    <div key={t.currency} className="mb-3">
                        <p className="mb-1.5 text-[11.5px] font-semibold uppercase tracking-[0.1em] text-text-secondary">{t.currency.toUpperCase()}, all time</p>
                        <Tiles dataA="admin:sales:tiles">
                            <Tile label="Gross" value={money(t.gross_cents, t.currency)} detail={`${t.units} sold · ${t.orders} orders`} />
                            <Tile label="Refunded" value={money(t.refunded_cents, t.currency)} />
                            <Tile label="Payment fees (est.)" value={money(t.fee_cents, t.currency)} detail={`${fee.percent}% + ${fee.fixed_cents}¢ a charge`} />
                            <Tile label="Authors’ royalties" value={money(t.royalty_cents, t.currency)} detail={`Platform share ${Math.round(platformRate * 100)}% of net`} />
                            <Tile
                                label="Platform revenue"
                                value={money(t.platform_cents, t.currency)}
                                detail={t.house_cents > 0 ? `Includes ${money(t.house_cents, t.currency)} from house books` : 'After fees, royalties and partners'}
                            />
                        </Tiles>
                    </div>
                ))
            )}

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1.4fr_1fr]">
                <Panel title={`Revenue, last 30 days (${daily.currency.toUpperCase()})`} detail={totals30.length > 1 ? 'The currency with the most sales; other currencies are in the tables.' : 'Gross per day.'}>
                    <BarChart ariaLabel="Gross per day, last 30 days" series={daily.days.map((d) => ({ key: d.day, label: shortDay(d.day), value: d.gross_cents }))} format={(v) => money(v, daily.currency)} />
                </Panel>
                <Panel title="Best sellers, 30 days">
                    {top.books.length === 0 ? (
                        <Empty>Nothing sold in the last 30 days.</Empty>
                    ) : (
                        <Table head={['Book', 'Author', 'Units', 'Gross']} rows={top.books.map((b) => [b.title, b.author?.name ?? '—', b.units, money(b.gross_cents, b.currency)])} />
                    )}
                    {top.authors.length > 0 && (
                        <>
                            <h3 className="mb-1 mt-4 text-[12px] font-semibold text-text-secondary">Top authors</h3>
                            <Table head={['Author', 'Units', 'Gross', 'Royalty']} rows={top.authors.map((a) => [a.author ? <Link key="a" href={route('admin.accounts.show', a.author.id)} className="text-brand-primary hover:underline">{a.author.name}</Link> : '—', a.units, money(a.gross_cents, a.currency), money(a.royalty_cents, a.currency)])} />
                        </>
                    )}
                </Panel>
            </div>

            <Orders orders={orders} filters={filters} currencies={currencies} statuses={statuses} canEdit={canEdit} payments={payments} />

            <Panel
                className="mt-4"
                title="Royalties owed"
                detail="Earned (after estimated fees and refunds) less payouts recorded below. Stripe Connect is not built: pay authors outside Écrivain, then record it here."
                data-a="admin:sales:royalties"
            >
                {royalties.length === 0 ? (
                    <Empty>No royalties earned yet.</Empty>
                ) : (
                    <Table
                        head={['Author', 'Currency', 'Earned', 'Paid', 'Owed', 'Last paid', '']}
                        rows={royalties.map((r) => [
                            <AccountLink key="a" account={r.account} />,
                            r.currency.toUpperCase(),
                            money(r.earned_cents, r.currency),
                            money(r.paid_cents, r.currency),
                            <span key="o" className={r.owed_cents > 0 ? 'font-semibold text-text-primary' : ''}>{money(r.owed_cents, r.currency)}</span>,
                            r.last_paid ? formatDate(r.last_paid, false) : '—',
                            canEdit && r.account && r.owed_cents > 0 ? <PayoutForm key="p" row={r} methods={methods} /> : null,
                        ])}
                    />
                )}
            </Panel>

            <Panel className="mt-4" title="Payouts ledger" detail="Every payout recorded, newest first. Each is in the audit log with its reason.">
                {payouts.length === 0 ? (
                    <Empty>No payouts recorded.</Empty>
                ) : (
                    <Table head={['Paid on', 'Author', 'Amount', 'Method', 'Reference', 'Recorded by']} rows={payouts.map((p) => [formatDate(p.paid_on, false), <AccountLink key="a" account={p.account} />, money(p.amount_cents, p.currency), p.method, p.reference ?? '—', p.by ?? '—'])} />
                )}
            </Panel>
        </AdminLayout>
    );
}

function Orders({ orders, filters, currencies, statuses, canEdit, payments }) {
    const [q, setQ] = useState(filters.q ?? '');
    const go = (changes) => router.get(route('admin.sales.index'), { ...filters, q, ...changes }, { preserveState: true, preserveScroll: true });

    return (
        <Panel className="mt-4" title="Orders" detail="Buyers’ addresses are masked: they are the author’s customers." data-a="admin:sales:orders">
            {canEdit && <PaymentsNotice payments={payments} />}
            <form className="mb-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); go({}); }}>
                <label className="sr-only" htmlFor="orders-q">Search orders</label>
                <input id="orders-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Order number or book" className={`${inputClass} max-w-[220px]`} />
                <label className="sr-only" htmlFor="orders-status">Status</label>
                <select id="orders-status" value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value || null })} className={`${inputClass} max-w-[170px]`}>
                    <option value="">Any status</option>
                    {statuses.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                </select>
                <label className="sr-only" htmlFor="orders-currency">Currency</label>
                <select id="orders-currency" value={filters.currency ?? ''} onChange={(e) => go({ currency: e.target.value || null })} className={`${inputClass} max-w-[120px]`}>
                    <option value="">Any currency</option>
                    {currencies.map((c) => <option key={c} value={c}>{c.toUpperCase()}</option>)}
                </select>
            </form>
            {orders.data.length === 0 ? (
                <Empty>No orders{filters.status || filters.currency || filters.q ? ' match' : ' yet'}.</Empty>
            ) : (
                <Table
                    head={['Order', 'Date', 'Book', 'Author', 'Buyer', 'Amount', 'Status', '']}
                    rows={orders.data.map((o) => [
                        <code key="n" className="text-[11.5px]">{o.number}</code>,
                        formatDate(o.at, false),
                        <span key="b" className="block max-w-[200px] truncate">{o.books.join(', ')}</span>,
                        o.authors.map((a) => a.name).join(', ') || '—',
                        o.buyer ?? '—',
                        <span key="m">{money(o.amount_cents, o.currency)}{o.discount_cents > 0 && <span className="block text-[11px] text-text-secondary">−{money(o.discount_cents, o.currency)} code</span>}{o.refunded_cents > 0 && <span className="block text-[11px] text-text-secondary">{money(o.refunded_cents, o.currency)} refunded</span>}</span>,
                        <Badge key="s" tone={ORDER_TONE[o.status]}>{o.status.replace('_', ' ')}</Badge>,
                        canEdit && o.refundable_cents > 0 && payments.available ? (
                            <FormDialog
                                key="r"
                                label="Refund"
                                danger
                                dataA={`admin:sales:refund:${o.id}`}
                                url={route('admin.billing.refund-order', o.id)}
                                detail={`Up to ${money(o.refundable_cents, o.currency)}. Leave the amount empty to refund everything that is left; a full refund revokes the buyer's download links.`}
                                initial={{ amount: '' }}
                                fields={(form) => (
                                    <Field label={`Amount (${o.currency.toUpperCase()}, optional)`} id={`refund-${o.id}-amount`}>
                                        <input id={`refund-${o.id}-amount`} type="number" min="0.01" step="0.01" max={o.refundable_cents / 100} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className={inputClass} />
                                    </Field>
                                )}
                            />
                        ) : null,
                    ])}
                />
            )}
            <Pager links={orders.links} />
        </Panel>
    );
}

function PayoutForm({ row, methods }) {
    const id = `payout-${row.account.id}-${row.currency}`;

    return (
        <FormDialog
            label="Record payout"
            dataA={`admin:sales:payout:${row.account.id}:${row.currency}`}
            url={route('admin.sales.payout', row.account.id)}
            detail={`${money(row.owed_cents, row.currency)} owed. Record money you have already sent; nothing is paid from here.`}
            initial={{ currency: row.currency, amount: (row.owed_cents / 100).toFixed(2), method: 'bank_transfer', reference: '', paid_on: new Date().toISOString().slice(0, 10), note: '' }}
            fields={(form) => (
                <>
                    <Field label={`Amount (${row.currency.toUpperCase()})`} id={`${id}-amount`}>
                        <input id={`${id}-amount`} type="number" min="0.01" step="0.01" max={row.owed_cents / 100} required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Method" id={`${id}-method`}>
                        <select id={`${id}-method`} value={form.data.method} onChange={(e) => form.setData('method', e.target.value)} className={inputClass}>
                            {methods.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
                        </select>
                    </Field>
                    <Field label="Reference (transfer id, optional)" id={`${id}-ref`}>
                        <input id={`${id}-ref`} maxLength={255} value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Paid on" id={`${id}-date`}>
                        <input id={`${id}-date`} type="date" required value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} className={inputClass} />
                    </Field>
                </>
            )}
        />
    );
}
