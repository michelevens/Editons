import { Extension, Mark, mergeAttributes } from '@tiptap/core';
import { isHistoryTransaction } from '@tiptap/pm/history';
import { Fragment, Slice } from '@tiptap/pm/model';
import { Plugin, PluginKey, Selection } from '@tiptap/pm/state';
import { Mapping, ReplaceStep } from '@tiptap/pm/transform';

/**
 * Suggest mode: tracked changes, the way Word and Google Docs keep them.
 *
 * While it is on, nothing typed or deleted changes the manuscript outright.
 * Typing carries an `insertion` mark; deleting wraps the text in a
 * `deletion` mark and leaves it where it was. Each mark names its change
 * (one id for a replacement: the struck words and the words typed over
 * them), its author and when. Both are ordinary marks in the ProseMirror
 * JSON, so they autosave, version and reload like any other text, and the
 * server's renderers read them as rejected until somebody decides
 * (App\Publishing\Suggestions).
 *
 * Accepting and rejecting are document transforms here, saved through the
 * normal save path with reason `review`.
 *
 * Not tracked: formatting (bold, headings, lists), paragraph breaks made or
 * joined, and pictures, tables, charts and display equations. Those are
 * structure, which marks cannot carry; adding one applies directly, and
 * removing one is refused while suggesting rather than done silently.
 */

export const SuggestKey = new PluginKey('suggest');

const changeAttributes = () => ({
    id: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-change'),
        renderHTML: (attributes) => ({ 'data-change': attributes.id }),
    },
    author: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-author') ?? '',
        renderHTML: (attributes) => ({ 'data-author': attributes.author }),
    },
    authorId: {
        default: null,
        parseHTML: (element) => Number(element.getAttribute('data-author-id')) || null,
        renderHTML: (attributes) => ({ 'data-author-id': attributes.authorId }),
    },
    at: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-at'),
        renderHTML: (attributes) => ({ 'data-at': attributes.at }),
    },
});

/** Text proposed for the book: underlined until accepted. */
export const Insertion = Mark.create({
    name: 'insertion',
    // Typing beside a suggestion is not part of it unless Suggest mode says
    // so; the mark is added explicitly, never inherited.
    inclusive: false,
    excludes: 'insertion deletion',
    addAttributes: changeAttributes,
    parseHTML: () => [{ tag: 'ins[data-change]' }],
    renderHTML: ({ HTMLAttributes }) => ['ins', mergeAttributes(HTMLAttributes, { class: 'suggest-ins' }), 0],
});

/** Text proposed for removal: struck through, still in the book. */
export const Deletion = Mark.create({
    name: 'deletion',
    inclusive: false,
    excludes: 'insertion deletion',
    addAttributes: changeAttributes,
    parseHTML: () => [{ tag: 'del[data-change]' }],
    renderHTML: ({ HTMLAttributes }) => ['del', mergeAttributes(HTMLAttributes, { class: 'suggest-del' }), 0],
});

const SUGGESTION = new Set(['insertion', 'deletion']);

/** Metadata ProseMirror and Tiptap read off a transaction after dispatch. */
const CARRIED_META = ['paste', 'uiEvent', 'composition', 'pointer', 'inputType'];

/** Block content a mark cannot stand for, so a suggestion cannot remove. */
const STRUCTURE = new Set(['table', 'tableRow']);

