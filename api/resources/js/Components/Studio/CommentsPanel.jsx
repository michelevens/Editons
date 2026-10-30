import { useCallback, useEffect, useRef, useState } from 'react';
import { onComments, publishComments } from './commentsBus';
import Icon from './Icon';
import { formatDate, t, useI18n } from '../../i18n';

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
    useI18n();
    const [data, setData] = useState(null);
    const [filter, setFilter] = useState('open');
    // Narrowing filters on top of open/resolved (R4). Empty = everyone,
    // every chapter, as before.
    const [author, setAuthor] = useState('');
    const [chapter, setChapter] = useState('');
    const [mentionsMe, setMentionsMe] = useState(false);
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

    /** Resolve or reopen every thread the filters show that this person may. */
    const setStatusAll = async (targets, status) => {
        const message = status === 'resolved'
            ? t('comments.bulk_resolve_confirm', { count: targets.length })
            : t('comments.bulk_reopen_confirm', { count: targets.length });

        if (!confirm(message)) return;

        setBusy(true);
        try {
            const response = await window.axios.post(`/books/${bookId}/comments/status`, {
                ids: targets.map((thread) => thread.id),
                status,
            });
            take(response.data);
        } finally {
            setBusy(false);
        }
    };

    const remove = async (comment) => {
        const response = await window.axios.delete(`/comments/${comment.id}`);
        take(response.data);
    };

    if (!data) {
        return <p className="p-4 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const byStatus = data.threads.filter((thread) =>
        filter === 'all' ? true : thread.status === filter,
    );

    // Each dimension counts what the OTHER filters leave, so a number always
    // says how many threads choosing it would show.
    const authorKey = (thread) => String(thread.user_id ?? thread.author_name);
    const pass = (thread, skip) =>
        (skip === 'author' || !author || authorKey(thread) === author)
        && (skip === 'chapter' || !chapter || String(thread.section_id) === chapter)
        && (skip === 'mentions' || !mentionsMe || thread.mentions_me);

    const threads = byStatus.filter((thread) => pass(thread));
    const narrowed = author !== '' || chapter !== '' || mentionsMe;

    const tally = (skip, keyOf, labelOf, chosen) => {
        const rows = new Map();
        byStatus.filter((thread) => pass(thread, skip)).forEach((thread) => {
            const key = keyOf(thread);
            const row = rows.get(key) ?? { key, label: labelOf(thread), count: 0 };
            row.count += 1;
            rows.set(key, row);
        });

        // A chosen value with no threads left stays listed, at 0, so the
        // select never goes blank.
        if (chosen && !rows.has(chosen)) {
            const thread = data.threads.find((th) => keyOf(th) === chosen);
            rows.set(chosen, { key: chosen, label: thread ? labelOf(thread) : chosen, count: 0 });
        }

        return [...rows.values()];
    };

    const authors = tally('author', authorKey, (th) => th.author_name, author)
        .sort((a, b) => a.label.localeCompare(b.label));
    const chapters = tally('chapter', (th) => String(th.section_id), (th) => th.section_title, chapter);
    const mentionCount = byStatus.filter((thread) => pass(thread, 'mentions') && thread.mentions_me).length;

    const resolvable = threads.filter((thread) => thread.status === 'open' && thread.can_resolve !== false);
    const reopenable = threads.filter((thread) => thread.status === 'resolved' && thread.can_resolve !== false);

    return (
        <div className="p-3.5">
            {changes.length > 0 && onDecide && (
                <ChangesList changes={changes} onDecide={onDecide} onShow={onShowChange} />
            )}

            <div className="mb-3 flex gap-1.5">
                {[
                    { key: 'open', label: t('comments.open'), count: data.counts.open },
                    { key: 'resolved', label: t('comments.resolved'), count: data.counts.resolved },
                    { key: 'all', label: t('shelf.filter_all'), count: data.counts.open + data.counts.resolved },
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

            {data.threads.length > 0 && (
                <div data-a="comments:filters" className="mb-3 flex flex-wrap items-center gap-1.5 text-[11.5px]">
                    <select
                        value={author}
                        data-a="comments:filter:author"
                        aria-label={t('comments.filter_author')}
                        onChange={(event) => setAuthor(event.target.value)}
                        className="min-w-0 max-w-full flex-1 basis-28 rounded-control border-border-input py-1 pl-2 pr-7 text-[11.5px] focus:border-brand-primary focus:ring-0"
                    >
                        <option value="">{t('comments.filter_anyone')}</option>
                        {authors.map((a) => (
                            <option key={a.key} value={a.key}>{a.label} ({a.count})</option>
                        ))}
                    </select>
                    <select
                        value={chapter}
                        data-a="comments:filter:chapter"
                        aria-label={t('comments.filter_chapter')}
                        onChange={(event) => setChapter(event.target.value)}
                        className="min-w-0 max-w-full flex-1 basis-28 rounded-control border-border-input py-1 pl-2 pr-7 text-[11.5px] focus:border-brand-primary focus:ring-0"
                    >
                        <option value="">{t('comments.filter_all_chapters')}</option>
                        {chapters.map((c) => (
                            <option key={c.key} value={c.key}>{c.label} ({c.count})</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        data-a="comments:filter:mentions"
                        aria-pressed={mentionsMe}
                        onClick={() => setMentionsMe((v) => !v)}
                        className={`rounded-badge border px-2.5 py-1 font-medium transition max-sm:py-1.5 ${
                            mentionsMe
                                ? 'border-brand-fill bg-brand-fill text-white'
                                : 'border-border-input text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {t('comments.filter_mentions_me')} <span>{mentionCount}</span>
                    </button>
                    {narrowed && (
                        <button
                            type="button"
                            data-a="comments:filter:clear"
                            onClick={() => {
                                setAuthor('');
                                setChapter('');
                                setMentionsMe(false);
                            }}
                            className="font-medium text-brand-primary hover:underline"
                        >
                            {t('comments.filter_clear')}
                        </button>
                    )}
                </div>
            )}

            {(resolvable.length > 1 || reopenable.length > 1) && (
                <div className="mb-3 flex flex-wrap gap-2">
                    {resolvable.length > 1 && (
                        <button
                            type="button"
                            disabled={busy}
                            data-a="comments:bulk-resolve"
                            onClick={() => setStatusAll(resolvable, 'resolved')}
                            className="rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-semibold text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                        >
                            {t('comments.bulk_resolve', { count: resolvable.length })}
                        </button>
                    )}
                    {reopenable.length > 1 && (
                        <button
                            type="button"
                            disabled={busy}
                            data-a="comments:bulk-reopen"
                            onClick={() => setStatusAll(reopenable, 'open')}
                            className="rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-semibold text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                        >
                            {t('comments.bulk_reopen', { count: reopenable.length })}
                        </button>
                    )}
                </div>
            )}

            <form onSubmit={post} className="mb-4">
                <textarea
                    value={draft}
                    rows={3}
                    data-a="comments:draft"
                    disabled={!sectionId}
                    placeholder={
                        sectionId
                            ? t('comments.placeholder')
                            : t('comments.open_chapter')
                    }
                    onChange={(event) => setDraft(event.target.value)}
                    className="w-full rounded-control border border-border-input p-2.5 text-[12.5px] placeholder:text-text-secondary focus:border-brand-primary focus:ring-0 disabled:bg-bg-subtle"
                />
                <button
                    disabled={busy || !draft.trim() || !sectionId}
                    data-a="comments:post"
                    className="mt-2 h-[32px] w-full rounded-control bg-brand-fill text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy ? t('comments.posting') : t('comments.post')}
                </button>
            </form>

            {threads.length === 0 ? (
                <p className="px-1 text-[11.5px] leading-relaxed text-text-secondary">
                    {filter === 'open' && !narrowed
                        ? t('comments.none_open')
                        : t('comments.none_filter')}
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
                                    {t('comments.detached')}
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
                                            {t('comments.reply')}
                                        </button>
                                        <button
                                            data-a={`comments:reply-cancel:${thread.id}`}
                                            onClick={() => setReplyTo(null)}
                                            className="text-[11.5px] text-text-secondary"
                                        >
                                            {t('common.cancel')}
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
                                        {t('comments.reply')}
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
                                        {thread.status === 'open' ? t('comments.resolve') : t('comments.reopen')}
                                    </button>
                                    <button
                                        data-a={`comments:delete:${thread.id}`}
                                        onClick={() => remove(thread)}
                                        title={t('comments.delete')}
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

    if (seconds < 60) return t('time.just_now');
    if (seconds < 3600) return t('time.min_ago', { n: Math.floor(seconds / 60) });
    if (seconds < 86400) return t('time.h_ago', { n: Math.floor(seconds / 3600) });

    return formatDate(iso, {});
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
    useI18n();

    return (
        <section data-a="changes:list" aria-label={t('changes.title')} className="mb-4 rounded-tile border border-border-default bg-bg-surface">
            <div className="flex flex-wrap items-center gap-2 border-b border-border-divider px-2.5 py-2">
                <h3 className="mr-auto text-[12.5px] font-semibold text-text-primary">
                    {t('changes.title')} <span className="font-normal text-text-secondary">({changes.length})</span>
                </h3>
                <button
                    type="button"
                    data-a="changes:accept-all"
                    onClick={() => onDecide(null, true)}
                    className="tap rounded-control bg-brand-fill px-2.5 py-1 text-[11.5px] font-semibold text-white hover:bg-brand-primary-hover"
                >
                    {t('changes.accept_all')}
                </button>
                <button
                    type="button"
                    data-a="changes:reject-all"
                    onClick={() => onDecide(null, false)}
                    className="tap rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-semibold text-text-primary hover:bg-bg-subtle"
                >
                    {t('changes.reject_all')}
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
                            title={t('changes.show')}
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
                            aria-label={t('changes.accept_by', { author: change.author })}
                            title={t('changes.accept')}
                            className="tap grid h-8 w-8 shrink-0 place-items-center rounded-control text-status-success hover:bg-status-success-bg"
                        >
                            <Icon name="check" size={15} strokeWidth={2.2} />
                        </button>
                        <button
                            type="button"
                            data-a={`changes:reject:${change.id}`}
                            onClick={() => onDecide([change.id], false)}
                            aria-label={t('changes.reject_by', { author: change.author })}
                            title={t('changes.reject')}
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
