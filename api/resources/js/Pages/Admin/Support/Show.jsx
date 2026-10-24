import AdminLayout, { Panel, age, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Errors } from '@/Components/Admin/Kit';

/**
 * One ticket: the conversation, the context it arrived with, and the reply
 * box. A reply is emailed and goes in the author's inbox; a note stays
 * between staff. The AI draft fills the box and nothing more — a person
 * reads it, edits it and decides to send it.
 */
export default function SupportShow({ ticket, staff, canned, can_draft: canDraft }) {
    const form = useForm({ body: '', internal: false, ai_drafted: false });
    const [drafting, setDrafting] = useState(false);
    const [draftError, setDraftError] = useState(null);

    const submit = (event) => {
        event.preventDefault();
        form.post(route('admin.support.reply', ticket.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const draft = async () => {
        setDrafting(true);
        setDraftError(null);

        try {
            const { data } = await window.axios.post(route('admin.support.draft', ticket.id));
            form.setData({ ...form.data, body: data.draft, ai_drafted: true, internal: false });
        } catch (error) {
            setDraftError(error.response?.data?.message ?? 'The draft could not be made.');
        } finally {
            setDrafting(false);
        }
    };

    const update = (changes) => router.patch(route('admin.support.update', ticket.id), changes, { preserveScroll: true });

    const context = ticket.context ?? {};

    return (
        <AdminLayout title={ticket.subject} active="support">
            <Link href={route('admin.support.index')} className="text-[12.5px] text-brand-primary hover:underline">
                ← Support
            </Link>

            <div className="mt-2 grid grid-cols-1 gap-4 xl:grid-cols-[1fr_320px]">
                <div className="min-w-0 space-y-3">
                    <ol className="space-y-2" data-a="admin:support:messages">
                        {ticket.messages.map((m) => (
                            <li
                                key={m.id}
                                className={`rounded-panel border px-4 py-3 text-[13px] ${
                                    m.internal ? 'border-status-warning/40 bg-status-warning-bg' : m.from_staff ? 'border-brand-primary/30 bg-brand-primary-subtle' : 'border-border-default bg-bg-surface'
                                }`}
                            >
                                <p className="text-[12px] text-text-secondary">
                                    <span className="font-semibold text-text-primary">{m.from_staff ? m.author ?? 'Staff' : ticket.user?.name ?? 'Author'}</span>
                                    {m.internal && ' · internal note'}
                                    {m.ai_drafted && ' · from an AI draft'} · {formatDate(m.at)}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap break-words text-text-body">{m.body}</p>
                            </li>
                        ))}
                    </ol>

                    <Panel title={form.data.internal ? 'Internal note' : 'Reply'} detail={form.data.internal ? 'Only staff see notes. Nobody is emailed.' : `Emailed to ${ticket.user?.email ?? 'the author'} and added to their inbox.`}>
                        <form onSubmit={submit}>
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                {canned.length > 0 && (
                                    <>
                                        <label htmlFor="canned" className="sr-only">
                                            Start from a canned reply
                                        </label>
                                        <select
                                            id="canned"
                                            data-a="admin:support:canned"
                                            value=""
                                            onChange={(event) => {
                                                const reply = canned.find((c) => String(c.id) === event.target.value);
                                                if (reply) form.setData('body', form.data.body ? `${form.data.body}\n\n${reply.body}` : reply.body);
                                            }}
                                            className={`${inputClass} max-w-[240px]`}
                                        >
                                            <option value="">Canned reply…</option>
                                            {canned.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.title}
                                                </option>
                                            ))}
                                        </select>
                                    </>
                                )}
                                {canDraft && (
                                    <button type="button" data-a="admin:support:draft" onClick={draft} disabled={drafting} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                                        {drafting ? 'Drafting…' : 'AI draft reply'}
                                    </button>
                                )}
                                <label className="ml-auto flex items-center gap-2 text-[12.5px] text-text-primary">
                                    <input type="checkbox" data-a="admin:support:internal" checked={form.data.internal} onChange={(event) => form.setData('internal', event.target.checked)} />
                                    Internal note
                                </label>
                            </div>
                            {draftError && (
                                <p role="alert" className="mb-2 text-[12px] text-status-danger">
                                    {draftError}
                                </p>
                            )}
                            {form.data.ai_drafted && <p className="mb-1 text-[12px] text-status-info">AI draft: read it and change what is not right before sending.</p>}
                            <label htmlFor="reply-body" className="sr-only">
                                Message
                            </label>
                            <textarea id="reply-body" data-a="admin:support:body" rows={7} required value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} className={inputClass} />
                            <Errors errors={form.errors} />
                            <div className="mt-2 flex justify-end">
                                <button type="submit" data-a="admin:support:send" disabled={form.processing || !form.data.body.trim()} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                    {form.data.internal ? 'Add note' : 'Send reply'}
                                </button>
                            </div>
                        </form>
                    </Panel>
                </div>

                <aside className="space-y-3">
                    <Panel title="Ticket">
                        <dl className="space-y-2 text-[12.5px]">
                            <div>
                                <dt className="text-text-secondary">Status</dt>
                                <dd className="mt-0.5">
                                    <label htmlFor="ticket-status" className="sr-only">
                                        Status
                                    </label>
                                    <select id="ticket-status" data-a="admin:support:status" value={ticket.status} onChange={(event) => update({ status: event.target.value })} className={inputClass}>
                                        <option value="open">Open (waiting for staff)</option>
                                        <option value="pending">Pending (waiting for the author)</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-text-secondary">Assigned to</dt>
                                <dd className="mt-0.5">
                                    <label htmlFor="ticket-assignee" className="sr-only">
                                        Assigned to
                                    </label>
                                    <select id="ticket-assignee" data-a="admin:support:assignee" value={ticket.assignee_id ?? ''} onChange={(event) => update({ assignee_id: event.target.value === '' ? null : Number(event.target.value) })} className={inputClass}>
                                        <option value="">Nobody</option>
                                        {staff.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-text-secondary">Waiting</dt>
                                <dd>
                                    {age(ticket.last_message_at)} since the last message · opened {formatDate(ticket.created_at)}
                                </dd>
                            </div>
                        </dl>
                    </Panel>

                    <Panel title="Author" detail="Their words and context; their books only through View as.">
                        {ticket.user ? (
                            <p className="text-[12.5px]">
                                <Link href={route('admin.accounts.show', ticket.user.id)} className="font-medium text-brand-primary hover:underline">
                                    {ticket.user.name}
                                </Link>
                                <span className="block break-all text-text-secondary">{ticket.user.email}</span>
                            </p>
                        ) : (
                            <p className="text-[12.5px] text-text-secondary">Account deleted.</p>
                        )}
                        <dl className="mt-2 space-y-1 text-[12px]" data-a="admin:support:context">
                            {ticket.book && <Fact label="Book">{ticket.book.title}</Fact>}
                            <Fact label="Plan">{context.plan}</Fact>
                            <Fact label="Browser">{context.user_agent}</Fact>
                            <Fact label="Screen">{context.viewport}</Fact>
                            <Fact label="Page">{context.page}</Fact>
                            <Fact label="Last browser error">{context.last_error}</Fact>
                            <Fact label="Last server error">{context.last_server_error}</Fact>
                        </dl>
                    </Panel>
                </aside>
            </div>
        </AdminLayout>
    );
}

function Fact({ label, children }) {
    if (!children) return null;

    return (
        <div>
            <dt className="text-text-muted">{label}</dt>
            <dd className="break-words text-text-body">{children}</dd>
        </div>
    );
}
