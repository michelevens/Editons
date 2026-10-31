import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Find and replace in the open chapter (ADR — Author walkthrough fixes).
 *
 * Matches are found in runs of adjacent text inside one block, so a phrase
 * that is half bold is still found; never across two paragraphs. The same
 * rule as the server's manuscript-wide replace (App\Manuscript\FindReplace),
 * so "3 in this chapter" here and there agree.
 *
 * Highlights are decorations: nothing is written to the document until the
 * author replaces, and a replacement is an ordinary edit (undoable, saved,
 * versioned; tracked when Track changes is on).
 */
export const findKey = new PluginKey('findReplace');

const EMPTY = { term: '', matchCase: false, wholeWord: false, current: 0, matches: [] };

function escape(term) {
    return term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function findPattern(term, { matchCase = false, wholeWord = false } = {}) {
    if (!term) return null;

    const body = wholeWord ? `(?<![\\p{L}\\p{N}_])${escape(term)}(?![\\p{L}\\p{N}_])` : escape(term);

    return new RegExp(body, `gu${matchCase ? '' : 'i'}`);
}

/** Every match in the document, as {from, to}. */
export function findMatches(doc, term, options = {}) {
    const pattern = findPattern(term, options);

    if (!pattern) return [];

    const matches = [];
    const scan = (text, start) => {
        pattern.lastIndex = 0;
        let found;

        while ((found = pattern.exec(text)) !== null) {
            if (found[0].length === 0) {
                pattern.lastIndex += 1;
                continue;
            }

            matches.push({ from: start + found.index, to: start + found.index + found[0].length });
        }
    };

    doc.descendants((node, pos) => {
        if (!node.isTextblock) return true;

        let run = '';
        let runStart = null;

        node.forEach((child, offset) => {
            if (child.isText) {
                if (runStart === null) runStart = pos + 1 + offset;
                run += child.text;
            } else {
                if (run) scan(run, runStart);
                run = '';
                runStart = null;
            }
        });

        if (run) scan(run, runStart);

        return false;
    });

    return matches;
}

function decorations(doc, state) {
    if (!state.matches.length) return DecorationSet.empty;

    return DecorationSet.create(
        doc,
        state.matches.map((m, i) => Decoration.inline(m.from, m.to, {
            class: i === state.current ? 'find-match find-match-current' : 'find-match',
            ...(i === state.current ? { 'data-find-current': 'true' } : {}),
        })),
    );
}

export const FindReplace = Extension.create({
    name: 'findReplace',

    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: findKey,
                state: {
                    init: () => EMPTY,
                    apply(tr, value) {
                        const meta = tr.getMeta(findKey);
                        let next = value;

                        if (meta) {
                            next = { ...value, ...meta };
                        }

                        if (meta || (tr.docChanged && next.term)) {
                            const matches = findMatches(tr.doc, next.term, next);
                            const current = matches.length ? Math.min(Math.max(next.current, 0), matches.length - 1) : 0;

                            return { ...next, matches, current };
                        }

                        return next;
                    },
                },
                props: {
                    decorations(state) {
                        return decorations(state.doc, findKey.getState(state));
                    },
                },
            }),
        ];
    },
});

/** The plugin's state: term, options, matches and the current index. */
export const findState = (editor) => (editor && !editor.isDestroyed ? findKey.getState(editor.state) ?? EMPTY : EMPTY);

function scrollToCurrent(editor) {
    requestAnimationFrame(() => {
        if (editor.isDestroyed) return;

        editor.view.dom.querySelector('[data-find-current]')?.scrollIntoView({ block: 'center' });
    });
}

/** Set the search; the first match at or after the caret becomes current. */
export function setSearch(editor, { term, matchCase = false, wholeWord = false }) {
    if (!editor || editor.isDestroyed) return;

    const matches = findMatches(editor.state.doc, term, { matchCase, wholeWord });
    const caret = editor.state.selection.from;
    const at = matches.findIndex((m) => m.from >= caret);

    editor.view.dispatch(editor.state.tr.setMeta(findKey, { term, matchCase, wholeWord, current: at < 0 ? 0 : at }));
    scrollToCurrent(editor);
}

export function step(editor, delta) {
    const state = findState(editor);

    if (!state.matches.length) return;

    const current = (state.current + delta + state.matches.length) % state.matches.length;
    editor.view.dispatch(editor.state.tr.setMeta(findKey, { current }));
    scrollToCurrent(editor);
}

export function replaceCurrent(editor, replacement) {
    const state = findState(editor);
    const match = state.matches[state.current];

    if (!match) return false;

    const tr = editor.state.tr;

    if (replacement) {
        tr.insertText(replacement, match.from, match.to);
    } else {
        tr.delete(match.from, match.to);
    }

    editor.view.dispatch(tr);
    scrollToCurrent(editor);

    return true;
}

/** Replace every match in the chapter, as one undoable step. */
export function replaceAll(editor, replacement) {
    const state = findState(editor);

    if (!state.matches.length) return 0;

    const tr = editor.state.tr;

    [...state.matches].reverse().forEach((m) => {
        if (replacement) {
            tr.insertText(replacement, m.from, m.to);
        } else {
            tr.delete(m.from, m.to);
        }
    });

    editor.view.dispatch(tr);

    return state.matches.length;
}

export function clearSearch(editor) {
    if (!editor || editor.isDestroyed) return;

    editor.view.dispatch(editor.state.tr.setMeta(findKey, { term: '', current: 0 }));
}
