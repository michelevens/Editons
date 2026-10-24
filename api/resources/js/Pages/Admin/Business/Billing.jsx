import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Pager, Table } from '@/Components/Admin/Kit';
import { AccountLink, BarChart, FormDialog, PaymentsNotice, TabLinks, Tile, Tiles, inputClass, money, shortDay } from '@/Components/Admin/BusinessKit';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Billing: revenue from real subscriptions, the subscriptions themselves,
 * trials about to end, accounts in dunning, why people cancel, credit packs
 * and refunds. Money figures say "No paid subscriptions yet" rather than $0
 * while there are none; trends come only from the nightly snapshots.
 */
const TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'subscriptions', label: 'Subscriptions' },
    { key: 'trials', label: 'Trials ending' },
    { key: 'past-due', label: 'Past due' },
    { key: 'cancellations', label: 'Cancellations' },
    { key: 'packs', label: 'Credit packs & refunds' },
];

const STATUS_TONE = { active: 'success', trialing: 'info', past_due: 'warning', unpaid: 'danger', canceled: 'neutral', incomplete: 'warning', incomplete_expired: 'neutral', paused: 'neutral' };

export default function Billing(props) {
    const { tab, kpis, payments } = props;

    return (
        <AdminLayout title="Billing" active="billing">
            <TabLinks label="Billing" active={tab} tabs={TABS.map((t) => ({ ...t, href: route('admin.billing.index', t.key === 'overview' ? {} : { tab: t.key }), count: t.key === 'past-due' ? kpis.past_due || null : t.key === 'trials' ? kpis.trialing || null : undefined }))} />
            {tab === 'overview' && <Overview {...props} />}
            {tab === 'subscriptions' && <Subscriptions {...props} />}
            {tab === 'trials' && <Trials {...props} />}
            {tab === 'past-due' && <PastDue {...props} />}
            {tab === 'cancellations' && <Cancellations {...props} />}
            {tab === 'packs' && <Packs {...props} payments={payments} />}
        </AdminLayout>
    );
}

function usdCents(cents) {
    return cents === null || cents === undefined ? '—' : money(cents, 'usd');
}

function Overview({ kpis, trend }) {
    const none = kpis.mrr_cents === null ? 'No paid subscriptions yet' : null;

    return (
        <>
            {!kpis.configured && (
                <p role="status" className="mb-4 rounded-tile bg-bg-subtle px-3 py-2 text-[12.5px] text-text-secondary" data-a="admin:billing:stripe-off">
                    Stripe is not configured on this deployment, so nobody can subscribe yet. Every figure below is read from real subscription rows; there are none to read.
                </p>
            )}
            <Tiles dataA="admin:billing:kpis">
                <Tile label="MRR" value={usdCents(kpis.mrr_cents)} none={none} detail={kpis.mrr_cents !== null ? `${kpis.paying_subscriptions} paying subscription${kpis.paying_subscriptions === 1 ? '' : 's'}` : null} alert={kpis.unknown_prices > 0 ? `${kpis.unknown_prices} on a price this deployment does not know` : null} dataA="admin:billing:mrr" />
                <Tile label="ARR" value={usdCents(kpis.arr_cents)} none={none} detail="MRR × 12" />
                <Tile label="ARPA" value={usdCents(kpis.arpa_cents)} none={none} detail={`per paying account (${kpis.paying_accounts})`} />
                <Tile label={`Net new MRR, ${kpis.month}`} value={usdCents(kpis.net_new_mrr_cents)} detail={`${usdCents(kpis.new_mrr_cents)} new (${kpis.new_subscriptions}) − ${usdCents(kpis.churned_mrr_cents)} churned (${kpis.churned_subscriptions})`} />
                <Tile label="Churned MRR this month" value={usdCents(kpis.churned_mrr_cents)} detail={`${kpis.churned_subscriptions} subscription${kpis.churned_subscriptions === 1 ? '' : 's'} ended`} />
                <Tile label="Opening MRR this month" value={kpis.opening_mrr_cents === null ? '—' : usdCents(kpis.opening_mrr_cents)} detail={kpis.opening_mrr_cents === null ? 'No snapshot from the last night of last month.' : 'From last month’s final snapshot.'} />
                <Tile label="Trialing" value={kpis.trialing} detail="not counted in MRR" />
                <Tile label="Past due or unpaid" value={kpis.past_due} detail="past due still counts in MRR" />
            </Tiles>

            <Panel className="mt-4" title="MRR, from the nightly snapshots" detail="platform:snapshot-metrics writes one point a day. Nothing before the first snapshot is estimated.">
                {trend.length === 0 ? (
                    <Empty>No snapshots yet.</Empty>
                ) : (
                    <BarChart ariaLabel="MRR per day" series={trend.map((p) => ({ key: p.day, label: shortDay(p.day), value: p.mrr_cents }))} format={(v) => money(v, 'usd')} />
                )}
            </Panel>
        </>
    );
}

