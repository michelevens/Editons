import { router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Icon from './Icon';

/**
 * The left panel: Manuscript tree, Outline table, Notes.
 *
 * HANDOFF.md component 3. Rows are h27, parts collapse, the active row uses
 * the outline tokens, and search hides parts with no matches.
 */

const TABS = [
    { key: 'Manuscript', ready: true },
    { key: 'Outline', ready: true },
    // Opens the Notes screen: the notes are the book's, not the outline's,
    // and this tab said "not built" long after that screen existed.
    { key: 'Notes', ready: true },
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
}) {
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState(() => new Set());
    const [partError, setPartError] = useState('');

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

    return (
        // w-full inside its container, capped at the design's 268px. As a
        // fixed width it overflowed the mobile drawer and the rows fell off
        // the side of the screen.
        <aside className="flex h-full min-h-0 w-full max-w-[268px] shrink-0 flex-col overflow-hidden rounded-panel border border-border-default bg-bg-surface md:w-[268px]">
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
                            title={t.ready ? undefined : 'Not built yet'}
                            className={`relative flex-1 py-[11px] text-[12.5px] transition ${
                                isActive
                                    ? 'bg-bg-surface font-semibold text-brand-primary'
                                    : t.ready
                                      ? 'text-text-secondary hover:text-text-primary'
                                      : 'text-text-secondary/60'
                            }`}
                        >
                            {t.key}
                            {!t.ready && (
                                <span className="ml-0.5 align-super text-[8px]">•</span>
                            )}
                            {isActive && (
                                <span className="absolute inset-x-0 bottom-0 h-[2px] bg-brand-primary" />
                            )}
                        </button>
                    );
                })}
            </div>

            {(
                <>
                    <div className="flex shrink-0 items-center gap-2 px-3 py-3">
                        <div className="relative flex-1">
                            <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-text-secondary">
                                <Icon name="search" size={14} />
                            </span>
                            <input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                data-a="outline:search"
                                placeholder="Search chapters..."
                                aria-label="Search chapters"
                                className="h-[31px] w-full rounded-control border border-border-input bg-bg-surface pl-[30px] pr-2 text-[12.5px] text-text-body placeholder:text-text-secondary focus:border-brand-primary focus:ring-0"
                            />
                        </div>

                        <button
                            data-a="outline:add"
                            onClick={onAddChapter}
                            disabled={adding}
                            aria-label="Add chapter"
                            className="grid h-[27px] w-[27px] shrink-0 place-items-center rounded-control bg-brand-primary text-white transition hover:bg-brand-primary-hover disabled:opacity-60"
                        >
                            <Icon name="plus" size={15} strokeWidth={2} />
                        </button>

                        {onImport && (
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

                    {partError && (
                        <p role="alert" className="mx-3 mb-2 rounded-control bg-status-danger-bg px-2.5 py-1.5 text-[11.5px] text-status-danger">
                            {partError}
                        </p>
                    )}

                    {tab === 'Outline' ? (
                        <OutlineTable nodes={nodes} onSelect={onSelect} />
                    ) : (
                        <nav className="flex-1 overflow-y-auto pb-3">
                            {visible.length === 0 ? (
                                <p className="px-4 py-6 text-center text-[11.5px] text-text-secondary">
                                    No chapters match “{query}”.
                                </p>
                            ) : (
                                visible.map((node) => (
                                    <TreeNode
                                        key={node.id}
                                        node={node}
                                        depth={0}
                                        activeId={activeId}
                                        collapsed={collapsed}
                                        onToggle={toggle}
                                        onSelect={onSelect}
                                        partOptions={partOptions}
                                        onSetPart={setPart}
                                    />
                                ))
                            )}
                        </nav>
                    )}
                </>
            )}
        </aside>
    );
}

function TreeNode({ node, depth, activeId, collapsed, onToggle, onSelect, partOptions = [], onSetPart }) {
    const isPart = node.type === 'part';
    const isActive = node.id === activeId;
    const isCollapsed = collapsed.has(node.id);

    return (
        <div>
            <div
                className={`group flex h-[27px] items-center gap-1.5 pr-2 ${
                    isActive ? 'bg-outline-active-bg' : 'hover:bg-bg-subtle'
                }`}
                style={{ paddingLeft: 12 + depth * 14 }}
            >
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
                    onClick={() => !isPart && onSelect(node.id)}
                    disabled={isPart}
                    className={`min-w-0 flex-1 truncate text-left ${
                        isPart
                            ? 'cursor-default text-[12px] font-semibold text-text-primary'
                            : isActive
                              ? 'text-[12.5px] font-medium text-outline-active-text'
                              : 'text-[12.5px] text-text-body'
                    }`}
                >
                    {node.title}
                </button>

                {!isPart && node.word_count > 0 && (
                    <span className="shrink-0 text-[10px] tabular-nums text-text-secondary">
                        {node.word_count.toLocaleString()}
                    </span>
                )}
            </div>

            {isActive && onSetPart && partOptions.length > 0 && (
                <PartSelect node={node} depth={depth} options={partOptions} onChange={onSetPart} />
            )}

            {!isCollapsed &&
                node.children?.map((child) => (
                    <TreeNode
                        key={child.id}
                        node={child}
                        depth={depth + 1}
                        activeId={activeId}
                        collapsed={collapsed}
                        onToggle={onToggle}
                        onSelect={onSelect}
                        partOptions={partOptions}
                        onSetPart={onSetPart}
                    />
                ))}
        </div>
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
    return (
        <div className="flex items-center gap-1.5 pb-1.5 pr-2" style={{ paddingLeft: 32 + depth * 14 }}>
            <label htmlFor={`part-${node.id}`} className="shrink-0 text-[10.5px] text-text-secondary">
                Book part
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
