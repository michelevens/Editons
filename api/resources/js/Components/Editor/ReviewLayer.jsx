import Dialog, { CloseButton } from '@/Components/Dialog';
import { ago, ChangeText } from '@/Components/Studio/CommentsPanel';
import Icon from '@/Components/Studio/Icon';
import { useState } from 'react';

/**
 * What floats over the manuscript while it is being reviewed: the card on a
 * suggested change, the bubble on a selection, and the sheets for writing a
 * comment and reading a thread. Dialogs are Components/Dialog, so on a phone
 * they are bottom sheets.
 *
 * Every control keeps the selection where it is (mousedown prevented): a
 * tap that moved focus out of the text would collapse the very selection
 * the author is commenting on.
 */

const keep = (event) => event.preventDefault();

/** A suggested change: who, when, and Accept / Reject. */
export function ChangePopover({ change, position, onAccept, onReject, onEnter, onLeave }) {
    return (
        <div
            role="group"
            aria-label={`Suggestion by ${change.author}`}
            data-a="suggest:popover"
            onMouseDown={keep}
            onMouseEnter={onEnter}
            onMouseLeave={onLeave}
            style={position}
            className="absolute z-30 w-[260px] max-w-[calc(100%-16px)] rounded-tile border border-border-default bg-bg-surface p-2.5 shadow-popover"
        >
            <p className="text-[11.5px] text-text-secondary">
                <span className="font-semibold text-text-primary">{change.author}</span>
                {change.at ? ` · ${ago(change.at)}` : ''}
            </p>
            <p className="mt-1 line-clamp-3">
                <ChangeText change={change} />
            </p>
            <div className="mt-2 flex gap-2">
                <button
                    type="button"
                    data-a="suggest:accept"
                    onClick={onAccept}
                    className="tap flex h-8 flex-1 items-center justify-center gap-1 rounded-control bg-status-success-fill text-[12px] font-semibold text-white"
                >
                    <Icon name="check" size={14} strokeWidth={2.4} /> Accept
                </button>
                <button
                    type="button"
                    data-a="suggest:reject"
                    onClick={onReject}
                    className="tap flex h-8 flex-1 items-center justify-center gap-1 rounded-control border border-border-input text-[12px] font-semibold text-status-danger hover:bg-status-danger-bg"
                >
                    <Icon name="x" size={13} strokeWidth={2.4} /> Reject
                </button>
            </div>
        </div>
    );
}

/** A commented passage on a desktop: the thread's opening, and a way in. */
export function CommentPopover({ thread, position, onOpen, onEnter, onLeave }) {
    return (
        <div
            role="group"
            aria-label={`Comment by ${thread.author_name}`}
            data-a="comment:popover"
            onMouseDown={keep}
            onMouseEnter={onEnter}
            onMouseLeave={onLeave}
            style={position}
            className="absolute z-30 w-[260px] max-w-[calc(100%-16px)] rounded-tile border border-border-default bg-bg-surface p-2.5 shadow-popover"
        >
            <p className="text-[11.5px] text-text-secondary">
                <span className="font-semibold text-text-primary">{thread.author_name}</span> · {thread.created_at}
            </p>
            <p className="mt-1 line-clamp-3 text-[12.5px] text-text-body">{thread.body}</p>
            <button
                type="button"
                data-a="comment:open-thread"
                onClick={onOpen}
                className="mt-1.5 text-[12px] font-semibold text-brand-primary hover:underline"
            >
                {thread.replies.length ? `${thread.replies.length} ${thread.replies.length === 1 ? 'reply' : 'replies'} · ` : ''}
                Reply or resolve
            </button>
        </div>
    );
}

/** On a selection: Comment, and the two formats people reach for. */
export function SelectionBubble({ position, onComment, onBold, onItalic, bold, italic }) {
    const button = 'tap flex h-9 min-w-9 items-center justify-center gap-1.5 rounded-control px-2.5 text-[13px] font-semibold transition';

    return (
        <div
            role="toolbar"
            aria-label="Selection"
            data-a="selection:bubble"
            onMouseDown={keep}
            style={position}
            className="absolute z-30 flex items-center gap-0.5 rounded-tile border border-border-default bg-bg-surface p-1 shadow-popover"
        >
            {onComment && (
                <button type="button" data-a="selection:comment" onClick={onComment} className={`${button} text-brand-primary hover:bg-brand-primary-subtle`}>
                    <Icon name="chat" size={15} /> Comment
                </button>
            )}
            <span className="mx-0.5 h-5 w-px bg-border-default" aria-hidden="true" />
            <button type="button" data-a="selection:bold" aria-pressed={bold} aria-label="Bold" onClick={onBold}
                className={`${button} ${bold ? 'bg-brand-primary-subtle text-brand-primary' : 'text-text-body hover:bg-bg-subtle'}`}>
                <strong>B</strong>
            </button>
            <button type="button" data-a="selection:italic" aria-pressed={italic} aria-label="Italic" onClick={onItalic}
                className={`${button} ${italic ? 'bg-brand-primary-subtle text-brand-primary' : 'text-text-body hover:bg-bg-subtle'}`}>
                <em className="font-serif">I</em>
            </button>
        </div>
    );
}

