import { Extension, Node } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { useMemo, useState } from 'react';

/**
 * Numbering and cross-references in the editor.
 *
 * The same rules as App\Publishing\Numbering on the server, so the chapter
 * on screen numbers exactly as it exports: a bold "Table 4.2" or "Figure
 * 3.1" opening a paragraph is a caption, renumbered from its position; a
 * display equation's \tag is renumbered when it is a plain number (or "?");
 * captions, numbered equations and headings carry a label that references
 * point at. The chapter's prefix ("4.") and running offsets come from the
 * server, which alone knows where this chapter sits in the book; until they
 * arrive nothing is renumbered.
 */
export const CAPTION = /^(Table|Figure)\s+((?:[A-Z]\.?)?\d+(?:[.-]\d+)*|\?)\.?$/;
const AUTO_TAG = /^(?:(?:[A-Z]\.?)?\d+(?:\.\d+)?|\?)$/;
const TAG = /\\tag\{([^{}]*)\}/;
const PREFIX = { table: 'tbl', figure: 'fig', equation: 'eq', section: 'sec' };

const key = new PluginKey('crossRef');

export const newLabel = (kind) => `${PREFIX[kind]}-${Math.random().toString(36).slice(2, 10)}`;

function captionKind(node) {
    if (node.type.name !== 'paragraph') return null;

    const first = node.firstChild;

    if (!first?.isText || !first.marks.some((mark) => mark.type.name === 'bold')) return null;

    const match = first.text.trim().match(CAPTION);

    return match ? [match[1], match[2]] : null;
}

/** Labelled things in this chapter, as a reference would read them. */
export function localTargets(doc) {
    const targets = [];

    doc.forEach((node) => {
        const label = node.attrs?.label;

        if (!label) return;

        const caption = captionKind(node);

        if (caption) {
            targets.push({ label, kind: caption[0].toLowerCase(), text: `${caption[0]} ${caption[1]}` });
        } else if (node.type.name === 'blockMath' && TAG.test(node.attrs.latex ?? '')) {
            targets.push({ label, kind: 'equation', text: `Equation (${node.attrs.latex.match(TAG)[1]})` });
        } else if (node.type.name === 'heading') {
            const number = node.textContent.match(/^(\d+(?:\.\d+)+)\s/);
            targets.push({
                label,
                kind: 'section',
                text: number ? `Section ${number[1]}` : `“${node.textContent.slice(0, 60)}”`,
            });
        }
    });

    return targets;
}

/** The changes that renumber, label and refresh references in a doc. */
function corrections(doc, place, remote, schema) {
    const prefix = place.scheme === 'continuous' ? '' : place.prefix;
    const counts = { table: 0, figure: 0, equation: 0 };
    const changes = [];

    doc.forEach((node, offset) => {
        const caption = captionKind(node);
        let attrs = null;

        if (caption) {
            const kind = caption[0].toLowerCase();
            const number = `${prefix}${(place.offsets?.[kind] ?? 0) + ++counts[kind]}`;
            const first = node.firstChild;
            const stop = first.text.trim().endsWith('.') ? '.' : '';
            const wanted = `${caption[0]} ${number}${stop}`;

            if (first.text !== wanted) {
                changes.push({ from: offset + 1, to: offset + 1 + first.nodeSize, text: schema.text(wanted, first.marks) });
            }

            if (!node.attrs.label) attrs = { ...node.attrs, label: newLabel(kind) };
        } else if (node.type.name === 'blockMath') {
            const latex = node.attrs.latex ?? '';
            const tag = latex.match(TAG);

            if (tag) {
                let next = latex;

                if (AUTO_TAG.test(tag[1].trim())) {
                    const number = `${prefix}${(place.offsets?.equation ?? 0) + ++counts.equation}`;
                    next = latex.replace(TAG, `\\tag{${number}}`);
                }

                if (next !== latex || !node.attrs.label) {
                    attrs = { ...node.attrs, latex: next, label: node.attrs.label || newLabel('equation') };
                }
            }
        } else if (node.type.name === 'heading' && !node.attrs.label) {
            attrs = { ...node.attrs, label: newLabel('section') };
        }

        if (attrs) changes.push({ at: offset, attrs });
    });

    return changes;
}

