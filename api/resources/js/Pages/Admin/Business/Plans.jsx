import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Table } from '@/Components/Admin/Kit';
import { Check, Field, FormDialog, PaymentsNotice, inputClass, money } from '@/Components/Admin/BusinessKit';

/**
 * The plan catalogue. What every limit in the product reads (credits, books,
 * seats, formats, specialist tier), now edited here instead of in
 * config/billing.php. A new price is a new Stripe price: people already
 * subscribed stay on theirs, which is why each plan shows its price history.
 */
export default function Plans({ plans, formats, tiers, payments, can_edit: canEdit }) {
    return (
        <AdminLayout
            title="Plans"
            active="plans"
            actions={canEdit ? <PlanForm formats={formats} tiers={tiers} /> : null}
        >
            {canEdit && <PaymentsNotice payments={payments} />}
            {!canEdit && <p className="mb-4 text-[12.5px] text-text-secondary">Only the platform owner can change plans.</p>}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2" data-a="admin:plans">
                {plans.map((plan) => (
                    <Panel
                        key={plan.key}
                        title={<span className="flex flex-wrap items-center gap-2">{plan.name} <span className="text-[11.5px] font-normal"><Badge tone={plan.visibility === 'public' ? 'success' : plan.visibility === 'legacy' ? 'warning' : 'neutral'}>{plan.visibility}</Badge></span></span>}
                        detail={`${plan.key} · ${plan.accounts.toLocaleString()} account${plan.accounts === 1 ? '' : 's'} on it`}
                        actions={canEdit ? <PlanForm plan={plan} formats={formats} tiers={tiers} /> : null}
                        data-a={`admin:plan:${plan.key}`}
                    >
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-[12.5px] sm:grid-cols-3">
                            <Fact label="Price">{plan.price_cents === 0 ? 'Free' : `${money(plan.price_cents, plan.currency)} / month`}</Fact>
                            <Fact label="Credits a month">{plan.credits.toLocaleString()}</Fact>
                            <Fact label="Books">{plan.books ?? 'Unlimited'}</Fact>
                            <Fact label="Seats">{plan.seats ?? 'Unlimited'}</Fact>
                            <Fact label="Specialist tier">{plan.tier}</Fact>
                            <Fact label="Formats">{plan.formats.map((f) => f.toUpperCase()).join(', ') || 'None'}</Fact>
                        </dl>
                        <h3 className="mt-4 text-[12px] font-semibold text-text-secondary">Price history</h3>
                        {plan.prices.length === 0 ? (
                            <Empty>No prices recorded.</Empty>
                        ) : (
                            <Table
                                head={['Amount', 'Stripe price', 'From', 'Until']}
                                rows={plan.prices.map((p) => [
                                    money(p.amount_cents, p.currency),
                                    p.stripe_price_id ? <code key="c" className="text-[11px]">{p.stripe_price_id}</code> : <span key="c" className="text-text-muted">none</span>,
                                    formatDate(p.active_from, false),
                                    p.retired_at ? formatDate(p.retired_at, false) : 'current',
                                ])}
                            />
                        )}
                    </Panel>
                ))}
            </div>

            <Panel className="mt-4" title="What each specialist tier runs" detail="From the editorial agents themselves; a plan's tier unlocks every agent at or below it.">
                <ul className="space-y-1 text-[12.5px]">
                    {tiers.map((t) => (
                        <li key={t.tier}><span className="font-semibold">Tier {t.tier}:</span> {t.agents.join(', ')}</li>
                    ))}
                </ul>
            </Panel>
        </AdminLayout>
    );
}

function Fact({ label, children }) {
    return (
        <div className="min-w-0">
            <dt className="text-text-secondary">{label}</dt>
            <dd className="truncate font-medium text-text-primary">{children}</dd>
        </div>
    );
}