export function newChangeId() {
    return `s${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
}

/** A slice with any suggestion marks taken off. */
function stripFragment(fragment) {
    const nodes = [];

    fragment.forEach((node) => {
        let next = node.isText || node.isInline
            ? node.mark(node.marks.filter((mark) => !SUGGESTION.has(mark.type.name)))
            : node;

        if (next.content.size) next = next.copy(stripFragment(next.content));

        nodes.push(next);
    });

    return Fragment.from(nodes);
}

function stripSlice(slice) {
    return new Slice(stripFragment(slice.content), slice.openStart, slice.openEnd);
}

/** Does [from, to) hold any inline content: text, a footnote, an equation? */
function hasInline(doc, from, to) {
    let found = false;

    doc.nodesBetween(from, to, (node) => {
        if (found) return false;
        if (node.isInline) found = true;

        return !found;
    });

    return found;
}

/** Does [from, to) wholly contain a picture, chart, table or the like? */
function hasStructure(doc, from, to) {
    let found = false;

    doc.nodesBetween(from, to, (node, pos) => {
        if (found) return false;

        const inside = pos >= from && pos + node.nodeSize <= to;

        if (inside && ((node.isLeaf && !node.isInline) || STRUCTURE.has(node.type.name))) found = true;

        return !found;
    });

    return found;
}

/** Should this transaction go through untouched? */
function untracked(tr) {
    return !tr.docChanged
        || tr.getMeta(SuggestKey)?.skip
        // Undo and redo replay what was tracked; tracking them again would
        // strike through the undo.
        || isHistoryTransaction(tr)
        // setContent on a chapter switch, numbering refreshes.
        || tr.getMeta('preventUpdate')
        || tr.getMeta('addToHistory') === false;
}

/**
 * Rewrite an editing transaction as a suggestion.
 *
 * Each replace step is taken apart: the text it would delete is marked
 * instead (or really removed, when it was itself a pending insertion), and
 * the text it would insert goes in after that, marked. Every other step —
 * a join, a list change, a mark — is replayed as it was.
 *
 * Returns null when there is nothing to track, and `{ refused: true }` in
 * the transaction's meta when a step tried to remove structure.
 */
export function track(state, tr, user) {
    const { insertion, deletion } = state.schema.marks;
    const out = state.tr;
    const now = new Date().toISOString();
    let tracked = false;
    let refused = false;
    // Where the caret goes: a position in out.doc and the step count then.
    let caret = null;

    for (let i = 0; i < tr.steps.length; i += 1) {
        const step = tr.steps[i];
        const doc = tr.docs[i];
        // Step i is written against tr.docs[i]; out has diverged from it.
        // Built by hand: Mapping.slice(0, i).invert() inverts every map in
        // the transaction, not the first i (prosemirror-transform 1.12),
        // which put the first deletion one letter to the right.
        const back = new Mapping();
        for (let j = i - 1; j >= 0; j -= 1) back.appendMap(tr.mapping.maps[j].invert());
        const toOut = (pos, assoc) => out.mapping.map(back.map(pos, assoc), assoc);
        const replay = () => {
            const mapping = new Mapping();
            mapping.appendMapping(back);
            mapping.appendMapping(out.mapping);
            const mapped = step.map(mapping);

            if (mapped) out.maybeStep(mapped);
        };

        if (!(step instanceof ReplaceStep) || step.structure) {
            replay();
            continue;
        }

        const { from, to, slice } = step;
        const deletes = from < to && hasInline(doc, from, to);
        const inserts = slice.size > 0 && hasInline(slice.content, 0, slice.content.size);

        // A paragraph break made or joined: structure, applied directly.
        if (!deletes && !inserts && !(from < to && hasStructure(doc, from, to))) {
            replay();
            continue;
        }

        if (from < to && hasStructure(doc, from, to)) {
            refused = true;
            continue;
        }

        const F = toOut(from, 1);
        const T = Math.max(F, toOut(to, -1));

        // Code blocks take no marks: what is typed there is not tracked.
        if (!out.doc.resolve(F).parent.type.allowsMarkType(deletion)) {
            replay();
            continue;
        }

        tracked = true;

        // The deleted range: pending insertions go for real, the rest is
        // marked, and text already struck stays as it is.
        const remove = [];
        const strike = [];

        if (F < T) {
            out.doc.nodesBetween(F, T, (node, pos) => {
                if (!node.isInline) return true;

                const a = Math.max(pos, F);
                const b = Math.min(pos + node.nodeSize, T);

                if (a < b) {
                    if (insertion.isInSet(node.marks)) remove.push([a, b]);
                    else if (!deletion.isInSet(node.marks)) strike.push([a, b]);
                }

                return false;
            });
        }

        // One change per run of edits by one person: a word deleted letter
        // by letter, or typed letter by letter, is one suggestion.
        let id = null;
        let at = now;
        const adopt = (mark) => {
            if (!id && mark && mark.attrs.authorId === (user?.id ?? null) && mark.attrs.id) {
                id = mark.attrs.id;
                at = mark.attrs.at ?? now;
            }
        };

        if (strike.length) {
            adopt(deletion.isInSet(out.doc.resolve(F).nodeBefore?.marks ?? []));
            adopt(deletion.isInSet(out.doc.resolve(T).nodeAfter?.marks ?? []));
        }

        const attrs = () => ({ id: id ?? (id = newChangeId()), author: user?.name ?? '', authorId: user?.id ?? null, at });

        if (strike.length) {
            const mark = deletion.create(attrs());
            strike.forEach(([a, b]) => out.addMark(a, b, mark));
        }

        const removedFrom = out.steps.length;
        remove.slice().reverse().forEach(([a, b]) => out.delete(a, b));
        const afterRemove = out.mapping.slice(removedFrom);

        const start = afterRemove.map(F, -1);
        const end = afterRemove.map(T, 1);

        if (inserts || slice.size) {
            const at0 = out.steps.length;
            out.replace(end, end, stripSlice(slice));
            const placed = out.mapping.slice(at0);
            const a = placed.map(end, -1);
            const b = placed.map(end, 1);

            if (b > a && inserts) {
                adopt(insertion.isInSet(out.doc.resolve(a).nodeBefore?.marks ?? []));
                out.addMark(a, b, insertion.create(attrs()));
            }

            caret = { pos: b, steps: out.steps.length };
        } else {
            // Backspace leaves the caret before what it struck, so the next
            // Backspace reaches the letter before; Delete and a selection
            // leave it after.
            const sel = state.selection;
            const backspace = i === 0 && sel.empty && sel.from === to;

            caret = { pos: backspace ? start : end, steps: out.steps.length };
        }
    }

    if (!tracked && !refused) return null;

    if (caret) {
        const pos = out.mapping.slice(caret.steps).map(caret.pos);
        out.setSelection(Selection.near(out.doc.resolve(Math.min(pos, out.doc.content.size))));
    }

    CARRIED_META.forEach((key) => {
        const value = tr.getMeta(key);

        if (value !== undefined) out.setMeta(key, value);
    });

    if (tr.scrolledIntoView) out.scrollIntoView();
    if (refused) out.setMeta(SuggestKey, { refused: true });

    return out;
}

/**
 * The pending changes in a document, in reading order.
 *
 * @returns {{id: string, author: string, authorId: ?number, at: ?string, from: number, to: number, inserted: string, deleted: string, kind: 'insert'|'delete'|'replace'}[]}
 */
export function pendingChanges(doc) {
    const changes = new Map();

    doc.descendants((node, pos) => {
        if (!node.isInline) return true;

        node.marks.forEach((mark) => {
            if (!SUGGESTION.has(mark.type.name)) return;

            const id = mark.attrs.id ?? `${mark.type.name}-${pos}`;
            let change = changes.get(id);

            if (!change) {
                change = {
                    id,
                    author: mark.attrs.author || 'Someone',
                    authorId: mark.attrs.authorId,
                    at: mark.attrs.at,
                    from: pos,
                    to: pos,
                    inserted: '',
                    deleted: '',
                };
                changes.set(id, change);
            }

            const key = mark.type.name === 'insertion' ? 'inserted' : 'deleted';
            const gap = change[key] && change.to !== pos ? ' ' : '';

            change[key] += gap + (node.isText ? node.text : '…');
            change.to = pos + node.nodeSize;
        });

        return false;
    });

    return [...changes.values()].map((change) => ({
        ...change,
        kind: change.inserted && change.deleted ? 'replace' : change.inserted ? 'insert' : 'delete',
    }));
}

/**
 * Accept or reject changes: `ids` null for all of them.
 *
 * Accepting keeps inserted text (unmarked) and removes struck text;
 * rejecting does the opposite. A paragraph that was all suggestion and is
 * now empty goes with it. The transaction is never itself tracked.
 */
export function decide(state, ids, accept) {
    const wanted = ids ? new Set(ids) : null;
    const ranges = [];

    state.doc.descendants((node, pos) => {
        if (!node.isInline) return true;

        node.marks.forEach((mark) => {
            if (!SUGGESTION.has(mark.type.name)) return;
            if (wanted && !wanted.has(mark.attrs.id)) return;

            ranges.push({ from: pos, to: pos + node.nodeSize, type: mark.type, insertion: mark.type.name === 'insertion' });
        });

        return false;
    });

    const tr = state.tr;

    if (!ranges.length) return null;

    // Marks off first: no position moves.
    ranges
        .filter((range) => range.insertion === accept)
        .forEach((range) => tr.removeMark(range.from, range.to, range.type));

    // Then the text that goes, last first, adjacent runs joined.
    const gone = [];

    ranges
        .filter((range) => range.insertion !== accept)
        .sort((a, b) => a.from - b.from)
        .forEach((range) => {
            const last = gone[gone.length - 1];

            if (last && last.to === range.from) last.to = range.to;
            else gone.push({ from: range.from, to: range.to });
        });

    gone.reverse().forEach(({ from, to }) => {
        const $from = tr.doc.resolve(from);
        const $to = tr.doc.resolve(to);
        const parent = $from.parent;
        const whole = parent === $to.parent && parent.isTextblock
            && $from.parentOffset === 0 && $to.parentOffset === parent.content.size
            && $from.depth > 0 && $from.node($from.depth - 1).childCount > 1;

        if (whole) tr.delete($from.before(), $from.after());
        else tr.delete(from, to);
    });

    return tr.setMeta(SuggestKey, { skip: true });
}

/**
 * The extension: both marks, and the hook that rewrites editing
 * transactions while `editor.storage.suggest.enabled` is on.
 *
 * `onRefused` is told when a deletion of structure was refused, so the page
 * can say why nothing happened.
 */
export const Suggest = Extension.create({
    name: 'suggest',

    addOptions() {
        return { onRefused: null };
    },

    addStorage() {
        return { enabled: false, user: null };
    },

    addExtensions() {
        return [Insertion, Deletion];
    },

    dispatchTransaction({ transaction, next }) {
        if (!this.storage.enabled || untracked(transaction)) {
            next(transaction);

            return;
        }

        const tracked = track(this.editor.state, transaction, this.storage.user);

        if (tracked?.getMeta(SuggestKey)?.refused) this.options.onRefused?.();

        next(tracked ?? transaction);
    },

    addProseMirrorPlugins() {
        const storage = this.storage;

        return [
            new Plugin({
                key: SuggestKey,
                props: {
                    // Backspace and Delete inside a paragraph are left to the
                    // browser by ProseMirror, which reads the change back from
                    // the page after the letter has gone from the screen.
                    // While suggesting they are done here instead, as a
                    // transaction the hook above can track. At a paragraph's
                    // edge the usual join runs (not tracked).
                    handleKeyDown: (view, event) => {
                        if (!storage.enabled || (event.key !== 'Backspace' && event.key !== 'Delete')) return false;
                        if (event.metaKey || event.ctrlKey || event.altKey) return false;

                        const { state } = view;
                        const { selection } = state;

                        if (!selection.empty) {
                            view.dispatch(state.tr.deleteSelection().scrollIntoView());

                            return true;
                        }

                        const $pos = selection.$from;
                        const back = event.key === 'Backspace';

                        if (!$pos.parent.isTextblock) return false;
                        if (back ? $pos.parentOffset === 0 : $pos.parentOffset === $pos.parent.content.size) return false;

                        // One character: two code units for a surrogate pair.
                        const near = back
                            ? state.doc.textBetween(Math.max($pos.start(), $pos.pos - 2), $pos.pos, '', '￼')
                            : state.doc.textBetween($pos.pos, Math.min($pos.end(), $pos.pos + 2), '', '￼');
                        const width = near.length === 2 && /^[\ud800-\udbff][\udc00-\udfff]$/.test(near) ? 2 : 1;
                        const from = back ? $pos.pos - width : $pos.pos;

                        view.dispatch(state.tr.delete(from, from + width).scrollIntoView());

                        return true;
                    },
                    // Copied text arrives as plain text: a pending change is
                    // a proposal about one place, not something to carry to
                    // another. In Suggest mode the paste is then marked.
                    transformPasted: (slice) => stripSlice(slice),
                },
            }),
        ];
    },
});
