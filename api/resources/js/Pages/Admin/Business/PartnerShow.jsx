import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Table } from '@/Components/Admin/Kit';
import { AccountLink, BarChart, Field, FormDialog, Tile, Tiles, inputClass, money, shortDay } from '@/Components/Admin/BusinessKit';
import { PARTNER_TONE, RecordPayout } from '@/Components/Admin/PartnerKit';
import PartnerPageView from '@/Components/Partners/PartnerPageView';
import { Link, useForm } from '@inertiajs/react';

/**
 * One partner, for staff: who they are, their page as visitors see it,
 * visits and orders, the fraud signals, their ledger and payouts, internal
 * notes, and the owner's actions — each with a reason, in the audit log.
 */
export default function PartnerShow({ partner, page, stats, timeline, fraud, balances, eligibility, commissions, payouts, notes, discount_codes: codes, methods, can_edit: canEdit }) {
    return (
        <AdminLayout
            title={partner.name}
            active="partners"
            actions={<Link href={route('admin.partners.index', { tab: 'partners' })} className="text-[13px] text-brand-primary hover:underline">All partners</Link>}
        >
            <div className="mb-4 flex flex-wrap items-center gap-2 text-[12.5px]" data-a="admin:partner">
                <Badge tone={PARTNER_TONE[partner.status]}>{partner.status}</Badge>
                {partner.restricted && <Badge tone="warning">Invited: {partner.sellers.map((s) => s.name).join(', ') || 'one author'} only</Badge>}
                {partner.balance_frozen && <Badge tone="danger">Balance frozen</Badge>}
                <span>
                    Account: <AccountLink account={partner.account} />
                </span>
                <a href={partner.url} target="_blank" rel="noreferrer" className="text-brand-primary hover:underline">/p/{partner.handle}</a>
                <span className="text-text-secondary">code {partner.code}</span>
            </div>

            <Actions partner={partner} codes={codes} canEdit={canEdit} />

            <Tiles>
                <Tile label="Visits" value={stats.clicks} detail={`${stats.clicks_recent} in 30 days`} />
                <Tile label="Orders" value={stats.orders} detail={stats.conversion === null ? 'no visits yet' : `${stats.conversion} % conversion`} />
                {balances.map((b) => (
                    <Tile key={b.currency} label={`Balance ${b.currency.toUpperCase()}`} value={money(b.balance_cents, b.currency)} detail={`pending ${money(b.pending_cents, b.currency)} · paid ${money(b.paid_cents, b.currency)}`} />
                ))}
            </Tiles>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Fraud signals" detail="Counted from the rows; nothing here happens automatically.">
                    <ul className="space-y-1.5 text-[12.5px]" data-a="admin:partner:fraud">
                        {Object.entries(fraud).map(([key, f]) => (
                            <li key={key} className="flex items-center justify-between gap-2">
                                <span>{f.detail}</span>
                                <Badge tone={f.flag ? 'danger' : 'neutral'}>
                                    {f.count}
                                    {key === 'refund_rate' ? ' %' : ''}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </Panel>
                <Panel title="Visits, last 30 days">
                    <BarChart ariaLabel="Visits per day" series={timeline.map((d) => ({ key: d.day, label: shortDay(d.day), value: d.clicks }))} />
                    <p className="mt-1 text-[11.5px] text-text-secondary">Orders in the period: {timeline.reduce((n, d) => n + d.orders, 0)}</p>
                </Panel>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Application">
                    <dl className="grid grid-cols-[120px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]">
                        <dt className="text-text-secondary">Promotes through</dt>
                        <dd>{partner.promotion}</dd>
                        <dt className="text-text-secondary">Country</dt>
                        <dd>{partner.country ?? '—'}</dd>
                        <dt className="text-text-secondary">Note</dt>
                        <dd className="whitespace-pre-line break-words">{partner.promotion_note ?? '—'}</dd>
                        <dt className="text-text-secondary">Links</dt>
                        <dd className="break-all">{partner.links.length ? partner.links.join(' · ') : '—'}</dd>
                        <dt className="text-text-secondary">Agreement</dt>
                        <dd>v{partner.agreement.version}, {formatDate(partner.agreement.at)}</dd>
                        <dt className="text-text-secondary">Payout</dt>
                        <dd className="whitespace-pre-line break-words">{partner.payout_method ? `${partner.payout_method}: ${partner.payout_details ?? ''}` : 'No details yet'}</dd>
                        <dt className="text-text-secondary">Tax form</dt>
                        <dd>{partner.tax_form_received_at ? `Received ${formatDate(partner.tax_form_received_at, false)}` : 'Not received'}</dd>
                        <dt className="text-text-secondary">Codes</dt>
                        <dd>{partner.codes.length ? partner.codes.map((c) => c.code).join(', ') : '—'}</dd>
                        {partner.suspend_reason && (
                            <>
                                <dt className="text-text-secondary">Suspended for</dt>
                                <dd>{partner.suspend_reason}</dd>
                            </>
                        )}
                    </dl>
                </Panel>
                <Notes partner={partner} notes={notes} />
            </div>

            <Panel title="Ledger" className="mt-4">
                {commissions.length === 0 ? (
                    <Empty>No commissions yet.</Empty>
                ) : (
                    <Table
                        head={['Date', 'Book', 'Rate', 'Amount', 'Net', 'Status', 'Via']}
                        rows={commissions.map((c) => [c.date, c.kind === 'sale' ? c.title : `${c.kind}: ${c.note ?? ''}`, c.kind === 'sale' ? `${c.rate} %` : '—', money(c.amount_cents, c.currency), money(c.net_cents, c.currency), <Badge key="s" tone={PARTNER_TONE[c.status]}>{c.status}</Badge>, c.via ?? '—'])}
                    />
                )}
            </Panel>

            <Panel
                title="Payouts"
                className="mt-4"
                actions={
                    canEdit && (
                        <span className="flex flex-wrap gap-2">
                            {balances.filter((b) => eligibility[b.currency]?.eligible).map((b) => (
                                <RecordPayout key={b.currency} partner={partner} currency={b.currency} balance={b.balance_cents} methods={methods} />
                            ))}
                        </span>
                    )
                }
            >
                {balances.map((b) => !eligibility[b.currency]?.eligible && b.balance_cents > 0 && (
                    <p key={b.currency} className="mb-2 text-[12px] text-text-secondary">{b.currency.toUpperCase()}: {eligibility[b.currency]?.reason}</p>
                ))}
                {payouts.length === 0 ? (
                    <Empty>No payouts yet.</Empty>
                ) : (
                    <Table head={['Paid on', 'Amount', 'Method', 'Reference', 'By']} rows={payouts.map((p) => [p.paid_on, money(p.amount_cents, p.currency), p.method, p.reference ?? '—', p.by ?? '—'])} />
                )}
            </Panel>

            <Panel title="Their page, as visitors see it" className="mt-4">
                <div className="rounded-panel bg-bg-app p-3">
                    <PartnerPageView page={page} interactive={false} />
                </div>
            </Panel>
        </AdminLayout>
    );
}

function Actions({ partner, codes, canEdit }) {
    const id = partner.id;

    return (
        <div className="mb-4 flex flex-wrap gap-2" data-a="admin:partner:actions">
            {partner.status === 'applied' && (
                <>
                    <FormDialog label="Approve" dataA="admin:partner:approve" url={route('admin.partners.approve', id)} noReason detail="They are emailed and their page goes live." />
                    <FormDialog label="Decline" danger dataA="admin:partner:decline" url={route('admin.partners.decline', id)} detail="They are emailed this reason." />
                </>
            )}
            {canEdit && partner.status === 'approved' && (
                <FormDialog label="Suspend" danger dataA="admin:partner:suspend" url={route('admin.partners.suspend', id)} detail="The page goes offline, links stop earning, the balance is frozen. They are emailed this reason." />
            )}
            {canEdit && partner.status === 'suspended' && (
                <>
                    <FormDialog label="Reinstate" dataA="admin:partner:reinstate" url={route('admin.partners.reinstate', id)} />
                    {partner.balance_frozen && <FormDialog label="Release balance" dataA="admin:partner:release" url={route('admin.partners.release', id)} detail="The balance can then be paid while they stay suspended." />}
                    <FormDialog label="Forfeit balance" danger dataA="admin:partner:forfeit" url={route('admin.partners.forfeit', id)} detail="Pending and approved commissions are taken, with this reason. Cannot be undone." />
                </>
            )}
            {canEdit && (
                <>
                    <FormDialog
                        label="Discount code"
                        dataA="admin:partner:code"
                        url={route('admin.partners.code', id)}
                        detail="A sale with this code is the partner's, even without their link (a flyer, a WhatsApp message). Choose none to detach."
                        initial={{ discount_code_id: partner.codes[0]?.id ?? '' }}
                        transform={(d) => ({ ...d, discount_code_id: d.discount_code_id || null })}
                        fields={(form) => (
                            <Field label="Storefront code" id="pc-code">
                                <select id="pc-code" value={form.data.discount_code_id} onChange={(e) => form.setData('discount_code_id', e.target.value)} className={inputClass}>
                                    <option value="">None</option>
                                    {codes.map((c) => <option key={c.id} value={c.id}>{c.code}</option>)}
                                </select>
                            </Field>
                        )}
                    />
                    <FormDialog
                        label={partner.tax_form_received_at ? 'Unmark tax form' : 'Mark tax form received'}
                        dataA="admin:partner:tax"
                        url={route('admin.partners.tax-form', id)}
                        initial={{ received: !partner.tax_form_received_at }}
                        detail="Only a note that the form came in. Écrivain stores no tax ids."
                    />
                    {partner.restricted && <FormDialog label="Open the whole catalogue" dataA="admin:partner:unrestrict" url={route('admin.partners.unrestrict', id)} />}
                </>
            )}
        </div>
    );
}

function Notes({ partner, notes }) {
    const form = useForm({ body: '', pinned: false });

    return (
        <Panel title="Internal notes" detail="On the partner's account; never shown to them.">
            {partner.account && (
                <form
                    className="mb-3 flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.accounts.notes.store', partner.account.id), { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <input aria-label="New note" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} placeholder="Add a note" className={inputClass} required />
                    <button type="submit" disabled={form.processing} className="tap rounded-button border border-border-default px-3 text-[13px] font-semibold">
                        Add
                    </button>
                </form>
            )}
            {notes.length === 0 ? (
                <Empty>No notes.</Empty>
            ) : (
                <ul className="space-y-2 text-[12.5px]">
                    {notes.map((n) => (
                        <li key={n.id} className="rounded-tile bg-bg-subtle px-3 py-2">
                            <p className="whitespace-pre-line break-words">{n.body}</p>
                            <p className="mt-0.5 text-[11px] text-text-secondary">{n.author} · {formatDate(n.at)}</p>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
