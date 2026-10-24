import AppHeader from '@/Components/Studio/AppHeader';
import Icon from '@/Components/Studio/Icon';
import MobileNav from '@/Components/Studio/MobileNav';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * The inbox: what happened on your books while you were elsewhere.
 *
 * Comments, replies and mentions, chapters moving on the review board,
 * people joining a book, PDFs and EPUBs finishing or failing, AI reviews
 * finishing. Every row is a record the server wrote when the thing happened
 * (App\Inbox\Inbox); read, done and snoozed are kept there too, so the inbox
 * is the same on the phone and the desktop.
 *
 * On a phone a row swipes: left to mark it done, right to snooze it until
 * tomorrow morning, each with Undo, the way a mail app does. A keyboard has
 * the same actions as buttons on every row and as keys (E done, H snooze,
 * U read or unread, arrows between rows).
 */
const KIND_ICONS = {
    comment: 'chat',
    reply: 'chat',
    mention: 'user',
    workflow: 'flow',
    assigned: 'flow',
    team: 'users',
    export_ready: 'exp',
    export_failed: 'info',
    review: 'sugg',
    support: 'help',
};

const VIEWS = [
    { key: 'inbox', label: 'Inbox' },
    { key: 'snoozed', label: 'Snoozed' },
    { key: 'done', label: 'Done' },
];

/** What a swipe each way does, in each view; null where it does nothing. */
const SWIPES = {
    inbox: { left: 'done', right: 'snooze' },
    snoozed: { left: 'done', right: 'unsnooze' },
    done: { left: 'undone', right: null },
};

const ACTIONS = {
    done: { label: 'Done', icon: 'check', tone: 'bg-status-success-fill', undo: 'undone', said: 'Marked done.' },
    undone: { label: 'Back to inbox', icon: 'reset', tone: 'bg-brand-fill', undo: 'done', said: 'Moved back to the inbox.' },
    snooze: { label: 'Tomorrow', icon: 'history', tone: 'bg-status-warning-fill', undo: 'unsnooze', said: 'Snoozed until tomorrow morning.' },
    unsnooze: { label: 'Wake now', icon: 'bell', tone: 'bg-brand-fill', undo: 'snooze', said: 'Back in the inbox.' },
};

