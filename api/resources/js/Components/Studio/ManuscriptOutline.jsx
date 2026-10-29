import Dialog, { CloseButton } from '@/Components/Dialog';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Icon from './Icon';
import ProgressRing, { ringLabel } from './ProgressRing';
import { ariaKeys, matches } from '../../shortcuts';
import { t as tr, useI18n } from '../../i18n';

/**
 * The left panel: Manuscript tree, Outline table, Notes.
 *
 * HANDOFF.md component 3. Rows are h27, parts collapse, the active row uses
 * the outline tokens, and search hides parts with no matches.
 *
 * Chapters reorder within their level (the top level, or inside one part):
 * by dragging a row with a mouse, by the move up/down buttons on each row,
 * or with Alt+ArrowUp / Alt+ArrowDown on the focused row. Each move saves at
 * once (books.sections.order) and reloads only the binder; the chapter open
 * in the editor is never reloaded under the author. Not while searching: a
 * filtered list has no honest "position".
 *
 * HTML5 drag-and-drop does nothing on a touch screen, so "Reorder" in the
 * header switches the rows into a mode made for a thumb: a drag handle that
 * follows pointer events (touch, pen or mouse), ↑/↓ buttons always shown,
 * rows that do not open while being arranged, and Done to leave.
 *
 * A long press on a row (touch), a right-click, or the ContextMenu key /
 * Shift+F10 opens the row's actions: Rename, Set goal, Move up, Move down
 * and Delete.
 */

/** How long a finger rests on a row before its actions open. */
const LONG_PRESS_MS = 500;

/** How far a finger may wander before a press becomes a scroll. */
const LONG_PRESS_SLOP = 10;

/** The children of `parentId` (null for the top level) in a binder tree. */
function siblingsOf(nodes, parentId) {
    if (!parentId) return nodes;

    for (const node of nodes) {
        if (node.id === parentId) return node.children ?? [];

        const found = siblingsOf(node.children ?? [], parentId);
        if (found.length) return found;
    }

    return [];
}

const TABS = [
    { key: 'Manuscript', tKey: 'binder.manuscript', ready: true },
    { key: 'Outline', tKey: 'binder.outline', ready: true },
    // Opens the Notes screen: the notes are the book's, not the outline's,
    // and this tab said "not built" long after that screen existed.
    { key: 'Notes', tKey: 'binder.notes', ready: true },
];