export const CrossRef = Extension.create({
    name: 'crossRef',

    addStorage() {
        return { place: null, remote: [] };
    },

    addGlobalAttributes() {
        return [
            {
                types: ['paragraph', 'heading', 'blockMath'],
                attributes: {
                    label: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('data-label'),
                        renderHTML: (attributes) => (attributes.label ? { 'data-label': attributes.label } : {}),
                    },
                },
            },
        ];
    },

    addProseMirrorPlugins() {
        const storage = this.storage;

        return [
            new Plugin({
                key,
                appendTransaction(transactions, oldState, newState) {
                    if (!storage.place) return null;
                    if (!transactions.some((tr) => tr.docChanged || tr.getMeta(key))) return null;

                    const changes = corrections(newState.doc, storage.place, storage.remote, newState.schema);
                    const tr = newState.tr;

                    // From the end, so earlier positions stay valid.
                    changes
                        .sort((a, b) => (b.from ?? b.at) - (a.from ?? a.at))
                        .forEach((change) => {
                            if (change.text) {
                                tr.replaceWith(change.from, change.to, change.text);
                            } else {
                                tr.setNodeMarkup(change.at, undefined, change.attrs);
                            }
                        });

                    // References read their target's current text.
                    const known = new Map(
                        [...storage.remote, ...localTargets(tr.doc)].map((target) => [target.label, target.text]),
                    );

                    tr.doc.descendants((node, pos) => {
                        if (node.type.name !== 'xref') return;

                        const text = known.get(node.attrs.target);

                        if (text && text !== node.attrs.text) {
                            tr.setNodeMarkup(pos, undefined, { ...node.attrs, text });
                        }
                    });

                    return tr.docChanged ? tr.setMeta('addToHistory', false) : null;
                },
            }),
        ];
    },
});

/** Ask the plugin to run now: the chapter's place in the book has arrived. */
export function refreshNumbering(editor) {
    editor?.view?.dispatch(editor.state.tr.setMeta(key, true));
}

export const Xref = Node.create({
    name: 'xref',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes() {
        return {
            target: { default: '', rendered: false },
            // The words last resolved, kept so a reader of the raw document,
            // and an export without the book's numbering, still sees them.
            text: { default: '', rendered: false },
        };
    },

    parseHTML() {
        return [{
            tag: 'a[data-type="xref"]',
            getAttrs: (el) => ({ target: el.getAttribute('data-target'), text: el.textContent }),
        }];
    },

    renderHTML({ node }) {
        return ['a', { 'data-type': 'xref', 'data-target': node.attrs.target }, node.attrs.text || 'reference'];
    },

    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('span');
            dom.className = 'xref';
            dom.dataset.type = 'xref';
            dom.textContent = node.attrs.text || '[missing reference]';
            dom.title = 'Cross-reference: updates when its target is renumbered';

            return {
                dom,
                update: (next) => {
                    if (next.type.name !== 'xref') return false;
                    dom.textContent = next.attrs.text || '[missing reference]';

                    return true;
                },
            };
        };
    },
});

const KIND_NAMES = { table: 'Tables', figure: 'Figures', equation: 'Equations', section: 'Sections', chapter: 'Chapters' };

/** Pick what to refer to: anything numbered or headed, in any chapter. */
export function XrefDialog({ local, remote, onInsert, onClose }) {
    const [query, setQuery] = useState('');

    const groups = useMemo(() => {
        const all = [
            ...local.map((t) => ({ ...t, where: 'This chapter' })),
            ...remote.map((t) => ({ ...t, where: t.section_title })),
        ].filter((t) => `${t.text} ${t.where}`.toLowerCase().includes(query.toLowerCase()));

        return Object.keys(KIND_NAMES)
            .map((kind) => [kind, all.filter((t) => t.kind === kind)])
            .filter(([, items]) => items.length);
    }, [local, remote, query]);

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-black/30 p-4"
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Insert cross-reference"
                className="flex max-h-[80vh] w-full max-w-[560px] flex-col rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
                onKeyDown={(event) => event.key === 'Escape' && onClose()}
            >
                <h3 className="text-[15px] font-semibold text-text-primary">Insert cross-reference</h3>
                <p className="mt-1 text-[12px] text-text-secondary">
                    The reference follows its target: move or add a table and the number updates.
                </p>
                <input
                    autoFocus
                    value={query}
                    data-a="xref:search"
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search tables, figures, equations, sections…"
                    className="mt-3 h-[34px] rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0"
                />
                <div className="mt-3 flex-1 overflow-y-auto">
                    {groups.length === 0 && (
                        <p className="py-6 text-center text-[12.5px] text-text-secondary">
                            Nothing to refer to yet. Captions written as a bold “Table 1.1” or
                            “Figure 1.1”, numbered equations and headings appear here.
                        </p>
                    )}
                    {groups.map(([kind, items]) => (
                        <div key={kind} className="mb-3">
                            <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-text-secondary">
                                {KIND_NAMES[kind]}
                            </p>
                            {items.map((target) => (
                                <button
                                    key={target.label}
                                    type="button"
                                    data-a={`xref:pick:${target.label}`}
                                    onClick={() => onInsert(target)}
                                    className="flex w-full items-baseline justify-between gap-3 rounded-control px-2.5 py-1.5 text-left text-[13px] hover:bg-bg-subtle"
                                >
                                    <span className="font-medium text-text-primary">{target.text}</span>
                                    <span className="truncate text-[11.5px] text-text-secondary">{target.where}</span>
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
