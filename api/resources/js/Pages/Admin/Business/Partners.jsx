import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Pager, Table } from '@/Components/Admin/Kit';
import { AccountLink, Field, FormDialog, TabLinks, inputClass, money } from '@/Components/Admin/BusinessKit';
import { PARTNER_TONE, RecordPayout } from '@/Components/Admin/PartnerKit';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Écrivain Partners in the console: the applications queue, the partners,
 * the commissions ledger (with a CSV), payouts and the program's settings.
 * Approving and declining are for all staff; money, standing and settings
 * are the owner's, each with a reason in the audit log.
 */
export default function Partners(props) {
    const { tab, counts, enabled } = props;
    const tabs = [
        { key: 'applications', label: 'Applications', count: counts.applications },
        { key: 'partners', label: 'Partners', count: counts.partners },
        { key: 'commissions', label: 'Commissions' },
        { key: 'payouts', label: 'Payouts' },
        { key: 'settings', label: 'Settings' },
    ].map((t) => ({ ...t, href: route('admin.partners.index', { tab: t.key }) }));

    return (
        <AdminLayout title="Partners" active="partners">
            {!enabled && (
                <p role="status" className="mb-4 rounded-tile bg-bg-subtle px-3 py-2 text-[12.5px] text-text-secondary" data-a="admin:partners:off">
                    The partner program is switched off on this deployment (feature flag “partners”, which also needs direct sales). Nothing here is public.
                </p>
            )}
            <TabLinks label="Partners" active={tab} tabs={tabs} />
            {tab === 'applications' && <Applications {...props} />}
            {tab === 'partners' && <PartnerList {...props} />}
            {tab === 'commissions' && <Ledger {...props} />}
            {tab === 'payouts' && <Payouts {...props} />}
            {tab === 'settings' && <Settings {...props} />}
        </AdminLayout>
    );
}

function Applications({ applications }) {
    if (applications.length === 0) {
        return <Panel><Empty>No applications waiting.</Empty></Panel>;
    }

    return (
        <div className="space-y-3" data-a="admin:partners:applications">
            {applications.map((a) => (
                <Panel
                    key={a.id}
                    title={a.name}
                    detail={`Applied ${formatDate(a.applied_at)} · ${a.promotion}`}
                    actions={
                        <span className="flex flex-wrap gap-2">
                            <FormDialog label="Approve" dataA={`admin:partners:approve:${a.id}`} url={route('admin.partners.approve', a.id)} noReason detail={`${a.name} is told by email, and their page goes live.`} />
                            <FormDialog label="Decline" danger dataA={`admin:partners:decline:${a.id}`} url={route('admin.partners.decline', a.id)} detail="The applicant is emailed this reason. They can apply again." />
                        </span>
                    }
                >
                    <div className="grid gap-2 text-[12.5px] sm:grid-cols-2">
                        <p>
                            Account: <AccountLink account={a.account} />
                        </p>
                        <p>
                            Page: <Link href={route('admin.partners.show', a.id)} className="text-brand-primary hover:underline">/p/{a.handle}</Link>
                        </p>
                        {a.promotion_note && <p className="whitespace-pre-line break-words sm:col-span-2">{a.promotion_note}</p>}
                        {a.links.length > 0 && (
                            <p className="break-all sm:col-span-2">
                                {a.links.map((l) => (
                                    <a key={l} href={l} target="_blank" rel="noopener noreferrer nofollow" className="mr-3 text-brand-primary hover:underline">
                                        {l}
                                    </a>
                                ))}
                            </p>
                        )}
                        <p className="text-text-secondary sm:col-span-2">
                            Agreement v{a.agreement.version}, accepted {formatDate(a.agreement.at)}
                        </p>
                    </div>
                </Panel>
            ))}
        </div>
    );
}

function PartnerList({ partners, filters }) {
    const [q, setQ] = useState(filters.q ?? '');

    return (
        <Panel
            title="Partners"
            actions={
                <form onSubmit={(e) => { e.preventDefault(); router.get(route('admin.partners.index'), { tab: 'partners', q, status: filters.status }, { preserveState: true }); }} className="flex gap-2">
                    <input aria-label="Search partners" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or handle" className={`${inputClass} w-[180px]`} />
                    <select aria-label="Status" value={filters.status ?? ''} onChange={(e) => router.get(route('admin.partners.index'), { tab: 'partners', q, status: e.target.value || undefined })} className={`${inputClass} w-[130px]`}>
                        <option value="">Any status</option>
                        <option value="approved">Approved</option>
                        <option value="suspended">Suspended</option>
                        <option value="declined">Declined</option>
                    </select>
                </form>
            }
        >
            {partners.data.length === 0 ? (
                <Empty>No partners yet.</Empty>
            ) : (
                <Table
                    head={['Partner', 'Status', 'Visits', 'Orders', 'Balance', 'Tax form']}
                    rows={partners.data.map((p) => [
                        <Link key="n" href={route('admin.partners.show', p.id)} className="font-semibold text-brand-primary hover:underline" data-a="admin:partners:row">{p.name}</Link>,
                        <Badge key="s" tone={PARTNER_TONE[p.status]}>{p.status}</Badge>,
                        p.stats.clicks,
                        p.stats.orders,
                        p.balances.map((b) => money(b.balance_cents, b.currency)).join(' · ') || '—',
                        p.tax_form ? 'Received' : '—',
                    ])}
                />
            )}
            <Pager links={partners.links} />
        </Panel>
    );
}