export default function Inbox({ view, items: initialItems, counts: initialCounts }) {
    const { auth } = usePage().props;
    const [items, setItems] = useState(initialItems);
    const [counts, setCounts] = useState(initialCounts);
    const [undo, setUndo] = useState(null);
    const [failure, setFailure] = useState(null);
    const list = useRef(null);
    const timer = useRef(null);

    useEffect(() => setItems(initialItems), [initialItems]);
    useEffect(() => setCounts(initialCounts), [initialCounts]);
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const send = async (item, action) => {
        const response = await window.axios.patch(route('inbox.update', item.id), { action });
        setCounts(response.data.counts);

        return response.data.item;
    };

    /** An action that takes the row out of this view, with Undo. */
    const act = async (item, action) => {
        const at = items.findIndex((entry) => entry.id === item.id);
        setItems((current) => current.filter((entry) => entry.id !== item.id));
        setFailure(null);

        try {
            const updated = await send(item, action);
            window.clearTimeout(timer.current);
            setUndo({ item: updated, at, action });
            timer.current = window.setTimeout(() => setUndo(null), 6000);
        } catch {
            setItems((current) => insert(current, item, at));
            setFailure('That did not save. Try again.');
        }
    };

    const revert = async () => {
        if (!undo) return;

        const { item, at, action } = undo;
        window.clearTimeout(timer.current);
        setUndo(null);

        try {
            const restored = await send(item, ACTIONS[action].undo);
            setItems((current) => insert(current, restored, at));
        } catch {
            setFailure('That could not be undone.');
        }
    };

    const toggleRead = async (item) => {
        const updated = await send(item, item.read ? 'unread' : 'read').catch(() => null);

        if (updated) setItems((current) => current.map((entry) => (entry.id === item.id ? updated : entry)));
    };

    const open = (item) => {
        setItems((current) => current.map((entry) => (entry.id === item.id ? { ...entry, read: true } : entry)));
        router.visit(item.url);
    };

    const readAll = async () => {
        const response = await window.axios.post(route('inbox.read-all'));
        setCounts(response.data.counts);
        setItems((current) => current.map((entry) => ({ ...entry, read: true })));
    };

    // Up and down between rows, wherever focus is inside one.
    const move = (from, step) => {
        const rows = [...(list.current?.querySelectorAll('[data-a="inbox:open"]') ?? [])];
        const at = rows.findIndex((row) => row.closest('li') === from);
        rows[Math.max(0, Math.min(rows.length - 1, at + step))]?.focus();
    };

    const swipes = SWIPES[view] ?? SWIPES.inbox;

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Inbox" />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main id="main" tabIndex={-1} className="min-h-0 flex-1 overflow-y-auto outline-none">
                <div className="mx-auto max-w-[760px] px-0 py-5 sm:px-8 sm:py-9">
                    <div className="flex items-end gap-3 px-4 sm:px-0">
                        <div className="min-w-0 flex-1">
                            <h1 className="font-display text-[26px] font-extrabold tracking-[-0.03em] text-text-primary sm:text-[30px]">
                                Inbox
                            </h1>
                            <p data-a="inbox:summary" className="mt-0.5 text-[13px] text-text-secondary">
                                {counts.unread > 0 ? `${counts.unread} unread` : 'All read'}
                            </p>
                        </div>
                        {view === 'inbox' && counts.unread > 0 && (
                            <button
                                type="button"
                                data-a="inbox:read-all"
                                onClick={readAll}
                                className="tap rounded-control border border-border-input px-3 text-[12.5px] font-medium text-text-body transition hover:border-brand-primary/40"
                            >
                                Mark all read
                            </button>
                        )}
                    </div>

                    <nav aria-label="Inbox views" className="mt-4 flex gap-1 border-b border-border-default px-3 sm:px-0">
                        {VIEWS.map((option) => (
                            <Link
                                key={option.key}
                                href={route('inbox.index', option.key === 'inbox' ? {} : { view: option.key })}
                                data-a={`inbox:view:${option.key}`}
                                aria-current={view === option.key ? 'page' : undefined}
                                preserveScroll
                                className={`-mb-px flex min-h-[44px] items-center gap-1.5 border-b-2 px-3 text-[13px] font-medium transition ${
                                    view === option.key
                                        ? 'border-brand-primary text-brand-primary'
                                        : 'border-transparent text-text-secondary hover:text-text-primary'
                                }`}
                            >
                                {option.label}
                                {option.key !== 'done' && counts[option.key] > 0 && (
                                    <span className="rounded-badge bg-bg-subtle px-1.5 text-[11px] tabular-nums text-text-secondary">
                                        {counts[option.key]}
                                    </span>
                                )}
                            </Link>
                        ))}
                    </nav>

                    <p className="mt-2 px-4 text-[11.5px] text-text-secondary sm:px-0">
                        <span className="sm:hidden">
                            {swipes.left && `Swipe left: ${ACTIONS[swipes.left].label.toLowerCase()}`}
                            {swipes.right && ` · right: ${ACTIONS[swipes.right].label.toLowerCase()}`}
                        </span>
                        <span className="max-sm:hidden">
                            Keys: ↑ ↓ to move, Enter to open
                            {swipes.left === 'done' && ', E done'}
                            {swipes.right === 'snooze' && ', H snooze until tomorrow'}, U read or unread.
                        </span>
                    </p>

                    {failure && (
                        <p role="alert" className="mx-4 mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[12.5px] text-status-danger sm:mx-0">
                            {failure}
                        </p>
                    )}

                    {items.length === 0 ? (
                        <div data-a="inbox:empty" className="px-6 py-16 text-center">
                            <span className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-bg-subtle text-text-secondary">
                                <Icon name="bell" size={22} />
                            </span>
                            <p className="mt-3 text-[14px] font-semibold text-text-primary">
                                {view === 'snoozed' ? 'Nothing snoozed' : view === 'done' ? 'Nothing done yet' : 'You are all caught up'}
                            </p>
                            <p className="mt-1 text-[12.5px] text-text-secondary">
                                Comments, review-board moves, new team members, finished exports and AI reviews arrive here.
                            </p>
                        </div>
                    ) : (
                        <ul ref={list} aria-label={`${VIEWS.find((v) => v.key === view)?.label ?? 'Inbox'} items`} className="mt-2 sm:space-y-1.5">
                            {items.map((item) => (
                                <InboxRow
                                    key={item.id}
                                    item={item}
                                    swipes={swipes}
                                    onOpen={() => open(item)}
                                    onAct={(action) => act(item, action)}
                                    onToggleRead={() => toggleRead(item)}
                                    onMove={move}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </main>

            {undo && (
                <div className="pointer-events-none fixed inset-x-0 bottom-[76px] z-40 flex justify-center px-4 lg:bottom-6">
                    <div
                        role="status"
                        data-a="inbox:toast"
                        className="pointer-events-auto flex items-center gap-3 rounded-panel bg-bg-inverse px-4 py-2.5 text-[13px] text-text-inverse shadow-popover"
                    >
                        <span>{ACTIONS[undo.action].said}</span>
                        <button
                            type="button"
                            data-a="inbox:undo"
                            onClick={revert}
                            className="tap rounded-control px-2 font-semibold underline underline-offset-2"
                        >
                            Undo
                        </button>
                    </div>
                </div>
            )}

            <MobileNav current="inbox" inboxCount={counts.unread} />
        </div>
    );
}

function insert(list, item, at) {
    const next = list.filter((entry) => entry.id !== item.id);
    next.splice(Math.max(0, Math.min(at, next.length)), 0, item);

    return next;
}

/**
 * One row. The whole row opens the item; a horizontal drag past a third of
 * its width (or 80px) does the swipe action for that side. The two actions
 * are also buttons, visible beside the row from a tablet up and reachable by
 * Tab on a phone, so nothing here needs a finger.
 */
function InboxRow({ item, swipes, onOpen, onAct, onToggleRead, onMove }) {
    const [dx, setDx] = useState(0);
    const [dragging, setDragging] = useState(false);
    const start = useRef(null);
    const moved = useRef(false);
    const row = useRef(null);

    const limit = () => Math.min(120, (row.current?.offsetWidth ?? 300) / 3);

    const onPointerDown = (event) => {
        if (event.button !== 0) return;

        start.current = { x: event.clientX, y: event.clientY, id: event.pointerId };
        moved.current = false;
    };

    const onPointerMove = (event) => {
        const from = start.current;

        if (!from || from.id !== event.pointerId) return;

        const x = event.clientX - from.x;
        const y = event.clientY - from.y;

        if (!dragging) {
            // A vertical drag is the list scrolling, not a swipe.
            if (Math.abs(y) > 10 && Math.abs(y) > Math.abs(x)) {
                start.current = null;

                return;
            }

            if (Math.abs(x) < 10) return;

            setDragging(true);
            moved.current = true;
            event.currentTarget.setPointerCapture?.(event.pointerId);
        }

        // Only toward a side that has an action.
        const allowed = (x < 0 && swipes.left) || (x > 0 && swipes.right) ? x : x / 6;
        setDx(Math.max(-limit() * 1.4, Math.min(limit() * 1.4, allowed)));
    };

    const finish = () => {
        const from = start.current;
        start.current = null;
        setDragging(false);

        if (!from) return;

        const side = dx <= -Math.min(80, limit()) ? 'left' : dx >= Math.min(80, limit()) ? 'right' : null;
        setDx(0);

        if (side && swipes[side]) onAct(swipes[side]);
    };

    const onClick = (event) => {
        // A drag that ended over the row is not a tap.
        if (moved.current) {
            event.preventDefault();
            moved.current = false;

            return;
        }

        onOpen();
    };

    const onKeyDown = (event) => {
        if (event.metaKey || event.ctrlKey || event.altKey) return;

        const key = event.key.toLowerCase();

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            onMove(event.currentTarget, event.key === 'ArrowDown' ? 1 : -1);
        } else if (key === 'e' && swipes.left === 'done') {
            event.preventDefault();
            onMove(event.currentTarget, 1);
            onAct('done');
        } else if (key === 'h' && swipes.right === 'snooze') {
            event.preventDefault();
            onMove(event.currentTarget, 1);
            onAct('snooze');
        } else if (key === 'u') {
            event.preventDefault();
            onToggleRead();
        }
    };

    const left = swipes.left && ACTIONS[swipes.left];
    const right = swipes.right && ACTIONS[swipes.right];
    const icon = KIND_ICONS[item.kind] ?? 'bell';

    return (
        <li
            ref={row}
            data-a="inbox:item"
            data-id={item.id}
            data-read={item.read ? 'true' : 'false'}
            onKeyDown={onKeyDown}
            className="group relative overflow-hidden border-b border-border-divider bg-bg-surface sm:rounded-tile sm:border sm:border-border-default"
        >
            {/* What a swipe will do, uncovered as the row slides. */}
            {right && dx > 0 && (
                <div aria-hidden="true" className={`absolute inset-y-0 left-0 flex items-center gap-2 px-5 text-[13px] font-semibold text-white ${right.tone}`} style={{ width: Math.abs(dx) + 40 }}>
                    <Icon name={right.icon} size={18} />
                    {right.label}
                </div>
            )}
            {left && dx < 0 && (
                <div aria-hidden="true" className={`absolute inset-y-0 right-0 flex items-center justify-end gap-2 px-5 text-[13px] font-semibold text-white ${left.tone}`} style={{ width: Math.abs(dx) + 40 }}>
                    {left.label}
                    <Icon name={left.icon} size={18} />
                </div>
            )}

            <div
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={finish}
                onPointerCancel={() => {
                    start.current = null;
                    setDragging(false);
                    setDx(0);
                }}
                style={{ transform: dx ? `translateX(${dx}px)` : undefined, touchAction: 'pan-y' }}
                className={`relative flex items-stretch bg-bg-surface ${dragging ? '' : 'transition-transform duration-200'}`}
            >
                <button
                    type="button"
                    data-a="inbox:open"
                    onClick={onClick}
                    className="flex min-w-0 flex-1 items-start gap-3 px-4 py-3 text-left outline-none focus-visible:bg-bg-subtle sm:pr-2"
                >
                    <span className="relative mt-0.5 shrink-0">
                        <span className={`grid h-9 w-9 place-items-center rounded-full ${item.kind === 'export_failed' ? 'bg-status-danger-bg text-status-danger' : 'bg-brand-primary-subtle text-brand-primary'}`}>
                            <Icon name={icon} size={17} />
                        </span>
                        {!item.read && (
                            <span data-a="inbox:unread-dot" className="absolute -left-1 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-bg-surface bg-brand-fill">
                                <span className="sr-only">Unread</span>
                            </span>
                        )}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="flex items-baseline gap-2">
                            <span className={`line-clamp-2 min-w-0 flex-1 text-[13.5px] leading-snug ${item.read ? 'text-text-body' : 'font-semibold text-text-primary'}`}>
                                {item.title}
                            </span>
                            <time dateTime={item.at} className="shrink-0 text-[11.5px] tabular-nums text-text-secondary">
                                {item.ago}
                            </time>
                        </span>
                        <span className="mt-0.5 block truncate text-[12px] text-text-secondary">
                            {item.book?.title}
                            {item.book && item.body ? ' · ' : ''}
                            {item.body}
                        </span>
                    </span>
                </button>

                <span className="flex shrink-0 items-center gap-0.5 pr-2 max-sm:pr-0">
                    {[left && swipes.left, right && swipes.right].filter(Boolean).map((action) => (
                        <button
                            key={action}
                            type="button"
                            data-a={`inbox:${action}`}
                            aria-label={`${ACTIONS[action].label}: ${item.title}`}
                            title={ACTIONS[action].label}
                            onClick={() => onAct(action)}
                            className="grid h-9 w-9 place-items-center rounded-control text-text-secondary transition hover:bg-bg-subtle hover:text-text-primary max-sm:sr-only max-sm:focus:not-sr-only"
                        >
                            <Icon name={ACTIONS[action].icon} size={16} />
                        </button>
                    ))}
                </span>
            </div>
        </li>
    );
}