function Subscriptions({ subscriptions, filters, plans, statuses }) {
    const [q, setQ] = useState(filters.q ?? '');
    const go = (changes) => router.get(route('admin.billing.index'), { tab: 'subscriptions', ...filters, q, ...changes }, { preserveState: true, preserveScroll: true });

    return (
        <Panel title="Subscriptions" detail="Every Stripe subscription, newest first. MRR is what each adds now, at the price it is on.">
            <form
                className="mb-3 flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    go({});
                }}
            >
                <label className="sr-only" htmlFor="sub-q">Search accounts</label>
                <input id="sub-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or email" className={`${inputClass} max-w-[220px]`} />
                <label className="sr-only" htmlFor="sub-status">Status</label>
                <select id="sub-status" data-a="admin:billing:status" value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value || null })} className={`${inputClass} max-w-[170px]`}>
                    <option value="">Any status</option>
                    {[...statuses, 'cancelling'].map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                </select>
                <label className="sr-only" htmlFor="sub-plan">Plan</label>
                <select id="sub-plan" value={filters.plan ?? ''} onChange={(e) => go({ plan: e.target.value || null })} className={`${inputClass} max-w-[150px]`}>
                    <option value="">Any plan</option>
                    {plans.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                </select>
            </form>
            {subscriptions.data.length === 0 ? (
                <Empty>{filters.status || filters.plan || filters.q ? 'No subscriptions match.' : 'No subscriptions yet.'}</Empty>
            ) : (
                <Table
                    head={['Account', 'Plan', 'Status', 'Started', 'Renews / ends', 'MRR']}
                    rows={subscriptions.data.map((s) => [
                        <AccountLink key="a" account={s.account} />,
                        s.plan ?? <span className="text-text-muted">Unknown price</span>,
                        <span key="s" className="text-[11.5px]"><Badge tone={STATUS_TONE[s.status] ?? 'neutral'}>{s.status.replace('_', ' ')}</Badge>{s.cancelling && <> <Badge tone="warning">cancels at period end</Badge></>}</span>,
                        formatDate(s.started_at, false),
                        s.ends_at ? `Ends ${formatDate(s.ends_at, false)}` : s.renews_at ? `Renews ${formatDate(s.renews_at, false)}` : s.trial_ends_at ? `Trial to ${formatDate(s.trial_ends_at, false)}` : '—',
                        s.mrr_cents ? money(s.mrr_cents, 'usd') : '—',
                    ])}
                />
            )}
            <Pager links={subscriptions.links} />
        </Panel>
    );
}

function DayChoice({ tab, days, options, current }) {
    return (
        <div className="mb-3 flex flex-wrap gap-1.5">
            {options.map((d) => (
                <button
                    key={d}
                    type="button"
                    onClick={() => router.get(route('admin.billing.index'), { tab, days: d }, { preserveScroll: true })}
                    aria-pressed={(days ?? current) === d}
                    className={`tap rounded-control px-3 py-1.5 text-[12.5px] ${(days ?? current) === d ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-subtle text-text-secondary'}`}
                >
                    {tab === 'trials' ? `Next ${d} days` : `Last ${d} days`}
                </button>
            ))}
        </div>
    );
}

function Trials({ trials, days }) {
    return (
        <Panel title="Trials ending" detail="Trialing subscriptions whose trial ends soon, and whether a card is on file.">
            <DayChoice tab="trials" days={days} options={[7, 14, 30]} current={7} />
            {trials.length === 0 ? (
                <Empty>No trials end in the next {days ?? 7} days.</Empty>
            ) : (
                <Table
                    head={['Account', 'Plan', 'Trial ends', 'Days left', 'Card']}
                    rows={trials.map((t) => [<AccountLink key="a" account={t.account} />, t.plan ?? '—', formatDate(t.trial_ends_at), t.days_left, t.card ? <Badge key="c" tone="success">on file</Badge> : <Badge key="c">none</Badge>])}
                />
            )}
        </Panel>
    );
}

function PastDue({ past_due: rows }) {
    return (
        <Panel title="Past due and unpaid" detail="Stripe retries failed payments on its own schedule and emails the customer; this is who is in that loop, longest first.">
            {rows.length === 0 ? (
                <Empty>Nobody is past due. Every subscription is paid up.</Empty>
            ) : (
                <Table
                    head={['Account', 'Plan', 'Status', 'Overdue since', 'Days', 'MRR at risk']}
                    rows={rows.map((r) => [
                        <AccountLink key="a" account={r.account} />,
                        r.plan ?? '—',
                        <Badge key="s" tone={STATUS_TONE[r.status]}>{r.status.replace('_', ' ')}</Badge>,
                        <span key="d" title={r.since_known ? 'From the first failed invoice' : 'From the last change to the subscription; the first failure was not reported'}>{formatDate(r.past_due_since, false)}{r.since_known ? '' : ' (approx.)'}</span>,
                        r.days_overdue,
                        r.price_cents ? money(r.price_cents, 'usd') : '—',
                    ])}
                />
            )}
        </Panel>
    );
}

