import Dialog from '@/Components/Dialog';
import { money } from '@/Components/Store/money';
import AdminLayout, { Panel, buttonClass, formatDate, inputClass, usd } from '@/Layouts/AdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Empty, Errors, ReasonAction, Table } from '@/Components/Admin/Kit';

/**
 * One account, everything about it but its words.
 *
 * Counts, never manuscript text: reading a book is what "View as" is for,
 * and that needs a reason and leaves a record. Every action asks for a
 * reason, which goes into the audit log with the change.
 */
export default function Show({
    account,
    credits,
    books,
    exports,
    orders,
    sales,
    logins,
    notes,
    impersonations,
    can,
    impersonation_minutes: minutes,
    ai,
    features,
    offboarding = null,
}) {
    const owner = can.owner;

    return (
        <AdminLayout title={account.name} active="accounts">
            <Link href={route('admin.accounts.index')} className="text-[12.5px] text-brand-primary hover:underline">
                ← Accounts
            </Link>

            <div className="mt-2 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0">
                    <p className="truncate text-[13.5px] text-text-secondary" data-a="admin:account:email">
                        {account.email} · #{account.id}
                    </p>
                    <div className="mt-2 flex flex-wrap gap-1.5 text-[11.5px]">
                        <Badge>{account.plan_name} plan</Badge>
                        {account.complimentary_since && (
                            <Badge tone="success">
                                Complimentary{account.complimentary_until ? ` until ${formatDate(account.complimentary_until, false)}` : ', no end date'}
                            </Badge>
                        )}
                        {account.stripe_subscription && <Badge tone="info">Stripe subscription</Badge>}
                        {account.platform_role && <Badge tone="info">Staff: {account.platform_role}</Badge>}
                        <Badge tone={account.two_factor ? 'success' : 'neutral'}>
                            Two-factor {account.two_factor ? 'on' : 'off'}
                        </Badge>
                        {account.suspended_at && <Badge tone="danger">Suspended</Badge>}
                        {!account.email_verified_at && <Badge tone="warning">Email not verified</Badge>}
                    </div>
                    <p className="mt-2 text-[12px] text-text-muted">Joined {formatDate(account.created_at)}</p>
                </div>

                <ImpersonateButton account={account} refusal={can.impersonate} minutes={minutes} />
            </div>

            {account.suspended_at && (
                <p role="status" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[13px] text-status-danger">
                    Suspended {formatDate(account.suspended_at)}: {account.suspended_reason}
                </p>
            )}

            <div className="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Access" detail="Signing out ends every session on every device.">
                    <div className="flex flex-wrap gap-2">
                        <ReasonAction label="Sign out everywhere" route={route('admin.accounts.sign-out', account.id)} dataA="admin:action:sign-out" />
                        <ReasonAction
                            label="Send password reset"
                            route={route('admin.accounts.password-reset', account.id)}
                            dataA="admin:action:password-reset"
                            noReason
                            confirm={`Send a reset link to ${account.email}?`}
                        />
                        {owner && account.two_factor && (
                            <ReasonAction label="Reset two-factor" route={route('admin.accounts.two-factor-reset', account.id)} dataA="admin:action:2fa-reset" danger />
                        )}
                        {owner &&
                            (account.suspended_at ? (
                                <ReasonAction label="Reactivate" route={route('admin.accounts.reactivate', account.id)} dataA="admin:action:reactivate" />
                            ) : (
                                <ReasonAction
                                    label="Suspend"
                                    route={route('admin.accounts.suspend', account.id)}
                                    dataA="admin:action:suspend"
                                    danger
                                    detail="They are signed out at once and cannot sign in until reactivated. Their books are untouched."
                                />
                            ))}
                        {owner && account.platform_role !== 'owner' && (
                            <ReasonAction
                                label={account.platform_role === 'support' ? 'Remove support role' : 'Make support staff'}
                                route={route('admin.accounts.role', account.id)}
                                dataA="admin:action:role"
                                extra={{ role: account.platform_role === 'support' ? null : 'support' }}
                                danger={account.platform_role !== 'support'}
                                detail="Support staff can read every account, take notes and View as. They must set up two-factor before using the console."
                            />
                        )}
                    </div>
                    {account.sessions_revoked_at && (
                        <p className="mt-3 text-[12px] text-text-muted">Last signed out everywhere {formatDate(account.sessions_revoked_at)}.</p>
                    )}
                </Panel>

                <Panel title="Plan and credits" detail={`${credits.balance.toLocaleString()} credits · ${credits.monthly.toLocaleString()} a month on this plan`}>
                    <div className="flex flex-wrap gap-2">
                        {owner && <PlanAction account={account} />}
                        <CreditsAction account={account} max={can.max_grant} owner={owner} />
                    </div>
                    <Ledger rows={credits.ledger} />
                </Panel>

                <Panel title={`Books (${books.length})`} detail="Counts only: open a book with View as.">
                    {books.length === 0 ? (
                        <Empty>No books.</Empty>
                    ) : (
                        <Table
                            head={['Title', 'Words', 'Sections', 'Versions', 'Exports', 'Updated']}
                            rows={books.map((book) => [
                                <span key="t" className="font-medium text-text-primary">
                                    {book.title}
                                    {book.status === 'archived' && <span className="ml-1 text-text-muted">(archived)</span>}
                                </span>,
                                book.words.toLocaleString(),
                                book.sections,
                                book.versions,
                                book.exports,
                                formatDate(book.updated_at, false),
                            ])}
                        />
                    )}
                </Panel>

                <Panel title="Exports" detail="The 20 most recent.">
                    {exports.length === 0 ? (
                        <Empty>No exports.</Empty>
                    ) : (
                        <Table
                            head={['Book', 'Format', 'Status', 'When']}
                            rows={exports.map((row) => [
                                `#${row.book_id}`,
                                row.format.toUpperCase(),
                                <Badge key="s" tone={row.status === 'failed' ? 'danger' : row.status === 'ready' ? 'success' : 'neutral'}>{row.status}</Badge>,
                                formatDate(row.at),
                            ])}
                        />
                    )}
                </Panel>

                <Panel title="Orders and sales">
                    <p className="mb-2 text-[12.5px] font-semibold text-text-primary">Sold from their storefront</p>
                    {sales.length === 0 ? (
                        <Empty>No sales.</Empty>
                    ) : (
                        <ul className="mb-3 text-[12.5px]">
                            {sales.map((row) => (
                                <li key={row.currency}>
                                    {row.items} sold · {money(row.gross_cents, row.currency)} gross
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="mb-2 mt-3 text-[12.5px] font-semibold text-text-primary">Bought</p>
                    {orders.length === 0 ? (
                        <Empty>No orders.</Empty>
                    ) : (
                        <Table
                            head={['Order', 'Status', 'Total', 'When']}
                            rows={orders.map((order) => [order.number, order.status, money(order.total_cents, order.currency), formatDate(order.at)])}
                        />
                    )}
                </Panel>

                <Panel title="Sign-ins" detail="Password, two-factor and View as. A session restored by remember-me is not counted.">
                    {logins.length === 0 ? (
                        <Empty>No sign-ins recorded.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:logins">
                            {logins.map((login) => (
                                <li key={login.id} className="py-1.5">
                                    <span className={login.label ? 'font-semibold text-status-danger' : 'text-text-primary'}>
                                        {login.label ?? methodLabel(login.method)}
                                    </span>
                                    <span className="text-text-secondary"> · {formatDate(login.at)} · {login.ip ?? 'unknown IP'}{login.country ? ` · ${login.country}` : ''}</span>
                                    {login.new_location && (
                                        <span className="ml-1.5 text-[11px]">
                                            <Badge tone="warning">New location</Badge>
                                        </span>
                                    )}
                                    {login.user_agent && <p className="truncate text-[11.5px] text-text-muted">{login.user_agent}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel
                    title="Offboarding"
                    detail="A copy of their data, or deleting the account: dry run first, seven days' grace."
                    data-a="admin:account:offboarding"
                    actions={
                        <Link href={route('admin.offboarding.show', account.id)} data-a="admin:account:offboarding:open" className="text-[12px] text-brand-primary hover:underline">
                            Open
                        </Link>
                    }
                >
                    {offboarding?.deletion ? (
                        <p className="text-[12.5px] text-text-primary">
                            Deletion {offboarding.deletion.status}
                            {offboarding.deletion.status === 'scheduled' && ` for ${formatDate(offboarding.deletion.scheduled_for)}`}.
                        </p>
                    ) : (
                        <Empty>No deletion requested.</Empty>
                    )}
                    {offboarding?.export && (
                        <p className="mt-1 text-[12px] text-text-secondary">
                            Latest data export: {offboarding.export.status}, {formatDate(offboarding.export.at)}.
                        </p>
                    )}
                </Panel>

                <Notes account={account} notes={notes} />

                <AiPanel account={account} ai={ai} owner={owner} />

                <FeaturesPanel account={account} features={features} owner={owner} />

                <Panel title="View as sessions" actions={<Link href={route('admin.impersonations.index', { target: account.id })} className="text-[12px] text-brand-primary hover:underline">All</Link>}>
                    {impersonations.length === 0 ? (
                        <Empty>Nobody has viewed as this account.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]">
                            {impersonations.map((s) => (
                                <li key={s.id} className="py-1.5">
                                    <span className="font-medium text-text-primary">{s.impersonator}</span>
                                    <span className="text-text-secondary"> · {formatDate(s.started_at)} · {s.end_reason}</span>
                                    <p className="text-text-muted">{s.reason}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}

function methodLabel(method) {
    return (
        {
            password: 'Password',
            two_factor: 'Password and two-factor',
            recovery_code: 'Password and recovery code',
            registration: 'Registered',
            impersonation: 'View as',
        }[method] ?? method
    );
}

function Ledger({ rows }) {
    if (rows.length === 0) return <p className="mt-3 text-[12.5px] text-text-secondary">No ledger rows.</p>;

    return (
        <ul className="mt-3 max-h-[240px] divide-y divide-border-divider overflow-y-auto text-[12.5px]" tabIndex={0} aria-label="Credit ledger" data-a="admin:ledger">
            {rows.map((row) => (
                <li key={row.id} className="flex items-center gap-3 py-1.5">
                    <span className="min-w-0 flex-1 truncate">
                        {row.kind.replace('_', ' ')} · {row.label}
                        {row.by_staff && <span className="text-status-danger"> · during View as</span>}
                    </span>
                    <span className={`tabular-nums ${row.amount < 0 ? 'text-status-danger' : 'text-status-success'}`}>
                        {row.amount > 0 ? '+' : ''}
                        {row.amount.toLocaleString()}
                    </span>
                    <span className="w-[110px] text-right text-text-muted">{formatDate(row.at, false)}</span>
                </li>
            ))}
        </ul>
    );
}

function PlanAction({ account }) {
    return (
        <>
            <ReasonAction
                label={account.complimentary_since ? 'Change complimentary plan' : 'Give a complimentary plan'}
                route={route('admin.accounts.plan', account.id)}
                dataA="admin:action:plan"
                initial={{ action: 'set', plan: account.complimentary_since ? account.plan : 'author', until: account.complimentary_until?.slice(0, 10) ?? '' }}
                detail={
                    account.stripe_subscription
                        ? 'This account pays through Stripe, so a complimentary plan will be refused. Change the plan in Stripe.'
                        : 'No payment is taken. Leave the end date empty for no end; at the end date the account returns to Free.'
                }
            >
                {(form) => (
                    <>
                        <div>
                            <label htmlFor="comp-plan" className="text-[12.5px] font-medium text-text-primary">
                                Plan
                            </label>
                            <select
                                id="comp-plan"
                                data-a="admin:action:plan:plan"
                                value={form.data.plan}
                                onChange={(event) => form.setData('plan', event.target.value)}
                                className={`${inputClass} mt-1`}
                            >
                                <option value="author">Author</option>
                                <option value="studio">Studio</option>
                            </select>
                        </div>
                        <div>
                            <label htmlFor="comp-until" className="text-[12.5px] font-medium text-text-primary">
                                Until (optional)
                            </label>
                            <input
                                id="comp-until"
                                type="date"
                                data-a="admin:action:plan:until"
                                value={form.data.until}
                                onChange={(event) => form.setData('until', event.target.value)}
                                className={`${inputClass} mt-1`}
                            />
                        </div>
                    </>
                )}
            </ReasonAction>
            {account.complimentary_since && (
                <ReasonAction
                    label="End complimentary plan"
                    route={route('admin.accounts.plan', account.id)}
                    dataA="admin:action:plan-clear"
                    extra={{ action: 'clear' }}
                    danger
                    detail="The account returns to Free now."
                />
            )}
        </>
    );
}

function CreditsAction({ account, max, owner }) {
    return (
        <ReasonAction
            label="Adjust credits"
            route={route('admin.accounts.credits', account.id)}
            dataA="admin:action:credits"
            initial={{ amount: '' }}
            detail={
                owner
                    ? `Add with a positive number, take away with a negative one (never below zero). At most ${max.toLocaleString()} at a time.`
                    : `Support can add up to ${max.toLocaleString()} credits at a time. Only the owner can take credits away.`
            }
        >
            {(form) => (
                <div>
                    <label htmlFor="credit-amount" className="text-[12.5px] font-medium text-text-primary">
                        Credits
                    </label>
                    <input
                        id="credit-amount"
                        type="number"
                        inputMode="numeric"
                        data-a="admin:action:credits:amount"
                        required
                        value={form.data.amount}
                        min={owner ? -max : 1}
                        max={max}
                        onChange={(event) => form.setData('amount', event.target.value)}
                        className={`${inputClass} mt-1`}
                    />
                </div>
            )}
        </ReasonAction>
    );
}

function ImpersonateButton({ account, refusal, minutes }) {
    return (
        <div className="shrink-0 md:text-right">
            <ReasonAction
                label="View as this account"
                route={route('admin.accounts.impersonate', account.id)}
                dataA="admin:action:impersonate"
                danger
                detail={`You will see Écrivain exactly as ${account.name} does, for at most ${minutes} minutes. Billing, passwords, publishing, deleting and downloads are refused while you view. They will see "Viewed by Ecrivain support" in their sign-in history.`}
            />
            {refusal && <p className="mt-1 max-w-[280px] text-[11.5px] text-text-muted md:ml-auto">{refusal}</p>}
        </div>
    );
}

function Notes({ account, notes }) {
    const form = useForm({ body: '', pinned: false });

    const add = (event) => {
        event.preventDefault();
        form.post(route('admin.accounts.notes.store', account.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Panel title="Internal notes" detail="Staff only. The account never sees these.">
            <form onSubmit={add} className="space-y-2">
                <label htmlFor="note-body" className="sr-only">
                    New note
                </label>
                <textarea
                    id="note-body"
                    data-a="admin:note:body"
                    rows={2}
                    required
                    value={form.data.body}
                    onChange={(event) => form.setData('body', event.target.value)}
                    placeholder="What happened, what was agreed"
                    className={inputClass}
                />
                <div className="flex items-center justify-between gap-2">
                    <label className="flex items-center gap-2 text-[12.5px] text-text-secondary">
                        <input type="checkbox" checked={form.data.pinned} onChange={(event) => form.setData('pinned', event.target.checked)} />
                        Pin to the top
                    </label>
                    <button type="submit" data-a="admin:note:add" disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                        Add note
                    </button>
                </div>
                <Errors errors={form.errors} />
            </form>
            <ul className="mt-3 divide-y divide-border-divider" data-a="admin:notes">
                {notes.map((note) => (
                    <li key={note.id} className={`py-2 text-[12.5px] ${note.pinned ? 'rounded-tile bg-status-warning-bg px-2' : ''}`}>
                        <p className="whitespace-pre-wrap text-text-body">{note.body}</p>
                        <p className="mt-1 flex flex-wrap gap-x-3 text-[11.5px] text-text-muted">
                            <span>
                                {note.pinned && 'Pinned · '}
                                {note.author ?? 'Former staff'} · {formatDate(note.at)}
                            </span>
                            <button
                                type="button"
                                className="text-brand-primary hover:underline"
                                onClick={() => router.patch(route('admin.accounts.notes.update', [account.id, note.id]), { pinned: !note.pinned }, { preserveScroll: true })}
                            >
                                {note.pinned ? 'Unpin' : 'Pin'}
                            </button>
                            <button
                                type="button"
                                className="text-status-danger hover:underline"
                                onClick={() => {
                                    if (window.confirm('Delete this note? Its text stays in the audit log.')) {
                                        router.delete(route('admin.accounts.notes.destroy', [account.id, note.id]), { preserveScroll: true });
                                    }
                                }}
                            >
                                Delete
                            </button>
                        </p>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** What this account's AI calls cost, and its monthly cap (owner sets it). */
function AiPanel({ account, ai, owner }) {
    if (!ai) return null;

    const d30 = ai.windows.d30;

    return (
        <Panel
            title="AI usage"
            detail={`This month ${usd(ai.spent_this_month)} known cost · cap ${ai.effective_cap === null ? 'none' : usd(ai.effective_cap)}${ai.cap === null && ai.effective_cap !== null ? ' (platform default)' : ''}`}
            data-a="admin:account:ai"
        >
            <p className="text-[12.5px] text-text-body">
                30 days: {d30.calls} calls ({d30.simulated} sample output), {d30.failed} failed, {usd(d30.cost_usd)} known
                {d30.unknown_cost > 0 && <span className="text-status-warning"> · {d30.unknown_cost} with unknown cost</span>}
            </p>
            {ai.by_feature.length > 0 && (
                <div className="mt-2">
                    <Table
                        head={['Feature', 'Calls', 'Failed', 'Cost']}
                        rows={ai.by_feature.map((row) => [row.label, row.calls, row.failed, row.unknown_cost > 0 ? `${usd(row.cost_usd)} + ${row.unknown_cost} unknown` : usd(row.cost_usd)])}
                    />
                </div>
            )}
            {ai.failures.length > 0 && (
                <ul className="mt-2 space-y-1 text-[12px] text-text-secondary">
                    {ai.failures.map((f) => (
                        <li key={f.id} className="truncate">
                            <span className="text-status-danger">Failed</span> {f.feature} · {formatDate(f.at)} · {f.error}
                        </li>
                    ))}
                </ul>
            )}
            {owner && (
                <div className="mt-3">
                    <ReasonAction
                        label={ai.cap === null ? 'Set a monthly AI cap' : 'Change the monthly AI cap'}
                        route={route('admin.accounts.ai-cap', account.id)}
                        dataA="admin:action:ai-cap"
                        detail="AI features stop for this account once its known AI cost this calendar month reaches the cap. Empty removes it."
                        initial={{ cap: ai.cap ?? '' }}
                    >
                        {(form) => (
                            <div>
                                <label htmlFor="ai-cap" className="text-[12.5px] font-medium text-text-primary">
                                    Cap in USD (empty for none)
                                </label>
                                <input
                                    id="ai-cap"
                                    data-a="admin:action:ai-cap:value"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={form.data.cap ?? ''}
                                    onChange={(event) => form.setData('cap', event.target.value)}
                                    className={`${inputClass} mt-1`}
                                />
                            </div>
                        )}
                    </ReasonAction>
                </div>
            )}
        </Panel>
    );
}

/** Each module for this account: what decides it, and an override (owner). */
function FeaturesPanel({ account, features, owner }) {
    if (!features) return null;

    const sources = { override: 'override', global: 'platform switch', default: 'default', unavailable: 'not configured' };

    return (
        <Panel title="Feature flags" detail="An override for this account wins over the platform switch." data-a="admin:account:features">
            <ul className="divide-y divide-border-divider text-[12.5px]">
                {features.map((f) => (
                    <li key={f.key} className="flex flex-wrap items-center gap-2 py-1.5">
                        <span className="min-w-0 flex-1">
                            <span className="font-medium text-text-primary">{f.label}</span>
                            <span className="text-text-secondary"> · {sources[f.source]}</span>
                        </span>
                        <Badge tone={f.enabled ? 'success' : 'neutral'}>{f.enabled ? 'On' : 'Off'}</Badge>
                        {owner && f.available && (
                            <ReasonAction
                                label="Override"
                                route={route('admin.accounts.features', account.id)}
                                dataA={`admin:action:feature:${f.key}`}
                                detail={`${f.label} for ${account.email}. Default hands the decision back to the platform switch.`}
                                initial={{ key: f.key, state: f.override === null ? 'default' : f.override ? 'on' : 'off' }}
                            >
                                {(form) => (
                                    <fieldset>
                                        <legend className="text-[12.5px] font-medium text-text-primary">For this account</legend>
                                        <div className="mt-1 flex gap-3 text-[13px]">
                                            {[['on', 'On'], ['off', 'Off'], ['default', 'Default']].map(([value, text]) => (
                                                <label key={value} className="flex items-center gap-1.5">
                                                    <input type="radio" name={`feature-${f.key}`} value={value} checked={form.data.state === value} onChange={() => form.setData('state', value)} />
                                                    {text}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                )}
                            </ReasonAction>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
