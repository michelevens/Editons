import { Node } from '@tiptap/core';
import { useState } from 'react';
import Dialog from '../Dialog';

/**
 * A footnote: a reference in the text holding its note.
 *
 * The note lives on the reference rather than in a list at the end, so
 * moving a sentence moves its note and the numbers never drift. The editor
 * numbers references with a CSS counter; every export numbers them again
 * in reading order.
 */
export const Footnote = Node.create({
    name: 'footnote',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addOptions() {
        return { onEdit: null };
    },

    addAttributes() {
        return { text: { default: '', rendered: false } };
    },

    parseHTML() {
        return [{ tag: 'sup[data-type="footnote"]', getAttrs: (el) => ({ text: el.getAttribute('data-text') ?? '' }) }];
    },

    renderHTML({ node }) {
        return ['sup', { 'data-type': 'footnote', 'data-text': node.attrs.text }];
    },

    addNodeView() {
        return ({ node, getPos }) => {
            const dom = document.createElement('sup');
            dom.className = 'footnote-ref';
            dom.dataset.type = 'footnote';
            dom.title = node.attrs.text || 'Empty note';
            dom.setAttribute('role', 'button');
            dom.setAttribute('aria-label', `Footnote: ${node.attrs.text}`);
            dom.addEventListener('click', (event) => {
                event.preventDefault();
                this.options.onEdit?.(node.attrs, getPos());
            });

            return { dom };
        };
    },
});

export function FootnoteDialog({ initial, onSave, onDelete, onClose }) {
    const [text, setText] = useState(initial.text ?? '');
    const editing = initial.pos !== null && initial.pos !== undefined;

    return (
        <Dialog
            onClose={onClose}
            aria-label={editing ? 'Edit footnote' : 'Insert footnote'}
            className="w-full max-w-[520px] rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
            onKeyDown={(event) => {
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && text.trim()) onSave(text.trim());
            }}
        >
            <h3 className="text-[15px] font-semibold text-text-primary">
                {editing ? 'Edit footnote' : 'Insert footnote'}
            </h3>
            <textarea
                autoFocus
                value={text}
                data-a="footnote:text"
                onChange={(event) => setText(event.target.value)}
                rows={4}
                placeholder="e.g. Hobsbawm, *The Age of Empire*, 41."
                className="mt-3 w-full rounded-control border-border-input text-[13.5px] focus:border-brand-primary focus:ring-0"
            />
            <p className="mt-1 text-[11.5px] text-text-secondary">
                Put a title in *asterisks* for italics. Notes are numbered for you, in
                order, in every export; Word gets real footnotes.
            </p>
            <div className="mt-4 flex items-center gap-2">
                {editing && (
                    <button type="button" data-a="footnote:delete" onClick={onDelete}
                        className="h-[34px] rounded-control px-3 text-[13px] text-status-danger hover:bg-status-danger-bg">
                        Delete
                    </button>
                )}
                <button type="button" onClick={onClose}
                    className="ml-auto h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle">
                    Cancel
                </button>
                <button type="button" data-a="footnote:save" disabled={!text.trim()} onClick={() => onSave(text.trim())}
                    className="h-[34px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50">
                    {editing ? 'Update' : 'Insert'}
                </button>
            </div>
        </Dialog>
    );
}
