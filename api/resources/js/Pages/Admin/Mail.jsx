import AdminLayout, { buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Pager, ReasonAction } from '@/Components/Admin/Kit';

/**
 * The email log. "Sent" means handed to the mail provider; whether it was
 * delivered, bounced or marked as spam needs the provider's webhook, which
 * is not connected, and the page says so rather than implying delivery.
 */
export default function Mail({ q, status, logs, mailer, delivery_tracking: tracking }) {
    const [query, setQuery] = useState(q ?? '');
    const [open, setOpen] = useState(null);

    const search = (event) => {
        event.preventDefault();
        router.get(route('admin.mail.index'), { ...(query ? { q: query } : {}), ...(status ? { status } : {}) }, { preserveState: true });
    };

    return (
        <AdminLayout title="Email log" active="mail">
            {!tracking && (
                <p className="mb-3 rounded-tile bg-status-info-bg px-3 py-2 text-[12.5px] text-status-info" data-a="admin:mail:no-tracking">
                    Bounces, complaints and suppressions are not shown: the provider reports them through a webhook that is not connected yet. “Sent” means handed to {mailer}, not delivered.
                </p>
            )}

            <form onSubmit={search} className="flex flex-col gap-2 rounded-panel border border-border-default bg-bg-surface p-3 sm:flex-row">
                <label htmlFor="mail-q" className="sr-only">
                    Search by address, subject or email type
                </label>
                <input id="mail-q" data-a="admin:mail:search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Address, subject, email type or message id" className={inputClass} />
                <div className="flex gap-2">
                    {[['', 'All'], ['sent', 'Sent'], ['failed', 'Failed']].map(([value, label]) => (
                        <Link
                            key={value}
                            href={route('admin.mail.index', { ...(q ? { q } : {}), ...(value ? { status: value } : {}) })}
                            aria-current={status === value ? 'page' : undefined}
                            className={`tap rounded-control px-3 py-2 text-[12.5px] ${status === value ? 'bg-bg-inverse text-text-inverse' : 'text-text-secondary hover:bg-bg-subtle'}`}
                        >
                            {label}
                        </Link>
                    ))}
                    <button type="submit" className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                        Search
                    </button>
                </div>
            </form>

            <ul className="mt-4 space-y-2" data-a="admin:mail:list">
                {logs.data.length === 0 && <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">No emails logged.</li>}
                {logs.data.map((log) => (
                    <li key={log.id} className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <Badge tone={log.status === 'failed' ? 'danger' : 'success'}>{log.status}</Badge>
                            <span className="min-w-0 break-all font-semibold text-text-primary">{log.to}</span>
                            <span className="text-text-secondary">{log.mailable ?? 'unknown'}</span>
                            {log.category && <span className="text-text-muted">{log.category}</span>}
                            {log.sensitive && <Badge tone="info">metadata only</Badge>}
                            {log.resent_from_id && <span className="text-text-muted">resend of #{log.resent_from_id}</span>}
                            <span className="ml-auto text-text-muted">{formatDate(log.at)}</span>
                        </div>
                        <p className="mt-1 text-text-body">{log.subject}</p>
                        {log.error && <p className="mt-1 break-words text-status-danger">{log.error}</p>}
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {log.user_id && (
                                <Link href={route('admin.accounts.show', log.user_id)} className="text-brand-primary hover:underline">
                                    Account
                                </Link>
                            )}
                            {log.provider_message_id && <code className="break-all text-[11px] text-text-muted">{log.provider_message_id}</code>}
                            {log.body_text && (
                                <button type="button" onClick={() => setOpen(open === log.id ? null : log.id)} aria-expanded={open === log.id} className="text-brand-primary hover:underline">
                                    {open === log.id ? 'Hide text' : 'Show text'}
                                </button>
                            )}
                            {log.resendable && (
                                <span className="ml-auto">
                                    <ReasonAction label="Resend" route={route('admin.mail.resend', log.id)} dataA={`admin:mail:resend:${log.id}`} detail={`The same email, built again, to ${log.to}. Their email preferences still apply.`} />
                                </span>
                            )}
                        </div>
                        {open === log.id && log.body_text && (
                            <pre className="mt-2 max-h-[320px] overflow-auto whitespace-pre-wrap break-words rounded-tile bg-bg-subtle p-2 text-[11.5px] text-text-body" tabIndex={0}>
                                {log.body_text}
                            </pre>
                        )}
                    </li>
                ))}
            </ul>
            <Pager links={logs.links} />
        </AdminLayout>
    );
}