function Cancellations({ cancellations, days }) {
    const { rows, breakdown, summary } = cancellations;

    return (
        <>
            <DayChoice tab="cancellations" days={days} options={[30, 90, 365]} current={90} />
            <Tiles>
                <Tile label="Cancellations" value={summary.total} />
                <Tile label="Ended" value={summary.ended} />
                <Tile label="Ending at period end" value={summary.ending} />
                <Tile label="Resumed" value={summary.resumed} detail="cancelled, then stayed" />
            </Tiles>
            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1fr_1.6fr]">
                <Panel title="Why people cancel" detail="The customer’s answer in Stripe’s cancellation flow. Écrivain has no cancel screen of its own; cancelling happens in Stripe’s billing portal.">
                    {breakdown.length === 0 ? (
                        <Empty>No cancellations in this period.</Empty>
                    ) : (
                        <ul className="space-y-2 text-[12.5px]" data-a="admin:billing:reasons">
                            {breakdown.map((b) => (
                                <li key={b.key}>
                                    <div className="flex justify-between gap-2"><span>{b.label}</span><span className="tabular-nums text-text-secondary">{b.count} · {b.pct}%</span></div>
                                    <div className="mt-1 h-2 overflow-hidden rounded-full bg-bg-subtle"><div className="h-full bg-brand-fill" style={{ width: `${b.pct}%` }} /></div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
                <Panel title="Cancellations">
                    {rows.length === 0 ? (
                        <Empty>None.</Empty>
                    ) : (
                        <Table
                            head={['When', 'Account', 'Plan', 'Why', 'Comment', 'Now']}
                            rows={rows.map((c) => [
                                formatDate(c.requested_at, false),
                                <AccountLink key="a" account={c.account} />,
                                c.plan ?? '—',
                                c.feedback ?? c.reason ?? '—',
                                <span key="m" className="block max-w-[260px] truncate" title={c.comment ?? ''}>{c.comment ?? '—'}</span>,
                                <Badge key="s" tone={c.state === 'resumed' ? 'success' : c.state === 'ended' ? 'neutral' : 'warning'}>{c.state}</Badge>,
                            ])}
                        />
                    )}
                </Panel>
            </div>
        </>
    );
}

function Packs({ packs, refunds, payments, can_edit: canEdit }) {
    return (
        <>
            <PaymentsNotice payments={payments} />
            <Panel title="Credit packs bought" detail="Ledger rows of kind purchase. A refund gives the money back through the gateway and takes back the pack’s credits that are left.">
                {packs.length === 0 ? (
                    <Empty>No credit packs bought yet. There is no pack checkout until Stripe is configured.</Empty>
                ) : (
                    <Table
                        head={['When', 'Account', 'Pack', 'Credits', 'Price', '']}
                        rows={packs.map((p) => [
                            formatDate(p.at),
                            <AccountLink key="a" account={p.account} />,
                            p.pack,
                            p.credits.toLocaleString(),
                            p.price_cents === null ? '—' : money(p.price_cents, 'usd'),
                            p.refunded ? <Badge key="r">refunded</Badge> : canEdit && p.refundable && payments.available ? (
                                <FormDialog key="f" label="Refund" danger dataA={`admin:billing:refund-pack:${p.id}`} url={route('admin.billing.refund-credits', p.id)} detail="The full pack price goes back to the card; the credits it bought that are unspent are taken back." />
                            ) : null,
                        ])}
                    />
                )}
            </Panel>
            <Panel className="mt-4" title="Refunds made from the console" detail="Storefront orders are refunded from Book sales. Subscription invoices are refunded in Stripe’s dashboard, which shows proration.">
                {refunds.length === 0 ? (
                    <Empty>No refunds yet.</Empty>
                ) : (
                    <Table
                        head={['When', 'What', 'Amount', 'Status', 'By', 'Reason']}
                        rows={refunds.map((r) => [formatDate(r.at), r.what, money(r.amount_cents, r.currency), <Badge key="s" tone={r.status === 'succeeded' ? 'success' : r.status === 'failed' ? 'danger' : 'warning'}>{r.status}{r.gateway === 'fake' ? ' (test)' : ''}</Badge>, r.by ?? '—', r.reason])}
                    />
                )}
            </Panel>
        </>
    );
}