export default function ManuscriptOutline({
    nodes,
    activeId,
    tab,
    onTab,
    onSelect,
    onAddChapter,
    onImport,
    adding,
    onSetGoal = null,
    onRenamed = null,
    onDeleted = null,
    // The workspace's sub-panels (ADR — customisable writing workspace):
    // the tab strip, the search-and-actions row, and the goal rings.
    showTabs = true,
    showTools = true,
    showGoals = true,
}) {
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState(() => new Set());
    const [partError, setPartError] = useState('');
    const [moving, setMoving] = useState(false);
    const [dragging, setDragging] = useState(null);
    const [dropAt, setDropAt] = useState(null);
    const [announcement, setAnnouncement] = useState('');
    const [reordering, setReordering] = useState(false);
    const [pointerDrag, setPointerDrag] = useState(null);
    const [actions, setActions] = useState(null);
    const refocus = useRef(null);
    const root = useRef(null);

    const bookId = usePage().props.book?.id;
    const reorderable = Boolean(bookId) && !query.trim();

    // Hiding the search row must not leave a filter or reordering on that
    // can no longer be seen or ended.
    useEffect(() => {
        if (showTools) return;

        setQuery('');
        setReordering(false);
    }, [showTools]);

    // The Outline table has no rows to arrange; leaving for it ends the mode.
    useEffect(() => {
        if (tab === 'Outline') setReordering(false);
    }, [tab]);

    // After a move the binder re-renders from the server; put keyboard focus
    // back on the control that moved the row (the row itself, or its ↑/↓
    // button in reorder mode), so it can be pressed again. Looked up inside
    // this panel: the desktop binder stays mounted, hidden, behind the phone
    // drawer, and a document-wide lookup found that one first.
    useEffect(() => {
        if (!refocus.current || !root.current) return;

        const { key, id } = refocus.current;
        const wanted = root.current.querySelector(`[data-a="${key}"]`);
        const target = wanted && !wanted.disabled ? wanted : root.current.querySelector(`[data-a="outline:open:${id}"]`);

        target?.focus();
        refocus.current = null;
    }, [nodes]);

    const move = (node, parentId, toIndex, focusKey = null) => {
        const siblings = siblingsOf(nodes, parentId);
        const ids = siblings.map((sibling) => sibling.id);
        const from = ids.indexOf(node.id);

        if (moving || from < 0 || toIndex < 0 || toIndex >= ids.length || from === toIndex) return;

        ids.splice(from, 1);
        ids.splice(toIndex, 0, node.id);

        setPartError('');
        setMoving(true);

        window.axios
            .patch(route('books.sections.order', bookId), { parent_id: parentId, ids })
            .then(() => {
                setAnnouncement(`${node.title} moved to position ${toIndex + 1} of ${ids.length}.`);
                refocus.current = { key: focusKey ?? `outline:open:${node.id}`, id: node.id };
                router.reload({ only: ['binder'], onFinish: () => setMoving(false) });
            })
            .catch((error) => {
                setMoving(false);
                setPartError(error?.response?.data?.message ?? 'The chapter was not moved.');
            });
    };

    // A touch screen as the main pointer. There a draggable row is worse
    // than useless: Chrome turns a long press on it into a native drag and
    // cancels the press that opens the row's actions.
    const coarse = useMemo(
        () => typeof window !== 'undefined' && Boolean(window.matchMedia?.('(pointer: coarse)').matches),
        [],
    );

    // Mouse drag-and-drop (HTML5). Outside reorder mode only: in it, the
    // handle's pointer drag does the same for every kind of pointer.
    const drag = reorderable && !reordering && !coarse
        ? {
              dragging,
              dropAt,
              start: (node, parentId) => setDragging({ id: node.id, parentId }),
              over: (event, node, parentId) => {
                  // Only between siblings: moving a chapter into another part
                  // is a different act, and not this one.
                  if (!dragging || dragging.parentId !== parentId) return;

                  event.preventDefault();
                  event.dataTransfer.dropEffect = 'move';
                  setDropAt(node.id);
              },
              drop: (event, parentId, index) => {
                  event.preventDefault();

                  const source = dragging && siblingsOf(nodes, parentId).find((n) => n.id === dragging.id);

                  if (source && dragging.parentId === parentId) move(source, parentId, index);

                  setDragging(null);
                  setDropAt(null);
              },
              end: () => {
                  setDragging(null);
                  setDropAt(null);
              },
          }
        : null;

    /**
     * The handle's drag: pointer events, so a finger works where HTML5
     * drag-and-drop does not. The sibling rows are measured once when the
     * drag starts; the row follows the pointer, a line shows where it will
     * land, and letting go saves the move. Listeners sit on the window, so
     * a finger that strays off the handle still drives the drag.
     */
    const startPointerDrag = (event, node, parentId, index) => {
        if (!reorderable || moving || !root.current) return;
        if (event.pointerType === 'mouse' && event.button !== 0) return;

        event.preventDefault();

        const rows = [...root.current.querySelectorAll(`[data-outline-parent="${parentId ?? 'root'}"]`)];
        const mids = rows.map((row) => {
            const box = row.getBoundingClientRect();

            return box.top + box.height / 2;
        });

        const state = { id: node.id, parentId, from: index, to: index, startY: event.clientY, dy: 0 };
        setPointerDrag(state);

        const onMove = (moveEvent) => {
            const y = moveEvent.clientY;
            const to = mids.filter((mid, i) => i !== index && mid < y).length;

            state.to = to;
            state.dy = y - state.startY;
            setPointerDrag({ ...state });
        };

        const stop = (commit) => () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onCancel);
            setPointerDrag(null);

            if (commit && state.to !== state.from) {
                move(node, parentId, state.to, `outline:handle:${node.id}`);
            }
        };
        const onUp = stop(true);
        const onCancel = stop(false);

        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onCancel);
    };

    // The binder's "Book part" menu (BookPart on the server), sent by the
    // editor page. Read from the page rather than passed down, so the
    // editor's own props stay as they were.
    const partOptions = usePage().props.book_parts ?? [];

    // Naming a section's part saves on its own and reloads only the binder:
    // the chapter open in the editor is never reloaded under the author.
    const setPart = (id, part) => {
        setPartError('');

        window.axios
            .patch(route('sections.part', id), { part: part || null })
            .then(() => router.reload({ only: ['binder'] }))
            .catch((error) =>
                setPartError(error?.response?.data?.message ?? 'The book part was not saved.'),
            );
    };

    const toggle = (id) =>
        setCollapsed((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });

    // A part survives the filter when it matches, or when any child does —
    // otherwise searching for a chapter would hide the part containing it.
    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) return nodes;

        const matches = (node) => node.title.toLowerCase().includes(q);

        return nodes
            .map((node) => {
                if (matches(node)) return node;

                const children = (node.children ?? []).filter(matches);

                return children.length ? { ...node, children } : null;
            })
            .filter(Boolean);
    }, [nodes, query]);

    const row = {
        activeId,
        collapsed,
        onToggle: toggle,
        onSelect,
        partOptions,
        onSetPart: setPart,
        onMove: reorderable ? move : null,
        drag,
        reordering,
        pointerDrag,
        onHandle: startPointerDrag,
        onActions: setActions,
    };

    return (
        // w-full inside its container: the studio sizes the container (the
        // workspace's splitter, 268px by default), the phone drawer its own.
        // As a fixed width it overflowed the mobile drawer and the rows fell
        // off the side of the screen.
        <aside
            ref={root}
            data-reordering={reordering ? 'true' : undefined}
            data-hide-goals={showGoals ? undefined : 'true'}
            className="flex h-full min-h-0 w-full shrink-0 flex-col overflow-hidden rounded-panel border border-border-default bg-bg-surface"
        >
            {showTabs && (
            <div className="flex shrink-0 bg-bg-subtle" role="tablist">
                {TABS.map((t) => {
                    const isActive = tab === t.key;

                    return (
                        <button
                            key={t.key}
                            role="tab"
                            aria-selected={isActive}
                            data-a={`outline:tab:${t.key}`}
                            onClick={() => onTab(t.key)}
                            title={t.ready ? undefined : tr('common.not_built')}
                            className={`relative flex-1 py-[11px] text-[12.5px] transition ${
                                isActive
                                    ? 'bg-bg-surface font-semibold text-brand-primary'
                                    : t.ready
                                      ? 'text-text-secondary hover:text-text-primary'
                                      : 'text-text-secondary'
                            }`}
                        >
                            {tr(t.tKey)}
                            {!t.ready && (
                                <span className="ml-0.5 align-super text-[8px]">•</span>
                            )}
                            {isActive && (
                                <span className="absolute inset-x-0 bottom-0 h-[2px] bg-brand-fill" />
                            )}
                        </button>
                    );
                })}
            </div>
            )}

            {(
                <>
                    <div className={`flex shrink-0 items-center gap-2 px-3 py-3 ${showTools ? '' : 'hidden'}`}>
                        {reordering ? (
                            <p className="min-w-0 flex-1 text-[12px] leading-snug text-text-secondary">
                                Drag a handle, or use ↑ ↓.
                            </p>
                        ) : (
                            <div className="relative min-w-0 flex-1">
                                <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-text-secondary">
                                    <Icon name="search" size={14} />
                                </span>
                                <input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    data-a="outline:search"
                                    placeholder={tr('binder.search')}
                                    aria-label={tr('binder.search_label')}
                                    className="h-[31px] w-full rounded-control border border-border-input bg-bg-surface pl-[30px] pr-2 text-[12.5px] text-text-body placeholder:text-text-secondary focus:border-brand-primary focus:ring-0"
                                />
                            </div>
                        )}

                        {tab !== 'Outline' && bookId && (
                            <button
                                type="button"
                                data-a="outline:reorder"
                                onClick={() => {
                                    setQuery('');
                                    setReordering((on) => !on);
                                }}
                                aria-pressed={reordering}
                                title={reordering ? 'Done reordering' : 'Reorder chapters'}
                                className={`tap grid h-[27px] shrink-0 place-items-center rounded-control px-1.5 text-[12px] font-semibold transition ${
                                    reordering
                                        ? 'bg-brand-fill px-2.5 text-white hover:bg-brand-primary-hover'
                                        : 'w-[27px] border border-border-input text-text-secondary hover:border-brand-primary hover:text-brand-primary'
                                }`}
                            >
                                {reordering ? (
                                    'Done'
                                ) : (
                                    <>
                                        <Icon name="sort" size={15} />
                                        <span className="sr-only">Reorder chapters</span>
                                    </>
                                )}
                            </button>
                        )}

                        {!reordering && (
                            <button
                                data-a="outline:add"
                                onClick={onAddChapter}
                                disabled={adding}
                                aria-label="Add chapter"
                                className="grid h-[27px] w-[27px] shrink-0 place-items-center rounded-control bg-brand-fill text-white transition hover:bg-brand-primary-hover disabled:opacity-60"
                            >
                                <Icon name="plus" size={15} strokeWidth={2} />
                            </button>
                        )}

                        {onImport && !reordering && (
                            <button
                                data-a="outline:import"
                                onClick={onImport}
                                aria-label="Import chapters from a file"
                                title="Import chapters (Markdown or Word)"
                                className="grid h-[27px] w-[27px] shrink-0 place-items-center rounded-control border border-border-input text-text-secondary transition hover:border-brand-primary hover:text-brand-primary"
                            >
                                <Icon name="fDoc" size={15} />
                            </button>
                        )}
                    </div>

                    {/* Read out after a move, for screen-reader users. */}
                    <p aria-live="polite" className="sr-only">
                        {announcement}
                    </p>

                    {partError && (
                        <p role="alert" className="mx-3 mb-2 rounded-control bg-status-danger-bg px-2.5 py-1.5 text-[11.5px] text-status-danger">
                            {partError}
                        </p>
                    )}

                    {tab === 'Outline' ? (
                        <OutlineTable nodes={nodes} onSelect={onSelect} />
                    ) : (
                        <nav
                            aria-label={reordering ? 'Chapters, reordering' : 'Chapters'}
                            className={`flex-1 overflow-y-auto pb-3 ${pointerDrag ? 'select-none' : ''}`}
                        >
                            {visible.length === 0 ? (
                                <p className="px-4 py-6 text-center text-[11.5px] text-text-secondary">
                                    No chapters match “{query}”.
                                </p>
                            ) : (
                                visible.map((node, index) => (
                                    <TreeNode
                                        key={node.id}
                                        node={node}
                                        depth={0}
                                        parentId={null}
                                        index={index}
                                        count={visible.length}
                                        {...row}
                                    />
                                ))
                            )}
                        </nav>
                    )}
                </>
            )}

            {actions && (
                <RowActions
                    {...actions}
                    canMove={reorderable && !moving}
                    onClose={() => setActions(null)}
                    onMove={(to) => {
                        setActions(null);
                        move(actions.node, actions.parentId, to);
                    }}
                    onSetGoal={
                        onSetGoal && actions.node.type !== 'part'
                            ? () => {
                                  setActions(null);
                                  onSetGoal(actions.node.id);
                              }
                            : null
                    }
                    onRenamed={(title) => {
                        setActions(null);
                        setAnnouncement(`Renamed to ${title}.`);
                        onRenamed?.(actions.node.id, title);
                        router.reload({ only: ['binder'] });
                    }}
                    onDeleted={
                        onDeleted && actions.node.type !== 'part' && !(actions.node.children ?? []).length
                            ? () => {
                                  const { node } = actions;
                                  setActions(null);
                                  setAnnouncement(`${node.title} deleted.`);
                                  onDeleted(node.id);
                              }
                            : null
                    }
                />
            )}
        </aside>
    );
}