function Ledger({ commissions, filters, statuses, currencies }) {
    const set = (key, value) => router.get(route('admin.partners.index'), { ...filters, tab: 'commissions', [key]: value || undefined }, { preserveState: true });

    return (
        <Panel
            title="Commissions ledger"
            detail="Sale rows, refund adjustments and forfeits. Amounts are as they were when earned; net is after refunds before payout."
            actions={
                <span className="flex flex-wrap gap-2">
                    <select aria-label="Status" value={filters.status ?? ''} onChange={(e) => set('status', e.target.value)} className={`${inputClass} w-[120px]`}>
                        <option value="">Any status</option>
                        {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select aria-label="Currency" value={filters.currency ?? ''} onChange={(e) => set('currency', e.target.value)} className={`${inputClass} w-[100px]`}>
                        <option value="">Any</option>
                        {currencies.map((c) => <option key={c} value={c}>{c.toUpperCase()}</option>)}
                    </select>
                    <a href={route('admin.partners.export', { status: filters.status, currency: filters.currency, partner: filters.partner })} data-a="admin:partners:csv" className="tap inline-flex items-center rounded-button border border-border-default px-3 text-[13px] font-semibold">
                        CSV
                    </a>
                </span>
            }
        >
            {commissions.data.length === 0 ? (
                <Empty>No commissions yet.</Empty>
            ) : (
                <Table
                    head={['Date', 'Partner', 'Book', 'Rate', 'Amount', 'Net', 'Status', 'Via']}
                    rows={commissions.data.map((c) => [
                        c.date,
                        c.partner ? <Link key="p" href={route('admin.partners.show', c.partner.id)} className="text-brand-primary hover:underline">{c.partner.name}</Link> : '—',
                        c.kind === 'sale' ? c.title : `${c.kind}: ${c.note ?? c.title ?? ''}`,
                        c.kind === 'sale' ? `${c.rate} %` : '—',
                        money(c.amount_cents, c.currency),
                        money(c.net_cents, c.currency),
                        <Badge key="s" tone={PARTNER_TONE[c.status]}>{c.status}</Badge>,
                        c.via ?? '—',
                    ])}
                />
            )}
            <Pager links={commissions.links} />
        </Panel>
    );
}

function Payouts({ eligible, payouts, can_edit: canEdit, methods, settings }) {
    return (
        <div className="space-y-4">
            <Panel title="Ready to pay" detail={`Approved balances of at least ${money(settings.payout_minimum_cents, 'usd')} (or the same in another currency). Pay outside Écrivain, then record it here.`}>
                {eligible.length === 0 ? (
                    <Empty>Nobody has a payable balance.</Empty>
                ) : (
                    <Table
                        head={['Partner', 'Balance', 'Pays by', 'Ready', '']}
                        rows={eligible.map((row) => [
                            <Link key="p" href={route('admin.partners.show', row.partner.id)} className="text-brand-primary hover:underline">{row.partner.name}</Link>,
                            money(row.balance_cents, row.currency),
                            row.payout_method ?? '—',
                            row.eligible ? 'Yes' : row.reason,
                            canEdit && row.eligible ? <RecordPayout key="r" partner={row.partner} currency={row.currency} balance={row.balance_cents} methods={methods} /> : null,
                        ])}
                    />
                )}
            </Panel>
            <Panel title="Recorded payouts">
                {payouts.length === 0 ? (
                    <Empty>No partner payouts recorded.</Empty>
                ) : (
                    <Table head={['Paid on', 'Account', 'Amount', 'Method', 'Reference', 'By']} rows={payouts.map((p) => [p.paid_on, <AccountLink key="a" account={p.account} />, money(p.amount_cents, p.currency), p.method, p.reference ?? '—', p.by ?? '—'])} />
                )}
            </Panel>
        </div>
    );
}

function Settings({ settings, can_edit: canEdit }) {
    const rows = [
        ['default_rate', 'Default commission', '%'],
        ['min_rate', 'Lowest rate a seller may choose', '%'],
        ['max_rate', 'Highest rate a seller may choose', '%'],
        ['hold_days', 'Hold before a commission is payable', 'days'],
        ['cookie_days', 'How long a link keeps the credit', 'days'],
    ];

    return (
        <Panel
            title="Program settings"
            detail="Changes apply to what happens next: a commission keeps the rate it was earned at, and a hold the date it was given."
            actions={
                canEdit ? (
                    <FormDialog
                        label="Change settings"
                        dataA="admin:partners:settings"
                        url={route('admin.partners.settings')}
                        initial={{ ...Object.fromEntries(rows.map(([k]) => [k, settings[k]])), payout_minimum: (settings.payout_minimum_cents / 100).toFixed(2) }}
                        fields={(form) => (
                            <>
                                {rows.map(([key, label, unit]) => (
                                    <Field key={key} label={`${label} (${unit})`} id={`ps-${key}`}>
                                        <input id={`ps-${key}`} type="number" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} className={inputClass} />
                                    </Field>
                                ))}
                                <Field label="Payout minimum" id="ps-minimum">
                                    <input id="ps-minimum" type="number" step="0.01" value={form.data.payout_minimum} onChange={(e) => form.setData('payout_minimum', e.target.value)} className={inputClass} />
                                </Field>
                            </>
                        )}
                    />
                ) : null
            }
        >
            <Table
                head={['Setting', 'Value']}
                rows={[...rows.map(([key, label, unit]) => [label, `${settings[key]} ${unit}`]), ['Payout minimum', money(settings.payout_minimum_cents, 'usd')]]}
            />
        </Panel>
    );
}
