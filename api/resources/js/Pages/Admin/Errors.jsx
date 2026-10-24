import AdminLayout, { formatDate } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Pager } from '@/Components/Admin/Kit';

/**
 * Error groups: server exceptions and browser errors, one row per
 * fingerprint. Resolve one when it is fixed — if it comes back it opens
 * again; ignore one that is known and harmless.
 */
export default function Errors({ status, source, groups }) {
    const [open, setOpen] = useState(null);

    const filter = (next) => router.get(route('admin.errors.index'), { status, ...(source ? { source } : {}), ...next }, { preserveState: true });

    const set = (group, next) => router.patch(route('admin.errors.update', group.id), { status: next }, { preserveScroll: true });

    return (
        <AdminLayout title="Errors" active="errors">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filter">
                {['open', 'resolved', 'ignored'].map((value) => (
                    <button key={value} type="button" onClick={() => filter({ status: value })} aria-pressed={status === value} data-a={`admin:errors:status:${value}`} className={`tap rounded-control px-3 py-2 text-[12.5px] capitalize ${status === value ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-surface text-text-secondary hover:text-text-primary'}`}>
                        {value}
                    </button>
                ))}
                <span className="mx-1 w-px bg-border-default" aria-hidden="true" />
                {[['', 'Both'], ['server', 'Server'], ['client', 'Browser']].map(([value, label]) => (
                    <button key={value} type="button" onClick={() => filter({ source: value || undefined })} aria-pressed={source === value} className={`tap rounded-control px-3 py-2 text-[12.5px] ${source === value ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-surface text-text-secondary hover:text-text-primary'}`}>
                        {label}
                    </button>
                ))}
            </div>

            <ul className="mt-4 space-y-2" data-a="admin:errors:list">
                {groups.data.length === 0 && <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">No {status} errors.</li>}
                {groups.data.map((g) => (
                    <li key={g.id} className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <Badge tone={g.source === 'server' ? 'danger' : 'warning'}>{g.source === 'server' ? 'Server' : 'Browser'}</Badge>
                            <code className="break-all font-semibold text-text-primary">{g.type.split('\\').pop()}</code>
                            <span className="tabular-nums text-text-secondary">×{g.count.toLocaleString()}</span>
                            <span className="ml-auto text-text-muted">
                                first {formatDate(g.first_seen_at)} · last {formatDate(g.last_seen_at)}
                            </span>
                        </div>
                        <p className="mt-1 break-words text-text-body">{g.message}</p>
                        <p className="mt-0.5 break-all text-text-muted">
                            {g.location}
                            {g.url && ` · ${g.url}`}
                            {g.user_id && (
                                <>
                                    {' '}·{' '}
                                    <Link href={route('admin.accounts.show', g.user_id)} className="text-brand-primary hover:underline">
                                        account #{g.user_id}
                                    </Link>
                                </>
                            )}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {g.trace && (
                                <button type="button" onClick={() => setOpen(open === g.id ? null : g.id)} aria-expanded={open === g.id} className="text-brand-primary hover:underline">
                                    {open === g.id ? 'Hide stack' : 'Show stack'}
                                </button>
                            )}
                            <span className="ml-auto flex gap-2">
                                {g.status !== 'resolved' && (
                                    <button type="button" data-a={`admin:errors:resolve:${g.id}`} onClick={() => set(g, 'resolved')} className="tap rounded-control border border-border-default px-3 py-1.5 font-semibold text-text-primary hover:bg-bg-subtle">
                                        Resolve
                                    </button>
                                )}
                                {g.status !== 'ignored' && (
                                    <button type="button" onClick={() => set(g, 'ignored')} className="tap rounded-control border border-border-default px-3 py-1.5 text-text-secondary hover:bg-bg-subtle">
                                        Ignore
                                    </button>
                                )}
                                {g.status !== 'open' && (
                                    <button type="button" onClick={() => set(g, 'open')} className="tap rounded-control border border-border-default px-3 py-1.5 text-text-secondary hover:bg-bg-subtle">
                                        Reopen
                                    </button>
                                )}
                            </span>
                        </div>
                        {open === g.id && (
                            <pre className="mt-2 max-h-[360px] overflow-auto whitespace-pre rounded-tile bg-bg-subtle p-2 text-[11px] text-text-body" tabIndex={0}>
                                {g.trace}
                            </pre>
                        )}
                    </li>
                ))}
            </ul>
            <Pager links={groups.links} />
        </AdminLayout>
    );
}