function TreeNode({
    node,
    depth,
    parentId = null,
    index = 0,
    count = 1,
    activeId,
    collapsed,
    onToggle,
    onSelect,
    partOptions = [],
    onSetPart,
    onMove,
    drag,
    reordering = false,
    pointerDrag = null,
    onHandle,
    onActions,
}) {
    const isPart = node.type === 'part';
    const isActive = node.id === activeId;
    const isCollapsed = collapsed.has(node.id);
    const isDropTarget = drag?.dropAt === node.id && drag?.dragging?.id !== node.id;
    const goal = !isPart && node.word_goal ? node.word_goal : null;

    // The pointer drag: this row follows the finger, and the line shows
    // where it will land among its siblings.
    const sameLevel = pointerDrag && pointerDrag.parentId === parentId;
    const isLifted = sameLevel && pointerDrag.id === node.id;
    const lineAbove = sameLevel && !isLifted && pointerDrag.to < pointerDrag.from && pointerDrag.to === index;
    const lineBelow = sameLevel && !isLifted && pointerDrag.to > pointerDrag.from && pointerDrag.to === index;

    const openActions = () => onActions?.({ node, parentId, index, count });

    // A long press with a finger opens the row's actions. The click that
    // follows the lifted finger is swallowed, so the press does not also
    // open the chapter.
    const press = useRef(null);
    const swallowClick = useRef(false);

    const cancelPress = () => {
        if (press.current) window.clearTimeout(press.current.timer);
        press.current = null;
    };

    useEffect(() => cancelPress, []);

    const onPointerDown = (event) => {
        // A new press starts clean: Android follows a long press with a
        // contextmenu event and no click, which left the flag set and ate
        // the next tap.
        swallowClick.current = false;

        if (event.pointerType !== 'touch' || reordering || !onActions) return;

        cancelPress();
        press.current = {
            x: event.clientX,
            y: event.clientY,
            timer: window.setTimeout(() => {
                press.current = null;
                swallowClick.current = true;
                navigator.vibrate?.(10);
                openActions();
            }, LONG_PRESS_MS),
        };
    };

    const onPointerMove = (event) => {
        if (!press.current) return;

        if (Math.hypot(event.clientX - press.current.x, event.clientY - press.current.y) > LONG_PRESS_SLOP) {
            cancelPress();
        }
    };

    // Alt+ArrowUp / Alt+ArrowDown on the focused row moves it; the
    // ContextMenu key or Shift+F10 opens its actions.
    const onKeyDown = (event) => {
        if (onActions && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))) {
            event.preventDefault();
            openActions();

            return;
        }

        if (!onMove) return;

        // binder.up / binder.down in the shortcut registry (Alt+↑ / Alt+↓).
        if (matches('binder.up', event) && index > 0) {
            event.preventDefault();
            onMove(node, parentId, index - 1);
        } else if (matches('binder.down', event) && index < count - 1) {
            event.preventDefault();
            onMove(node, parentId, index + 1);
        }
    };

    const moveButtons = onMove && count > 1 && (
        <span
            className={`shrink-0 items-center ${
                reordering ? 'flex gap-0.5' : 'hidden group-focus-within:flex group-hover:flex'
            }`}
        >
            <button
                type="button"
                data-a={`outline:up:${node.id}`}
                onClick={() => onMove(node, parentId, index - 1, `outline:up:${node.id}`)}
                disabled={index === 0}
                aria-label={`Move ${node.title} up`}
                title="Move up (Alt+↑)"
                className={`grid place-items-center rounded text-text-secondary hover:text-text-primary disabled:opacity-30 ${
                    reordering ? 'h-9 w-9 hover:bg-bg-subtle' : 'h-[20px] w-[18px]'
                }`}
            >
                <Icon name="chev" size={reordering ? 16 : 12} strokeWidth={2} className="rotate-180" />
            </button>
            <button
                type="button"
                data-a={`outline:down:${node.id}`}
                onClick={() => onMove(node, parentId, index + 1, `outline:down:${node.id}`)}
                disabled={index === count - 1}
                aria-label={`Move ${node.title} down`}
                title="Move down (Alt+↓)"
                className={`grid place-items-center rounded text-text-secondary hover:text-text-primary disabled:opacity-30 ${
                    reordering ? 'h-9 w-9 hover:bg-bg-subtle' : 'h-[20px] w-[18px]'
                }`}
            >
                <Icon name="chev" size={reordering ? 16 : 12} strokeWidth={2} />
            </button>
        </span>
    );

    return (
        <div>
            <div
                data-a={`outline:item:${node.id}`}
                data-outline-parent={parentId ?? 'root'}
                draggable={Boolean(drag)}
                onDragStart={
                    drag
                        ? (event) => {
                              event.dataTransfer.effectAllowed = 'move';
                              event.dataTransfer.setData('text/plain', String(node.id));
                              drag.start(node, parentId);
                          }
                        : undefined
                }
                onDragOver={drag ? (event) => drag.over(event, node, parentId) : undefined}
                onDrop={drag ? (event) => drag.drop(event, parentId, index) : undefined}
                onDragEnd={drag ? drag.end : undefined}
                onContextMenu={
                    onActions && !reordering
                        ? (event) => {
                              event.preventDefault();
                              cancelPress();
                              openActions();
                          }
                        : undefined
                }
                className={`group relative flex items-center gap-1.5 pr-2 ${reordering ? 'min-h-[44px] border-b border-border-divider' : 'h-[27px]'} ${
                    isActive && !reordering ? 'bg-outline-active-bg' : 'hover:bg-bg-subtle'
                } ${isDropTarget || lineAbove ? 'border-t-2 border-t-brand-primary' : ''} ${
                    lineBelow ? 'border-b-2 border-b-brand-primary' : ''
                } ${drag?.dragging?.id === node.id ? 'opacity-50' : ''} ${
                    isLifted ? 'z-10 bg-bg-surface shadow-popover' : ''
                }`}
                style={{
                    paddingLeft: (reordering ? 4 : 12) + depth * 14,
                    transform: isLifted ? `translateY(${pointerDrag.dy}px)` : undefined,
                }}
            >
                {reordering && onMove && count > 1 && (
                    <button
                        type="button"
                        data-a={`outline:handle:${node.id}`}
                        onPointerDown={(event) => onHandle(event, node, parentId, index)}
                        onKeyDown={(event) => {
                            // The handle answers the arrows too, for a
                            // keyboard that has found it.
                            if (event.key === 'ArrowUp' && index > 0) {
                                event.preventDefault();
                                onMove(node, parentId, index - 1, `outline:handle:${node.id}`);
                            } else if (event.key === 'ArrowDown' && index < count - 1) {
                                event.preventDefault();
                                onMove(node, parentId, index + 1, `outline:handle:${node.id}`);
                            }
                        }}
                        aria-label={`Drag to reorder ${node.title} (or press ↑ ↓)`}
                        aria-keyshortcuts="ArrowUp ArrowDown"
                        style={{ touchAction: 'none' }}
                        className="grid h-9 w-8 shrink-0 cursor-grab place-items-center rounded text-text-secondary hover:bg-bg-subtle hover:text-text-primary active:cursor-grabbing"
                    >
                        <Icon name="grip" size={16} strokeWidth={3} />
                    </button>
                )}

                {isPart ? (
                    <button
                        data-a={`outline:toggle:${node.id}`}
                        onClick={() => onToggle(node.id)}
                        aria-expanded={!isCollapsed}
                        aria-label={`${isCollapsed ? 'Expand' : 'Collapse'} ${node.title}`}
                        className="shrink-0 text-text-secondary transition hover:text-text-primary"
                    >
                        <Icon
                            name="chev"
                            size={13}
                            strokeWidth={2}
                            className={`transition-transform ${isCollapsed ? '-rotate-90' : ''}`}
                        />
                    </button>
                ) : (
                    <span className="shrink-0 text-text-secondary">
                        <Icon name="doc" size={14} />
                    </span>
                )}

                <button
                    data-a={`outline:open:${node.id}`}
                    onClick={() => {
                        if (swallowClick.current) {
                            swallowClick.current = false;

                            return;
                        }

                        if (!isPart && !reordering) onSelect(node.id);
                    }}
                    onKeyDown={onKeyDown}
                    onPointerDown={onPointerDown}
                    onPointerMove={onPointerMove}
                    onPointerUp={cancelPress}
                    onPointerCancel={cancelPress}
                    onPointerLeave={cancelPress}
                    // Once the press has opened the actions, the lifted
                    // finger must not become a tap: its emulated mousedown
                    // lands on the sheet's backdrop and shut it at once.
                    onTouchEnd={(event) => {
                        if (swallowClick.current) event.preventDefault();
                    }}
                    aria-disabled={isPart || reordering || undefined}
                    aria-keyshortcuts={onMove ? `${ariaKeys('binder.up')} ${ariaKeys('binder.down')} Shift+F10` : 'Shift+F10'}
                    // iOS shows its own callout on a long press; the row's
                    // actions are what the press is for here.
                    style={{ WebkitTouchCallout: 'none' }}
                    className={`min-w-0 flex-1 select-none truncate text-left ${
                        isPart
                            ? 'cursor-default text-[12px] font-semibold text-text-primary'
                            : isActive && !reordering
                              ? 'text-[12.5px] font-medium text-outline-active-text'
                              : `text-[12.5px] text-text-body ${reordering ? 'cursor-default' : ''}`
                    }`}
                >
                    {node.title}
                </button>

                {goal && (
                    <span
                        data-a={`outline:goal:${node.id}`}
                        title={ringLabel(node.word_count, goal)}
                        className="shrink-0"
                    >
                        <ProgressRing value={node.word_count} goal={goal} size={13} stroke={2} />
                        <span className="sr-only">{ringLabel(node.word_count, goal)}</span>
                    </span>
                )}

                {!isPart && node.word_count > 0 && !reordering && (
                    <span className="shrink-0 text-[10px] tabular-nums text-text-secondary group-focus-within:hidden group-hover:hidden">
                        {node.word_count.toLocaleString()}
                    </span>
                )}

                {moveButtons}

                {!reordering && onActions && (
                    <button
                        type="button"
                        data-a={`outline:more:${node.id}`}
                        onClick={openActions}
                        aria-haspopup="dialog"
                        aria-label={`More actions for ${node.title}`}
                        title="Rename, goal, move, delete"
                        className="hidden h-[20px] w-[18px] shrink-0 place-items-center rounded text-text-secondary hover:text-text-primary group-focus-within:grid group-hover:grid"
                    >
                        <Icon name="more" size={14} strokeWidth={3} />
                    </button>
                )}
            </div>

            {isActive && !reordering && onSetPart && partOptions.length > 0 && (
                <PartSelect node={node} depth={depth} options={partOptions} onChange={onSetPart} />
            )}

            {!isCollapsed &&
                node.children?.map((child, childIndex) => (
                    <TreeNode
                        key={child.id}
                        node={child}
                        depth={depth + 1}
                        parentId={node.id}
                        index={childIndex}
                        count={node.children.length}
                        activeId={activeId}
                        collapsed={collapsed}
                        onToggle={onToggle}
                        onSelect={onSelect}
                        partOptions={partOptions}
                        onSetPart={onSetPart}
                        onMove={onMove}
                        drag={drag}
                        reordering={reordering}
                        pointerDrag={pointerDrag}
                        onHandle={onHandle}
                        onActions={onActions}
                    />
                ))}
        </div>
    );
}

