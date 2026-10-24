import AdminLayout, { Panel, age, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Errors, Pager } from '@/Components/Admin/Kit';

/**
 * The support inbox, oldest waiting first: open tickets wait for staff,
 * pending ones for the author. Age is how long since the last message.
 */
const TABS = [
    ['open', 'Open'],
    ['pending', 'Waiting for author'],
    ['closed', 'Closed'],
];

export default function SupportIndex({ status, mine, counts, tickets, canned, can }) {
    return (
        <AdminLayout title="Support" active="support">
            <nav aria-label="Ticket status" className="flex flex-wrap gap-2">
                {TABS.map(([value, label]) => (
                    <Link
                        key={value}
                        href={route('admin.support.index', { status: value, ...(mine ? { mine: 1 } : {}) })}
                        aria-current={status === value ? 'page' : undefined}
                        data-a={`admin:support:tab:${value}`}
                        className={`tap rounded-control px-3 py-2 text-[12.5px] ${status === value ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-surface text-text-secondary hover:text-text-primary'}`}
                    >
                        {label} ({counts[value] ?? 0})
                    </Link>
                ))}
                <Link
                    href={route('admin.support.index', { status, ...(mine ? {} : { mine: 1 }) })}
                    aria-pressed={mine}
                    className={`tap ml-auto rounded-control px-3 py-2 text-[12.5px] ${mine ? 'bg-brand-primary-subtle text-brand-primary' : 'bg-bg-surface text-text-secondary hover:text-text-primary'}`}
                >
                    Assigned to me
                </Link>
            </nav>

            <ul className="mt-4 space-y-2" data-a="admin:support:list">
                {tickets.data.length === 0 && <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">No tickets here.</li>}
                {tickets.data.map((t) => (
                    <li key={t.id}>
                        <Link
                            href={route('admin.support.show', t.id)}
                            data-a={`admin:support:ticket:${t.id}`}
                            className="block rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px] transition hover:border-brand-primary"
                        >
                            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span className="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-text-primary">{t.subject}</span>
                                <Badge tone={t.status === 'open' ? 'warning' : t.status === 'pending' ? 'info' : 'neutral'}>{t.status}</Badge>
                                <span className={`tabular-nums ${t.status === 'open' && ageHours(t.last_message_at) >= 24 ? 'font-semibold text-status-danger' : 'text-text-secondary'}`} title={formatDate(t.last_message_at)}>
                                    {age(t.last_message_at)}
                                </span>
                            </div>
                            <p className="mt-0.5 truncate text-text-secondary">
                                #{t.id} · {t.user?.email ?? 'deleted account'} · {t.messages} message{t.messages === 1 ? '' : 's'}
                                {t.assignee && ` · ${t.assignee}`}
                            </p>
                        </Link>
                    </li>
                ))}
            </ul>
            <Pager links={tickets.links} />

            <CannedReplies canned={canned} owner={can.owner} />
        </AdminLayout>
    );
}

function ageHours(iso) {
    return iso ? (Date.now() - new Date(iso).getTime()) / 3_600_000 : 0;
}

/** The replies staff start from. Everyone uses them; the owner keeps them. */
function CannedReplies({ canned, owner }) {
    const [editing, setEditing] = useState(null);
    const form = useForm({ title: '', body: '' });

    const start = (reply) => {
        setEditing(reply?.id ?? 'new');
        form.setData({ title: reply?.title ?? '', body: reply?.body ?? '' });
        form.clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        editing === 'new' ? form.post(route('admin.support.canned.store'), done) : form.patch(route('admin.support.canned.update', editing), done);
    };

    return (
        <Panel
            title="Canned replies"
            detail={owner ? 'Staff pick these in the reply box.' : 'Staff pick these in the reply box. Only the owner changes them.'}
            className="mt-6"
            data-a="admin:support:canned-list"
            actions={
                owner && editing === null ? (
                    <button type="button" data-a="admin:support:canned:new" onClick={() => start(null)} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                        New
                    </button>
                ) : null
            }
        >
            {editing !== null && (
                <form onSubmit={submit} className="mb-3 space-y-2">
                    <label htmlFor="canned-title" className="text-[12.5px] font-medium text-text-primary">
                        Title
                    </label>
                    <input id="canned-title" data-a="admin:support:canned:title" required maxLength={120} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className={inputClass} />
                    <label htmlFor="canned-body" className="text-[12.5px] font-medium text-text-primary">
                        Reply
                    </label>
                    <textarea id="canned-body" data-a="admin:support:canned:body" required rows={4} maxLength={5000} value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} className={inputClass} />
                    <Errors errors={form.errors} />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setEditing(null)} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>
                            Cancel
                        </button>
                        <button type="submit" data-a="admin:support:canned:save" disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                            Save
                        </button>
                    </div>
                </form>
            )}
            {canned.length === 0 ? (
                <p className="text-[12.5px] text-text-secondary">None yet.</p>
            ) : (
                <ul className="divide-y divide-border-divider text-[12.5px]">
                    {canned.map((reply) => (
                        <li key={reply.id} className="flex flex-wrap items-start gap-2 py-2">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-text-primary">{reply.title}</p>
                                <p className="line-clamp-2 whitespace-pre-wrap text-text-secondary">{reply.body}</p>
                            </div>
                            {owner && (
                                <span className="flex gap-2">
                                    <button type="button" onClick={() => start(reply)} className="tap text-brand-primary hover:underline">
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => window.confirm(`Delete “${reply.title}”?`) && router.delete(route('admin.support.canned.destroy', reply.id), { preserveScroll: true })}
                                        className="tap text-status-danger hover:underline"
                                    >
                                        Delete
                                    </button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
