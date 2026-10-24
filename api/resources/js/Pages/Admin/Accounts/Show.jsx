import Dialog from '@/Components/Dialog';
import { money } from '@/Components/Store/money';
import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from './Index';

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
                                    <span className="text-text-secondary"> · {formatDate(login.at)} · {login.ip ?? 'unknown IP'}</span>
                                    {login.user_agent && <p className="truncate text-[11.5px] text-text-muted">{login.user_agent}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Notes account={account} notes={notes} />

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

function Empty({ children }) {
    return <p className="text-[12.5px] text-text-secondary">{children}</p>;
}

function Table({ head, rows }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[360px] text-left text-[12.5px]">
                <thead className="text-text-secondary">
                    <tr>
                        {head.map((cell) => (
                            <th key={cell} className="py-1.5 pr-3 font-medium">
                                {cell}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="tabular-nums">
                    {rows.map((row, index) => (
                        <tr key={index} className="border-t border-border-divider">
                            {row.map((cell, i) => (
                                <td key={i} className="py-1.5 pr-3">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
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

/**
 * A button that opens a dialog asking for a reason, then posts.
 * `extra` adds fields; `children` renders more inputs bound to the form.
 */
function ReasonAction({ label, route: url, dataA, danger = false, detail = null, extra = {}, noReason = false, confirm = null, children = null, initial = {} }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '', ...extra, ...initial });

    const submit = (event) => {
        event.preventDefault();
        // transform() returns nothing in Inertia's useForm: set, then post.
        form.transform((data) => ({ ...data, ...extra }));
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <>
            <button
                type="button"
                data-a={dataA}
                onClick={() => setOpen(true)}
                className={`${buttonClass} border ${
                    danger
                        ? 'border-status-danger/40 text-status-danger hover:bg-status-danger-bg'
                        : 'border-border-default text-text-primary hover:bg-bg-subtle'
                }`}
            >
                {label}
            </button>
            {open && (
                <Dialog label={label} onClose={() => setOpen(false)} className="w-[460px] rounded-panel bg-bg-surface p-5">
                    <form onSubmit={submit} className="text-left">
                        <h2 className="text-[16px] font-bold text-text-primary">{label}</h2>
                        {(detail || confirm) && <p className="mt-1 text-[12.5px] text-text-secondary">{detail ?? confirm}</p>}
                        {children && <div className="mt-3 space-y-3">{children(form)}</div>}
                        {!noReason && (
                            <div className="mt-3">
                                <label htmlFor={`${dataA}-reason`} className="text-[12.5px] font-medium text-text-primary">
                                    Reason (kept in the audit log)
                                </label>
                                <textarea
                                    id={`${dataA}-reason`}
                                    data-a={`${dataA}:reason`}
                                    data-autofocus
                                    required
                                    minLength={4}
                                    rows={3}
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                    className={`${inputClass} mt-1`}
                                />
                            </div>
                        )}
                        <Errors errors={form.errors} />
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setOpen(false)} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                data-a={`${dataA}:confirm`}
                                disabled={form.processing}
                                className={`${buttonClass} text-white ${danger ? 'bg-status-danger-fill' : 'bg-brand-fill hover:bg-brand-primary-hover'}`}
                            >
                                {label}
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}
        </>
    );
}

function Errors({ errors }) {
    const list = Object.values(errors ?? {});

    if (list.length === 0) return null;

    return (
        <div role="alert" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[12.5px] text-status-danger" data-a="admin:error">
            {list.map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
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
