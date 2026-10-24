import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Table } from '@/Components/Admin/Kit';
import { Check, Field, FormDialog, GrowthTabs, inputClass, money } from '@/Components/Admin/BusinessKit';

/**
 * Discount codes. Storefront codes work today: the buyer types one on a
 * book's page and the order is priced with it. Plan codes are kept (with a
 * Stripe coupon once Stripe is configured) for the subscription checkout,
 * which is not built yet. A code's kind and value never change once made.
 */
export default function Discounts({ codes, redemptions, plans, currencies, payments, can_edit: canEdit }) {
    return (
        <AdminLayout title="Growth" active="growth" actions={canEdit ? <NewCode plans={plans} currencies={currencies} /> : null}>
            <GrowthTabs active="discounts" />
            <p className="mb-3 text-[12.5px] text-text-secondary">
                Storefront codes apply at checkout now. Plan codes are stored{payments.available ? ' with a Stripe coupon' : ''} for when subscription checkout is built; nobody can use them yet.
            </p>

            <Panel title="Codes" data-a="admin:discounts">
                {codes.length === 0 ? (
                    <Empty>No discount codes yet.</Empty>
                ) : (
                    <Table
                        head={['Code', 'Off', 'Applies to', 'Used', 'Expires', 'Status', '']}
                        rows={codes.map((c) => [
                            <span key="c"><code className="text-[12px] font-semibold">{c.code}</code>{c.description && <span className="block text-[11.5px] text-text-secondary">{c.description}</span>}</span>,
                            c.label,
                            [c.applies_to_storefront && 'Books', c.applies_to_plans && (c.plan_keys ? `Plans: ${c.plan_keys.join(', ')}` : 'All paid plans'), c.first_time_only && 'first purchase only'].filter(Boolean).join(' · '),
                            `${c.used}${c.max_redemptions ? ` / ${c.max_redemptions}` : ''}`,
                            c.expires_at ? formatDate(c.expires_at, false) : 'Never',
                            <Badge key="s" tone={!c.active ? 'neutral' : c.expired ? 'warning' : 'success'}>{!c.active ? 'off' : c.expired ? 'expired' : 'active'}</Badge>,
                            canEdit ? (
                                <FormDialog
                                    key="e"
                                    label="Edit"
                                    dataA={`admin:discount:${c.id}:edit`}
                                    url={route('admin.growth.discounts.update', c.id)}
                                    method="patch"
                                    detail="Kind and value are fixed once a code exists."
                                    initial={{ active: c.active, max_redemptions: c.max_redemptions ?? '', expires_at: c.expires_at?.slice(0, 10) ?? '', description: c.description ?? '' }}
                                    fields={(form) => (
                                        <>
                                            <Check id={`d-${c.id}-active`} label="Active" checked={form.data.active} onChange={(v) => form.setData('active', v)} />
                                            <Field label="Most uses (empty: no limit)" id={`d-${c.id}-max`}>
                                                <input id={`d-${c.id}-max`} type="number" min="1" value={form.data.max_redemptions} onChange={(e) => form.setData('max_redemptions', e.target.value)} className={inputClass} />
                                            </Field>
                                            <Field label="Expires (empty: never)" id={`d-${c.id}-exp`}>
                                                <input id={`d-${c.id}-exp`} type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} className={inputClass} />
                                            </Field>
                                            <Field label="Description" id={`d-${c.id}-desc`}>
                                                <input id={`d-${c.id}-desc`} maxLength={255} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} className={inputClass} />
                                            </Field>
                                        </>
                                    )}
                                />
                            ) : null,
                        ])}
                    />
                )}
            </Panel>

            <Panel className="mt-4" title="Redemptions" detail="Paid orders that used a code. Addresses are masked.">
                {redemptions.length === 0 ? (
                    <Empty>No code has been used yet.</Empty>
                ) : (
                    <Table head={['When', 'Code', 'Order', 'Buyer', 'Took off']} rows={redemptions.map((r) => [formatDate(r.at), <code key="c">{r.code}</code>, r.order ?? '—', r.email ?? '—', money(r.amount_off_cents, r.currency)])} />
                )}
            </Panel>
        </AdminLayout>
    );
}

