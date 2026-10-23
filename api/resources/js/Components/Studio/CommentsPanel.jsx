import { useCallback, useEffect, useRef, useState } from 'react';
import { onComments, publishComments } from './commentsBus';
import Icon from './Icon';

/**
 * Comment threads across the book.
 *
 * Anchored by a copied excerpt, like editorial findings. A thread whose
 * passage has been edited away is shown as detached rather than left pointing
 * at whatever now occupies that position.
 *
 * Above the threads, the open chapter's suggested changes (Suggest mode)
 * when there are any: reviewing a chapter is one list, the way a word
 * processor keeps suggestions and comments in the same margin.
 */
export default function CommentsPanel({ bookId, sectionId, onOpenSection, onChanged, changes = [], onDecide = null, onShowChange = null }) {
    const [data, setData] = useState(null);
    const [filter, setFilter] = useState('open');
    const [draft, setDraft] = useState('');
    const [replyTo, setReplyTo] = useState(null);
    const [replyBody, setReplyBody] = useState('');
    const [busy, setBusy] = useState(false);
    const changedRef = useRef(onChanged);
    changedRef.current = onChanged;

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/comments`);
        setData(response.data);
        onChanged?.(response.data.counts);
    }, [bookId, onChanged]);

    useEffect(() => {
        load();
    }, [load]);

    // A comment made from the manuscript's selection bubble, or a reply in
    // its thread sheet, lands here too.
    useEffect(() => onComments((next) => {
        setData(next);
        changedRef.current?.(next.counts);
    }), []);

    /** Take a fresh thread list and tell the manuscript about it. */
    const take = (next) => {
        setData(next);
        onChanged?.(next.counts);
        publishComments(next);
    };

    const post = async (event) => {
        event.preventDefault();

        if (!draft.trim() || !sectionId) return;

        setBusy(true);

        try {
            // The selected text becomes the anchor. Without a selection the
            // comment is about the chapter rather than a passage, which is a
            // real thing to want.
            const selection = window.getSelection()?.toString().trim();

            const response = await window.axios.post(`/sections/${sectionId}/comments`, {
                body: draft,
                excerpt: selection && selection.length <= 2000 ? selection : null,
            });

            take(response.data);
            setDraft('');
        } finally {
            setBusy(false);
        }
    };

    const reply = async (thread) => {
        if (!replyBody.trim()) return;

        const response = await window.axios.post(
            `/sections/${thread.section_id}/comments`,
            { body: replyBody, parent_id: thread.id },
        );

        take(response.data);
        setReplyTo(null);
        setReplyBody('');
    };

    const setStatus = async (thread, status) => {
        const response = await window.axios.patch(`/comments/${thread.id}`, { status });
        take(response.data);
    };

    const remove = async (comment) => {
        const response = await window.axios.delete(`/comments/${comment.id}`);
        take(response.data);
    };

    if (!data) {
        return <p className="p-4 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const threads = data.threads.filter((thread) =>
        filter === 'all' ? true : thread.status === filter,
    );

    return (
        <div className="p-3.5">
            {changes.length > 0 && onDecide && (
                <ChangesList changes={changes} onDecide={onDecide} onShow={onShowChange} />
            )}

            <div className="mb-3 flex gap-1.5">
                {[
                    { key: 'open', label: 'Open', count: data.counts.open },
                    { key: 'resolved', label: 'Resolved', count: data.counts.resolved },
                    { key: 'all', label: 'All', count: data.counts.open + data.counts.resolved },
                ].map((tab) => (
                    <button
                        key={tab.key}
                        data-a={`comments:filter:${tab.key}`}
                        onClick={() => setFilter(tab.key)}
                        className={`rounded-badge px-2.5 py-1 text-[11.5px] font-medium transition max-sm:px-3 max-sm:py-2 max-sm:text-[12.5px] ${
                            filter === tab.key
                                ? 'bg-brand-fill text-white'
                                : 'text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {tab.label} <span>{tab.count}</span>
                    </button>
                ))}
            </div>

            <form onSubmit={post} className="mb-4">
                <textarea
                    value={draft}
                    rows={3}
                    data-a="comments:draft"
                    disabled={!sectionId}
                    placeholder={
                        sectionId
                            ? 'Select a passage, then write a comment about it.'
                            : 'Open a chapter to comment.'
                    }
                    onChange={(event) => setDraft(event.target.value)}
                    className="w-full rounded-control border border-border-input p-2.5 text-[12.5px] placeholder:text-text-secondary focus:border-brand-primary focus:ring-0 disabled:bg-bg-subtle"
                />
                <button
                    disabled={busy || !draft.trim() || !sectionId}
                    data-a="comments:post"
                    className="mt-2 h-[32px] w-full rounded-control bg-brand-fill text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy ? 'Posting…' : 'Comment'}
                </button>
            </form>

            {threads.length === 0 ? (
                <p className="px-1 text-[11.5px] leading-relaxed text-text-secondary">
                    {filter === 'open'
                        ? 'No open comments.'
                        : 'Nothing under this filter.'}
                </p>
            ) : (
                <ul className="space-y-2.5">
                    {threads.map((thread) => (
                        <li
                            key={thread.id}
                            className="rounded-tile border border-border-default bg-bg-surface p-2.5"
                        >
                            <div className="flex items-baseline justify-between gap-2">
                                <span className="text-[12px] font-semibold text-text-primary">
                                    {thread.author_name}
                                </span>
                                <button
                                    data-a={`comments:open:${thread.section_id}`}
                                    onClick={() => onOpenSection?.(thread.section_id)}
                                    className="shrink-0 text-[11px] text-brand-primary hover:underline"
                                >
                                    {thread.section_title}
                                </button>
                            </div>

                            {thread.excerpt && (
                                <p className="mt-1.5 border-l-2 border-highlight-ai-underline pl-2 font-manuscript text-[12px] italic leading-snug text-text-secondary">
                                    {thread.excerpt}
                                </p>
                            )}

                            {thread.detached && (
                                <p className="mt-1.5 rounded bg-status-warning-bg px-1.5 py-1 text-[10.5px] font-semibold text-status-warning">
                                    The passage this refers to has been edited away.
                                </p>
                            )}

                            <p className="mt-1.5 text-[12.5px] leading-relaxed text-text-body">
                                {thread.body}
                            </p>

                            {thread.replies.length > 0 && (
                                <ul className="mt-2 space-y-1.5 border-l-2 border-border-default pl-2.5">
                                    {thread.replies.map((item) => (
                                        <li key={item.id}>
                                            <p className="text-[11.5px] font-semibold text-text-primary">
                                                {item.author_name}
                                                <span className="ml-1.5 font-normal text-text-secondary">
                                                    {item.created_at}
                                                </span>
                                            </p>
                                            <p className="text-[12px] leading-relaxed text-text-body">
                                                {item.body}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {replyTo === thread.id ? (
                                <div className="mt-2">
                                    <textarea
                                        value={replyBody}
                                        rows={2}
                                        autoFocus
                                        data-a={`comments:reply-body:${thread.id}`}
                                        onChange={(event) => setReplyBody(event.target.value)}
                                        className="w-full rounded-control border border-border-input p-2 text-[12px] focus:border-brand-primary focus:ring-0"
                                    />
                                    <div className="mt-1.5 flex gap-2">
                                        <button
                                            data-a={`comments:reply-send:${thread.id}`}
                                            onClick={() => reply(thread)}
                                            className="rounded-control bg-brand-fill px-2.5 py-1 text-[11.5px] font-semibold text-white"
                                        >
                                            Reply
                                        </button>
                                        <button
                                            data-a={`comments:reply-cancel:${thread.id}`}
                                            onClick={() => setReplyTo(null)}
                                            className="text-[11.5px] text-text-secondary"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    <span className="text-[10.5px] text-text-secondary">
                                        {thread.created_at}
                                    </span>
                                    <button
                                        data-a={`comments:reply:${thread.id}`}
                                        onClick={() => setReplyTo(thread.id)}
                                        className="text-[11.5px] font-medium text-brand-primary hover:underline"
                                    >
                                        Reply
                                    </button>
                                    <button
                                        data-a={`comments:resolve:${thread.id}`}
                                        onClick={() =>
                                            setStatus(
                                                thread,
                                                thread.status === 'open' ? 'resolved' : 'open',
                                            )
                                        }
                                        className="text-[11.5px] font-medium text-text-secondary hover:text-text-primary"
                                    >
                                        {thread.status === 'open' ? 'Resolve' : 'Reopen'}
                                    </button>
                                    <button
                                        data-a={`comments:delete:${thread.id}`}
                                        onClick={() => remove(thread)}
                                        title="Delete this thread"
                                        className="ml-auto text-text-secondary hover:text-status-danger"
                                    >
                                        <Icon name="x" size={13} />
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** "2 min ago" from an ISO time, or nothing for a change without one. */
export function ago(iso) {
    if (!iso) return '';

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`;

    return new Date(iso).toLocaleDateString();
}

/** What a change does, in a line: the struck words, then the new ones. */
export function ChangeText({ change }) {
    const clip = (text) => (text.length > 90 ? `${text.slice(0, 90)}…` : text);

    return (
        <span className="font-manuscript text-[12.5px] leading-snug">
            {change.deleted && (
                <del className="text-status-danger">{clip(change.deleted)}</del>
            )}
            {change.deleted && change.inserted && ' '}
            {change.inserted && (
                <ins className="text-status-success underline decoration-2 underline-offset-2">{clip(change.inserted)}</ins>
            )}
        </span>
    );
}

/**
 * The open chapter's suggested changes, each with Accept and Reject, and
 * both for all of them. Deciding is the editor's job (a document transform,
 * then the ordinary save); this list only asks for it.
 */
function ChangesList({ changes, onDecide, onShow }) {
    return (
        <section data-a="changes:list" aria-label="Suggested changes" className="mb-4 rounded-tile border border-border-default bg-bg-surface">
            <div className="flex flex-wrap items-center gap-2 border-b border-border-divider px-2.5 py-2">
                <h3 className="mr-auto text-[12.5px] font-semibold text-text-primary">
                    Suggested changes <span className="font-normal text-text-secondary">({changes.length})</span>
                </h3>
                <button
                    type="button"
                    data-a="changes:accept-all"
                    onClick={() => onDecide(null, true)}
                    className="tap rounded-control bg-brand-fill px-2.5 py-1 text-[11.5px] font-semibold text-white hover:bg-brand-primary-hover"
                >
                    Accept all
                </button>
                <button
                    type="button"
                    data-a="changes:reject-all"
                    onClick={() => onDecide(null, false)}
                    className="tap rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-semibold text-text-primary hover:bg-bg-subtle"
                >
                    Reject all
                </button>
            </div>
            <ul className="divide-y divide-border-divider">
                {changes.map((change) => (
                    <li key={change.id} data-a={`changes:item:${change.id}`} className="flex items-start gap-1.5 px-2.5 py-2">
                        <button
                            type="button"
                            data-a={`changes:show:${change.id}`}
                            onClick={() => onShow?.(change)}
                            className="min-w-0 flex-1 text-left"
                            title="Show in the text"
                        >
                            <span className="block text-[11px] text-text-secondary">
                                <span className="font-semibold text-text-primary">{change.author}</span>
                                {' · '}
                                {change.kind === 'replace' ? 'replaced' : change.kind === 'insert' ? 'inserted' : 'deleted'}
                                {change.at ? ` · ${ago(change.at)}` : ''}
                            </span>
                            <ChangeText change={change} />
                        </button>
                        <button
                            type="button"
                            data-a={`changes:accept:${change.id}`}
                            onClick={() => onDecide([change.id], true)}
                            aria-label={`Accept change by ${change.author}`}
                            title="Accept"
                            className="tap grid h-8 w-8 shrink-0 place-items-center rounded-control text-status-success hover:bg-status-success-bg"
                        >
                            <Icon name="check" size={15} strokeWidth={2.2} />
                        </button>
                        <button
                            type="button"
                            data-a={`changes:reject:${change.id}`}
                            onClick={() => onDecide([change.id], false)}
                            aria-label={`Reject change by ${change.author}`}
                            title="Reject"
                            className="tap grid h-8 w-8 shrink-0 place-items-center rounded-control text-status-danger hover:bg-status-danger-bg"
                        >
                            <Icon name="x" size={14} strokeWidth={2.2} />
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}