function PlanForm({ plan = null, formats, tiers }) {
    const editing = plan !== null;
    const id = editing ? `plan-${plan.key}` : 'plan-new';
    const maxTier = Math.max(0, ...tiers.map((t) => t.tier));

    return (
        <FormDialog
            label={editing ? 'Edit' : 'New plan'}
            title={editing ? `Edit ${plan.name}` : 'New plan'}
            dataA={editing ? `admin:plan:${plan.key}:edit` : 'admin:plan:new'}
            url={editing ? route('admin.plans.update', plan.id) : route('admin.plans.store')}
            method={editing ? 'patch' : 'post'}
            wide
            detail={editing ? 'A new price creates a new Stripe price for new subscribers; people already subscribed keep theirs. Limits apply to everybody on the plan at once.' : 'The key is permanent: accounts store it.'}
            initial={{
                key: '',
                name: plan?.name ?? '',
                price: plan ? plan.price_cents / 100 : 0,
                credits: plan?.credits ?? 0,
                books: plan?.books ?? '',
                seats: plan?.seats ?? '',
                formats: plan?.formats ?? ['pdf', 'epub'],
                tier: plan?.tier ?? 0,
                visibility: plan?.visibility ?? 'hidden',
            }}
            fields={(form) => (
                <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                    {!editing && (
                        <Field label="Key" id={`${id}-key`} hint="Lower case letters, digits and _.">
                            <input id={`${id}-key`} required pattern="[a-z][a-z0-9_]{1,31}" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} className={inputClass} />
                        </Field>
                    )}
                    <Field label="Name" id={`${id}-name`}>
                        <input id={`${id}-name`} data-a={`${id}:name`} required maxLength={60} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Price a month (USD)" id={`${id}-price`} hint={plan?.key === 'free' ? 'Free stays at 0.' : null}>
                        <input id={`${id}-price`} data-a={`${id}:price`} type="number" min="0" step="0.01" required disabled={plan?.key === 'free'} value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Credits a month" id={`${id}-credits`}>
                        <input id={`${id}-credits`} data-a={`${id}:credits`} type="number" min="0" required value={form.data.credits} onChange={(e) => form.setData('credits', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Books (empty: unlimited)" id={`${id}-books`}>
                        <input id={`${id}-books`} type="number" min="1" value={form.data.books ?? ''} onChange={(e) => form.setData('books', e.target.value === '' ? null : e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Seats (empty: unlimited)" id={`${id}-seats`}>
                        <input id={`${id}-seats`} type="number" min="1" value={form.data.seats ?? ''} onChange={(e) => form.setData('seats', e.target.value === '' ? null : e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Specialist tier" id={`${id}-tier`}>
                        <select id={`${id}-tier`} value={form.data.tier} onChange={(e) => form.setData('tier', Number(e.target.value))} className={inputClass}>
                            {Array.from({ length: maxTier + 1 }, (_, t) => <option key={t} value={t}>Tier {t}</option>)}
                        </select>
                    </Field>
                    <Field label="Visibility" id={`${id}-visibility`} hint="Hidden: not offered, can be given. Legacy: kept for the people on it.">
                        <select id={`${id}-visibility`} data-a={`${id}:visibility`} disabled={plan?.key === 'free'} value={form.data.visibility} onChange={(e) => form.setData('visibility', e.target.value)} className={inputClass}>
                            <option value="public">Public</option>
                            <option value="hidden">Hidden</option>
                            <option value="legacy">Legacy</option>
                        </select>
                    </Field>
                    <fieldset className="mt-3 sm:col-span-2">
                        <legend className="text-[12.5px] font-medium text-text-primary">Export formats</legend>
                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                            {formats.map((f) => (
                                <Check
                                    key={f.key}
                                    id={`${id}-format-${f.key}`}
                                    label={f.label}
                                    checked={form.data.formats.includes(f.key)}
                                    onChange={(on) => form.setData('formats', on ? [...form.data.formats, f.key] : form.data.formats.filter((x) => x !== f.key))}
                                />
                            ))}
                        </div>
                    </fieldset>
                </div>
            )}
        />
    );
}