/**
 * A row's actions, from a long press, a right-click or the keyboard: a
 * centred dialog, so a bottom sheet on a phone. Rename edits in place;
 * Delete asks first and names what goes (the chapter can be restored from
 * the database, not from here).
 */
function RowActions({ node, index, count, canMove, onClose, onMove, onSetGoal, onRenamed, onDeleted }) {
    const [mode, setMode] = useState('menu');
    const [title, setTitle] = useState(node.title);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const rename = async (event) => {
        event.preventDefault();

        const next = title.trim();
        if (!next) {
            setError('A chapter needs a title.');

            return;
        }

        setBusy(true);
        setError('');

        try {
            await window.axios.patch(route('sections.update', node.id), { title: next });
            onRenamed(next);
        } catch (failure) {
            setError(failure?.response?.data?.errors?.title?.[0] ?? failure?.response?.data?.message ?? 'The chapter was not renamed.');
            setBusy(false);
        }
    };

    const remove = async () => {
        setBusy(true);
        setError('');

        try {
            await window.axios.delete(route('sections.destroy', node.id));
            onDeleted();
        } catch (failure) {
            setError(failure?.response?.data?.message ?? 'The chapter was not deleted.');
            setBusy(false);
        }
    };

    const item =
        'tap flex w-full items-center gap-3 rounded-control px-3 py-2.5 text-left text-[13.5px] text-text-body transition hover:bg-bg-subtle disabled:opacity-40 disabled:hover:bg-transparent';

    return (
        <Dialog
            label={`${node.title}: actions`}
            onClose={onClose}
            data-a="outline:actions"
            className="w-[340px] max-w-full rounded-panel bg-bg-surface p-3 shadow-popover"
        >
            <div className="flex items-center justify-between gap-2 pb-2 pl-3">
                <h2 className="min-w-0 truncate text-[13.5px] font-semibold text-text-primary">{node.title}</h2>
                <CloseButton onClick={onClose} label="Close actions" />
            </div>

            {mode === 'rename' ? (
                <form onSubmit={rename} className="space-y-2 px-3 pb-2">
                    <label htmlFor={`rename-${node.id}`} className="block text-[11.5px] text-text-secondary">
                        Title
                    </label>
                    <input
                        id={`rename-${node.id}`}
                        data-a="outline:rename:input"
                        value={title}
                        maxLength={255}
                        autoFocus
                        onChange={(event) => setTitle(event.target.value)}
                        className="h-[36px] w-full rounded-control border border-border-input bg-bg-surface px-2.5 text-[13px] text-text-body focus:border-brand-primary focus:ring-0"
                    />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setMode('menu')} className="tap rounded-control px-3 py-1.5 text-[12.5px] text-text-secondary hover:text-text-primary">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            data-a="outline:rename:save"
                            disabled={busy}
                            className="tap rounded-control bg-brand-fill px-3 py-1.5 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                        >
                            Rename
                        </button>
                    </div>
                </form>
            ) : mode === 'delete' ? (
                <div className="space-y-3 px-3 pb-2">
                    <p className="text-[12.5px] leading-relaxed text-text-body">
                        Delete “{node.title}” and its {(node.word_count ?? 0).toLocaleString()} words? It leaves the
                        book and every export.
                    </p>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setMode('menu')} className="tap rounded-control px-3 py-1.5 text-[12.5px] text-text-secondary hover:text-text-primary">
                            Keep it
                        </button>
                        <button
                            type="button"
                            data-a="outline:delete:confirm"
                            onClick={remove}
                            disabled={busy}
                            className="tap rounded-control bg-status-danger-fill px-3 py-1.5 text-[12.5px] font-semibold text-white disabled:opacity-60"
                        >
                            Delete chapter
                        </button>
                    </div>
                </div>
            ) : (
                <div role="group" aria-label="Actions" className="space-y-0.5">
                    <button type="button" data-a="outline:action:rename" className={item} onClick={() => setMode('rename')}>
                        <Icon name="aLine" size={16} /> Rename
                    </button>
                    {onSetGoal && (
                        <button type="button" data-a="outline:action:goal" className={item} onClick={onSetGoal}>
                            <ProgressRing value={node.word_count} goal={node.word_goal} size={16} stroke={2} /> Set goal
                        </button>
                    )}
                    <button
                        type="button"
                        data-a="outline:action:up"
                        className={item}
                        disabled={!canMove || index === 0}
                        onClick={() => onMove(index - 1)}
                    >
                        <Icon name="chev" size={16} strokeWidth={2} className="rotate-180" /> Move up
                    </button>
                    <button
                        type="button"
                        data-a="outline:action:down"
                        className={item}
                        disabled={!canMove || index >= count - 1}
                        onClick={() => onMove(index + 1)}
                    >
                        <Icon name="chev" size={16} strokeWidth={2} /> Move down
                    </button>
                    {onDeleted && (
                        <button
                            type="button"
                            data-a="outline:action:delete"
                            className={`${item} text-status-danger`}
                            onClick={() => setMode('delete')}
                        >
                            <Icon name="x" size={16} /> Delete
                        </button>
                    )}
                </div>
            )}

            {error && (
                <p role="alert" className="mx-3 mt-2 text-[11.5px] text-status-danger">
                    {error}
                </p>
            )}
        </Dialog>
    );
}