/** Writing a comment on the selected passage. */
export function CommentDialog({ excerpt, onClose, onPost }) {
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const post = async () => {
        if (!body.trim() || busy) return;

        setBusy(true);
        setError(null);

        try {
            await onPost(body.trim());
        } catch (failure) {
            setError(failure.response?.data?.message ?? 'The comment was not posted. Try again.');
            setBusy(false);
        }
    };

    return (
        <Dialog
            label="Comment"
            onClose={onClose}
            data-a="comment:dialog"
            className="w-full max-w-[480px] rounded-panel border border-border-default bg-bg-surface p-4 shadow-xl"
            onKeyDown={(event) => {
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) post();
            }}
        >
            <div className="flex items-center justify-between">
                <h3 className="text-[15px] font-semibold text-text-primary">Comment</h3>
                <CloseButton onClick={onClose} label="Cancel comment" />
            </div>
            <p className="mt-2 line-clamp-3 border-l-2 border-status-warning-badge pl-2 font-manuscript text-[12.5px] italic text-text-secondary">
                {excerpt}
            </p>
            <textarea
                autoFocus
                rows={4}
                value={body}
                data-a="comment:body"
                onChange={(event) => setBody(event.target.value)}
                placeholder="What should change here, or what is right about it?"
                className="mt-3 w-full rounded-control border-border-input text-[13.5px] focus:border-brand-primary focus:ring-0"
            />
            {error && <p role="alert" className="mt-1 text-[12px] text-status-danger">{error}</p>}
            <div className="mt-3 flex justify-end gap-2">
                <button type="button" onClick={onClose}
                    className="tap h-[36px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle">
                    Cancel
                </button>
                <button type="button" data-a="comment:post" disabled={!body.trim() || busy} onClick={post}
                    className="tap h-[36px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50">
                    {busy ? 'Posting…' : 'Comment'}
                </button>
            </div>
        </Dialog>
    );
}

/** One thread: the comment, its replies, Reply and Resolve. */
export function ThreadSheet({ thread, onClose, onReply, onResolve }) {
    const [reply, setReply] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const run = async (action) => {
        setBusy(true);
        setError(null);

        try {
            await action();
        } catch (failure) {
            setError(failure.response?.data?.message ?? 'That did not go through. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            label="Comment thread"
            onClose={onClose}
            data-a="thread:sheet"
            className="flex max-h-[80vh] w-full max-w-[480px] flex-col rounded-panel border border-border-default bg-bg-surface p-4 shadow-xl"
        >
            <div className="flex items-center justify-between">
                <h3 className="text-[15px] font-semibold text-text-primary">Comment</h3>
                <CloseButton onClick={onClose} label="Close thread" />
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {thread.excerpt && (
                    <p className="mt-2 line-clamp-3 border-l-2 border-status-warning-badge pl-2 font-manuscript text-[12.5px] italic text-text-secondary">
                        {thread.excerpt}
                    </p>
                )}
                <p className="mt-3 text-[12px] text-text-secondary">
                    <span className="font-semibold text-text-primary">{thread.author_name}</span> · {thread.created_at}
                </p>
                <p data-a="thread:body" className="mt-0.5 text-[13.5px] leading-relaxed text-text-body">{thread.body}</p>
                {thread.replies.length > 0 && (
                    <ul className="mt-3 space-y-2 border-l-2 border-border-default pl-3">
                        {thread.replies.map((item) => (
                            <li key={item.id}>
                                <p className="text-[12px] text-text-secondary">
                                    <span className="font-semibold text-text-primary">{item.author_name}</span> · {item.created_at}
                                </p>
                                <p className="text-[13px] leading-relaxed text-text-body">{item.body}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <textarea
                rows={2}
                value={reply}
                data-a="thread:reply-body"
                onChange={(event) => setReply(event.target.value)}
                placeholder="Reply"
                className="mt-3 w-full rounded-control border-border-input text-[13.5px] focus:border-brand-primary focus:ring-0"
            />
            {error && <p role="alert" className="mt-1 text-[12px] text-status-danger">{error}</p>}
            <div className="mt-2 flex items-center gap-2">
                <button type="button" data-a="thread:resolve" disabled={busy}
                    onClick={() => run(() => onResolve(thread.status === 'open' ? 'resolved' : 'open'))}
                    className="tap h-[36px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle disabled:opacity-50">
                    {thread.status === 'open' ? 'Resolve' : 'Reopen'}
                </button>
                <button type="button" data-a="thread:reply" disabled={!reply.trim() || busy}
                    onClick={() => run(async () => {
                        await onReply(reply.trim());
                        setReply('');
                    })}
                    className="tap ml-auto h-[36px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50">
                    Reply
                </button>
            </div>
        </Dialog>
    );
}
