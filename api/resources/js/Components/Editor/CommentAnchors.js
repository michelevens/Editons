import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Open comment threads highlighted in the text they are about.
 *
 * A comment is anchored by a copied excerpt (CommentController), not by
 * positions, so the highlight is found the same way the server decides a
 * thread is detached: by looking for the excerpt in the chapter. The first
 * paragraph of the excerpt is enough to place it; a thread whose text has
 * been edited away simply has no highlight.
 *
 * Decorations, never marks: a highlight is not part of the manuscript and
 * must not be saved into it.
 */
export const CommentKey = new PluginKey('commentAnchors');

/** Shorter than this, an excerpt would light up every "the". */
const MIN_EXCERPT = 3;

function build(doc, threads) {
    if (!threads.length) return DecorationSet.empty;

    const wanted = threads
        .map((thread) => ({ id: thread.id, needle: (thread.excerpt ?? '').split(/\n+/)[0].trim() }))
        .filter((thread) => thread.needle.length >= MIN_EXCERPT);
    const placed = new Set();
    const decorations = [];

    if (!wanted.length) return DecorationSet.empty;

    doc.descendants((node, pos) => {
        if (placed.size === wanted.length) return false;
        if (!node.isTextblock) return true;

        // The block's text, and where each text node starts in it and in
        // the document. Inline atoms (footnotes, equations) are one
        // character that matches nothing.
        let text = '';
        const runs = [];

        node.forEach((child, offset) => {
            if (child.isText) {
                runs.push({ at: text.length, pos: pos + 1 + offset });
                text += child.text;
            } else {
                text += '￼';
            }
        });

        const toPos = (index) => {
            let run = runs[0];

            for (const candidate of runs) {
                if (candidate.at <= index) run = candidate;
                else break;
            }

            return run ? run.pos + (index - run.at) : pos + 1;
        };

        wanted.forEach((thread) => {
            if (placed.has(thread.id)) return;

            const at = text.indexOf(thread.needle);

            if (at === -1) return;

            placed.add(thread.id);
            decorations.push(Decoration.inline(toPos(at), toPos(at + thread.needle.length), {
                class: 'comment-anchor',
                'data-thread': String(thread.id),
            }));
        });

        return false;
    });

    return DecorationSet.create(doc, decorations);
}

export const CommentAnchors = Extension.create({
    name: 'commentAnchors',

    addStorage() {
        return { threads: [] };
    },

    addProseMirrorPlugins() {
        const storage = this.storage;

        return [
            new Plugin({
                key: CommentKey,
                state: {
                    init: (_, state) => build(state.doc, storage.threads),
                    apply: (tr, set, _old, state) =>
                        tr.docChanged || tr.getMeta(CommentKey) ? build(state.doc, storage.threads) : set,
                },
                props: {
                    decorations: (state) => CommentKey.getState(state),
                },
            }),
        ];
    },
});

/** Hand the editor a new list of threads and redraw the highlights. */
export function setCommentThreads(editor, threads) {
    if (!editor || editor.isDestroyed) return;

    editor.storage.commentAnchors.threads = threads;
    editor.view.dispatch(
        editor.state.tr.setMeta(CommentKey, true).setMeta('addToHistory', false).setMeta('preventUpdate', true),
    );
}