const PART_GROUPS = [
    ['front', 'Front matter'],
    ['body', 'Text'],
    ['back', 'Back matter'],
];

/**
 * Which conventional part the open section is (Preface, Epilogue, Index…).
 * Shown under the active row only, so the binder stays one line per
 * section; keyboard users reach it by Tab from the row.
 */
function PartSelect({ node, depth, options, onChange }) {
    useI18n();

    return (
        <div className="flex items-center gap-1.5 pb-1.5 pr-2" style={{ paddingLeft: 32 + depth * 14 }}>
            <label htmlFor={`part-${node.id}`} className="shrink-0 text-[10.5px] text-text-secondary">
                {tr('binder.book_part')}
            </label>
            <select
                id={`part-${node.id}`}
                value={node.part ?? ''}
                data-a={`outline:part:${node.id}`}
                onChange={(event) => onChange(node.id, event.target.value)}
                className="h-[24px] min-w-0 flex-1 rounded-control border border-border-input bg-bg-surface px-1.5 py-0 text-[11px] text-text-body focus:border-brand-primary focus:ring-0"
            >
                <option value="">Not set</option>
                {PART_GROUPS.map(([group, label]) => (
                    <optgroup key={group} label={label}>
                        {options
                            .filter((option) => option.group === group)
                            .map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                    </optgroup>
                ))}
            </select>
        </div>
    );
}

/** Flat chapter table with live word counts. */
function OutlineTable({ nodes, onSelect }) {
    const rows = [];

    const walk = (list, part) =>
        list.forEach((node) => {
            if (node.type === 'part') {
                walk(node.children ?? [], node.title);
            } else {
                rows.push({ ...node, part });
            }
        });

    walk(nodes, null);

    if (rows.length === 0) {
        return (
            <p className="px-4 py-6 text-center text-[11.5px] text-text-secondary">
                No chapters yet.
            </p>
        );
    }

    return (
        <div className="flex-1 overflow-y-auto px-2 pb-3">
            <table className="w-full border-collapse">
                <thead>
                    <tr className="text-left text-[10px] uppercase tracking-wide text-text-secondary">
                        <th className="px-2 pb-1.5 font-medium">Chapter</th>
                        <th className="px-2 pb-1.5 text-right font-medium">Words</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            className="cursor-pointer border-t border-border-divider hover:bg-bg-subtle"
                            onClick={() => onSelect(row.id)}
                            data-a={`outline:row:${row.id}`}
                        >
                            <td className="px-2 py-1.5">
                                <span className="block truncate text-[12px] text-text-body">
                                    {row.title}
                                </span>
                                {row.part && (
                                    <span className="block truncate text-[10px] text-text-secondary">
                                        {row.part}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-1.5 text-right text-[11px] tabular-nums text-text-secondary">
                                {(row.word_count ?? 0).toLocaleString()}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