function NewCode({ plans, currencies }) {
    return (
        <FormDialog
            label="New code"
            dataA="admin:discount:new"
            url={route('admin.growth.discounts.store')}
            wide
            initial={{ code: '', description: '', kind: 'percent', percent_off: 10, amount_off: '', currency: 'usd', applies_to_storefront: true, applies_to_plans: false, plan_keys: [], max_redemptions: '', expires_at: '', first_time_only: false }}
            fields={(form) => (
                <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                    <Field label="Code" id="dn-code" hint="Letters, digits, - and _. Not case-sensitive.">
                        <input id="dn-code" data-a="admin:discount:code" required pattern="[A-Za-z0-9_\-]{3,40}" value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={inputClass} />
                    </Field>
                    <Field label="Description (optional)" id="dn-desc">
                        <input id="dn-desc" maxLength={255} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Kind" id="dn-kind">
                        <select id="dn-kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)} className={inputClass}>
                            <option value="percent">Percentage off</option>
                            <option value="amount">Amount off</option>
                        </select>
                    </Field>
                    {form.data.kind === 'percent' ? (
                        <Field label="Percent off" id="dn-pct">
                            <input id="dn-pct" data-a="admin:discount:percent" type="number" min="1" max="100" required value={form.data.percent_off} onChange={(e) => form.setData('percent_off', e.target.value)} className={inputClass} />
                        </Field>
                    ) : (
                        <div className="grid grid-cols-[1fr_100px] gap-2">
                            <Field label="Amount off" id="dn-amt">
                                <input id="dn-amt" type="number" min="0.01" step="0.01" required value={form.data.amount_off} onChange={(e) => form.setData('amount_off', e.target.value)} className={inputClass} />
                            </Field>
                            <Field label="Currency" id="dn-cur">
                                <select id="dn-cur" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} className={inputClass}>
                                    {currencies.map((c) => <option key={c} value={c}>{c.toUpperCase()}</option>)}
                                </select>
                            </Field>
                        </div>
                    )}
                    <Field label="Most uses (empty: no limit)" id="dn-max">
                        <input id="dn-max" type="number" min="1" value={form.data.max_redemptions} onChange={(e) => form.setData('max_redemptions', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Expires (empty: never)" id="dn-exp">
                        <input id="dn-exp" type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} className={inputClass} />
                    </Field>
                    <fieldset className="mt-3 space-y-1 sm:col-span-2">
                        <legend className="text-[12.5px] font-medium text-text-primary">Applies to</legend>
                        <Check id="dn-store" label="Books in the storefront" checked={form.data.applies_to_storefront} onChange={(v) => form.setData('applies_to_storefront', v)} />
                        <Check id="dn-plans" label="Plans (when subscription checkout exists)" checked={form.data.applies_to_plans} onChange={(v) => form.setData('applies_to_plans', v)} />
                        {form.data.applies_to_plans && (
                            <div className="ml-6 flex flex-wrap gap-x-4">
                                {plans.map((p) => <Check key={p.key} id={`dn-plan-${p.key}`} label={p.name} checked={form.data.plan_keys.includes(p.key)} onChange={(v) => form.setData('plan_keys', v ? [...form.data.plan_keys, p.key] : form.data.plan_keys.filter((k) => k !== p.key))} />)}
                                <span className="text-[11.5px] text-text-secondary">None ticked: every paid plan.</span>
                            </div>
                        )}
                        <Check id="dn-first" label="First purchase only" checked={form.data.first_time_only} onChange={(v) => form.setData('first_time_only', v)} />
                    </fieldset>
                </div>
            )}
        />
    );
}
