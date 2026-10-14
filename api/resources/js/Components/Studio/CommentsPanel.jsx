import { useCallback, useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * Comment threads across the book.
 *
 * Anchored by a copied excerpt, like editorial findings. A thread whose
 * passage has been edited away is shown as detached rather than left pointing
 * at whatever now occupies that position.
 */
export default function CommentsPanel({ bookId, sectionId, onOpenSection, onChanged }) {
    const [data, setData] = useState(null);
    const [filter, setFilter] = useState('open');
    const [draft, setDraft] = useState('');
    const [replyTo, setReplyTo] = useState(null);
    const [replyBody, setReplyBody] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/comments`);
        setData(response.data);
        onChanged?.(response.data.counts);
    }, [bookId, onChanged]);

    useEffect(() => {
        load();
    }, [load]);

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

            setData(response.data);
            onChanged?.(response.data.counts);
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

        setData(response.data);
        setReplyTo(null);
        setReplyBody('');
    };

    const setStatus = async (thread, status) => {
        const response = await window.axios.patch(`/comments/${thread.id}`, { status });
        setData(response.data);
        onChanged?.(response.data.counts);
    };

    const remove = async (comment) => {
        const response = await window.axios.delete(`/comments/${comment.id}`);
        setData(response.data);
        onChanged?.(response.data.counts);
    };

    if (!data) {
        return <p className="p-4 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const threads = data.threads.filter((thread) =>
        filter === 'all' ? true : thread.status === filter,
    );

    return (
        <div className="p-3.5">
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
                        className={`rounded-badge px-2.5 py-1 text-[11.5px] font-medium transition ${
                            filter === tab.key
                                ? 'bg-brand-primary text-white'
                                : 'text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {tab.label} <span className="opacity-70">{tab.count}</span>
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
                    className="w-full rounded-control border border-border-input p-2.5 text-[12.5px] placeholder:text-text-secondary/70 focus:border-brand-primary focus:ring-0 disabled:bg-bg-subtle"
                />
                <button
                    disabled={busy || !draft.trim() || !sectionId}
                    data-a="comments:post"
                    className="mt-2 h-[32px] w-full rounded-control bg-brand-primary text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
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
                                            className="rounded-control bg-brand-primary px-2.5 py-1 text-[11.5px] font-semibold text-white"
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
