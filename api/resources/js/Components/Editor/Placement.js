import Image from '@tiptap/extension-image';
import { Table, TableView } from '@tiptap/extension-table';
import { NodeSelection, Plugin, PluginKey } from '@tiptap/pm/state';
import { selectedRect } from '@tiptap/pm/tables';
import { captionKind } from './CrossRef';

/**
 * Where a picture or a table sits and how it is set (ADR-00XX — Image and
 * table placement), as attributes on Tiptap's own image and table nodes, so
 * every existing manuscript loads and saves as it did. App\Publishing\
 * Placement reads the same attributes for the PDF, the EPUB and Word; a
 * missing one is the old rendering.
 *
 * The canvas shows the choices as the exports will set them, roughly: a
 * width as a share of the column, alignment, text wrapping beside a
 * picture, and a table's rules, type size and column widths.
 */
export const IMAGE_WIDTHS = [25, 33, 50, 66, 100];

export const TABLE_STYLES = [
    { key: 'booktabs', name: 'Booktabs (rules)' },
    { key: 'grid', name: 'Grid' },
    { key: 'shaded', name: 'Shaded header' },
    { key: 'zebra', name: 'Zebra rows' },
    { key: 'plain', name: 'Plain' },
];

const ALIGNS = ['left', 'center', 'right'];
const WRAPS = ['none', 'left', 'right'];
const PAGES = ['full', 'bleed'];
const CAPTIONS = ['above', 'below'];

/** dompdf's resolution for a picture set at its own size (Placement::SCREEN_DPI). */
const SCREEN_DPI = 96;

export const MIN_DPI = 300;

/** Sent on window when the book's text block is known (setTextBlock). */
const TEXT_BLOCK_EVENT = 'editons:text-block';

/** Tell the canvas the book's text block, inches [width, height]. */
export function setTextBlock(editor, block) {
    if (!editor || editor.isDestroyed || !editor.storage.image) return;

    editor.storage.image.block = block;
    window.dispatchEvent(new Event(TEXT_BLOCK_EVENT));
}

const oneOf = (value, allowed) => (allowed.includes(value) ? value : null);

/** A width as stored: a whole percentage, 10–100, or null (original size). */
export function clampSize(value) {
    const n = Number(value);

    if (value === null || value === '' || !Number.isFinite(n)) return null;

    return Math.max(10, Math.min(100, Math.round(n)));
}

/**
 * The width a picture is set at, percent of the measure, or null for its own
 * size (Placement::width): wrapped text needs a width, half by default.
 */
export function placedWidth(attrs) {
    if (attrs.page) return 100;
    if (attrs.size) return attrs.size;
    if (attrs.wrap === 'left' || attrs.wrap === 'right') return 50;

    return null;
}

/** How wide a picture prints, inches (Placement::printedWidth). */
export function printedWidth(attrs, naturalWidth, naturalHeight, block) {
    const [measure, depth] = block;

    if (attrs.page) return Math.min(measure, (depth * naturalWidth) / Math.max(1, naturalHeight));

    const width = placedWidth(attrs);

    return width ? (measure * width) / 100 : Math.min(measure, naturalWidth / SCREEN_DPI);
}

/** Dots per inch at that width (Placement::dpi). */
export function printDpi(attrs, naturalWidth, naturalHeight, block) {
    const inches = printedWidth(attrs, naturalWidth, naturalHeight, block);

    return inches > 0 ? Math.floor(naturalWidth / inches) : 0;
}

/** Attribute stored on the node, mirrored to a data- attribute for the HTML the editor copies. */
const attribute = (name, read) => ({
    default: null,
    parseHTML: (element) => read(element.getAttribute(`data-${name.toLowerCase()}`)),
    renderHTML: (attributes) => (attributes[name] === null || attributes[name] === undefined ? {} : { [`data-${name.toLowerCase()}`]: String(attributes[name]) }),
});

const bool = (value) => (value === 'true' ? true : value === 'false' ? false : null);

/**
 * The adjacent caption paragraph of the node at `pos`, as the exports pair
 * them: after it when the caption is below, before it when above.
 */
function captionNear(doc, pos, node, side) {
    const $pos = doc.resolve(pos);
    const parent = $pos.parent;
    const index = $pos.index();

    if (side === 'below') {
        const next = index + 1 < parent.childCount ? parent.child(index + 1) : null;

        return next && captionKind(next) ? { node: next, pos: pos + node.nodeSize } : null;
    }

    const before = index > 0 ? parent.child(index - 1) : null;

    return before && captionKind(before) ? { node: before, pos: pos - before.nodeSize } : null;
}

/**
 * Put the caption above or below its picture or table: the attribute, and
 * the caption paragraph itself moved to that side, so the canvas reads as
 * the book will.
 */
function placeCaption(tr, pos, node, position) {
    // A table's caption is above it unless set otherwise, a picture's below.
    const current = node.attrs.captionPosition ?? (node.type.name === 'table' ? 'above' : 'below');
    tr.setNodeMarkup(pos, undefined, { ...node.attrs, captionPosition: position });

    if (position === current) return tr;

    const caption = captionNear(tr.doc, pos, node, current);

    if (!caption) return tr;

    if (current === 'below') {
        // Caption after the node: take it out, put it in front.
        tr.delete(caption.pos, caption.pos + caption.node.nodeSize);
        tr.insert(pos, caption.node);
    } else {
        // Caption before it: the node moves up by the caption's size.
        const after = pos + node.nodeSize;
        tr.insert(after, caption.node);
        tr.delete(caption.pos, caption.pos + caption.node.nodeSize);
    }

    return tr;
}

/** The picture under the selection: a node selection on an image. */
export function selectedImage(state) {
    const { selection } = state;

    return selection instanceof NodeSelection && selection.node.type.name === 'image'
        ? { node: selection.node, pos: selection.from }
        : null;
}

/** The table the cursor is in, and its position. */
export function selectedTable(state) {
    const { $from } = state.selection;

    for (let depth = $from.depth; depth > 0; depth--) {
        const node = $from.node(depth);

        if (node.type.name === 'table') return { node, pos: $from.before(depth) };
    }

    return null;
}

function applyImage(element, node) {
    const { align, wrap, page } = node.attrs;
    const side = !page && (wrap === 'left' || wrap === 'right') ? wrap : null;
    const width = placedWidth(node.attrs);

    element.dataset.align = page ? 'center' : (side ?? align ?? 'center');
    element.dataset.wrap = side ?? 'none';
    element.dataset.page = page ?? '';
    element.style.width = width ? `${width}%` : '';
    element.title = page === 'full' ? 'On a page of its own' : page === 'bleed' ? 'Full bleed' : '';
}

/**
 * The picture on the canvas: the image in a box sized and placed as it will
 * print, with a corner handle that sets its width as a share of the column.
 */
export const PlacedImage = Image.extend({
    addStorage() {
        // The text block in inches [width, height], for the resolution
        // warning; from the book (CrossReferenceController), setTextBlock.
        return { block: null };
    },

    addAttributes() {
        return {
            ...this.parent?.(),
            size: attribute('size', clampSize),
            align: attribute('align', (v) => oneOf(v, ALIGNS)),
            wrap: attribute('wrap', (v) => oneOf(v, WRAPS)),
            page: attribute('page', (v) => oneOf(v, PAGES)),
            captionPosition: attribute('captionPosition', (v) => oneOf(v, CAPTIONS)),
            keep: attribute('keep', bool),
        };
    },

    addCommands() {
        return {
            ...this.parent?.(),
            setImagePlacement:
                (changes) =>
                ({ state, tr, dispatch }) => {
                    const image = selectedImage(state);

                    if (!image) return false;

                    if (dispatch) {
                        const { captionPosition, ...rest } = changes;
                        const attrs = { ...image.node.attrs, ...rest };

                        if ('size' in rest) attrs.size = clampSize(rest.size);

                        tr.setNodeMarkup(image.pos, undefined, attrs);

                        if (captionPosition) {
                            placeCaption(tr, image.pos, tr.doc.nodeAt(image.pos), captionPosition);
                        }

                        // Keep the picture selected, wherever the caption went.
                        const at = tr.mapping.map(image.pos, 1);
                        if (tr.doc.nodeAt(at)?.type.name === 'image') tr.setSelection(NodeSelection.create(tr.doc, at));
                    }

                    return true;
                },
        };
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            let current = node;
            const dom = document.createElement('div');
            dom.className = 'placed-image';
            dom.dataset.a = 'image:placed';

            const img = document.createElement('img');
            img.src = node.attrs.src ?? '';
            img.alt = node.attrs.alt ?? '';
            img.draggable = false;
            dom.appendChild(img);

            const warning = document.createElement('span');
            warning.className = 'placed-dpi';
            warning.dataset.a = 'image:dpi';
            warning.hidden = true;
            dom.appendChild(warning);

            const handle = document.createElement('span');
            handle.className = 'placed-handle';
            handle.dataset.a = 'image:resize';
            handle.setAttribute('aria-hidden', 'true');
            handle.contentEditable = 'false';
            dom.appendChild(handle);

            const checkDpi = () => {
                const block = editor.storage.image?.block;

                if (!block || !img.naturalWidth) {
                    warning.hidden = true;
                    return;
                }

                const dpi = printDpi(current.attrs, img.naturalWidth, img.naturalHeight, block);
                dom.dataset.dpi = String(dpi);
                warning.hidden = dpi >= MIN_DPI;
                warning.textContent = `${dpi} dpi`;
                warning.title = `Prints at about ${dpi} dpi at this width: below ${MIN_DPI} it may look soft. Use a larger original or set it smaller.`;
            };

            img.addEventListener('load', checkDpi);
            // The book's text block arrives after the chapter is drawn.
            window.addEventListener(TEXT_BLOCK_EVENT, checkDpi);
            applyImage(dom, node);

            // Drag the corner: the width follows the pointer as a share of
            // the column, saved once when the pointer is let go.
            handle.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const column = editor.view.dom.getBoundingClientRect();
                const style = getComputedStyle(editor.view.dom);
                const inner = column.width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
                const start = dom.getBoundingClientRect();
                const fromRight = dom.dataset.align === 'right';
                const x0 = event.clientX;
                let size = current.attrs.size;

                handle.setPointerCapture?.(event.pointerId);

                const move = (moved) => {
                    const delta = (moved.clientX - x0) * (fromRight ? -1 : 1);
                    size = clampSize(((start.width + delta) / inner) * 100);
                    dom.style.width = `${size}%`;
                };

                const up = () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', up);
                    handle.removeEventListener('pointercancel', up);

                    const pos = typeof getPos === 'function' ? getPos() : null;

                    if (pos === null || pos === undefined || size === current.attrs.size) return;

                    const tr = editor.view.state.tr.setNodeMarkup(pos, undefined, { ...current.attrs, size, page: null });
                    // Still selected, in the document the change made.
                    editor.view.dispatch(tr.setSelection(NodeSelection.create(tr.doc, pos)));
                };

                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', up);
                handle.addEventListener('pointercancel', up);
            });

            return {
                dom,
                update(updated) {
                    if (updated.type !== current.type) return false;

                    current = updated;

                    if (img.getAttribute('src') !== (updated.attrs.src ?? '')) img.src = updated.attrs.src ?? '';
                    img.alt = updated.attrs.alt ?? '';
                    applyImage(dom, updated);
                    checkDpi();

                    return true;
                },
                selectNode() {
                    dom.classList.add('ProseMirror-selectednode');
                },
                deselectNode() {
                    dom.classList.remove('ProseMirror-selectednode');
                },
                stopEvent: (event) => event.target === handle,
                ignoreMutation: () => true,
                destroy() {
                    window.removeEventListener(TEXT_BLOCK_EVENT, checkDpi);
                },
            };
        };
    },
});

/**
 * The table on the canvas: its style, type size and column widths shown as
 * the exports set them. Column widths are Tiptap's own (dragged at a
 * column's edge), read as shares of the measure.
 */
export class PlacedTableView extends TableView {
    constructor(node, cellMinWidth, view, attributes) {
        super(node, cellMinWidth, view, attributes);
        this.show(node);
    }

    update(node) {
        const ok = super.update(node);

        if (ok) this.show(node);

        return ok;
    }

    show(node) {
        const { tableStyle, fontSize } = node.attrs;
        this.table.dataset.style = tableStyle ?? 'booktabs';
        this.table.dataset.size = fontSize ?? 'small';

        // Every column given a width: proportions, as the exports set them.
        let widths = true;
        node.firstChild?.forEach((cell) => {
            if (!Array.isArray(cell.attrs.colwidth) || cell.attrs.colwidth.some((w) => !w)) widths = false;
        });

        if (widths && node.firstChild) {
            this.table.dataset.widths = 'true';
        } else {
            delete this.table.dataset.widths;
        }
    }
}

/**
 * Column widths are shares of the measure, so they mean something only when
 * every column has one. Dragging one column's edge sets that column alone:
 * the others are given the widths they have on screen, once, so the table
 * the exports set is the one the author sees.
 */
const completeWidths = new Plugin({
    key: new PluginKey('placedTableWidths'),
    view: () => ({
        update(view) {
            const { state } = view;
            const tr = state.tr;

            state.doc.descendants((node, pos) => {
                if (node.type.name !== 'table') return true;

                const row = node.firstChild;
                const widths = [];
                let partial = false;
                let some = false;

                row?.forEach((cell) => {
                    const set = Array.isArray(cell.attrs.colwidth) && cell.attrs.colwidth.every((w) => w > 0);
                    some ||= set;
                    partial ||= !set;
                    widths.push(set);
                });

                if (!some || !partial) return false;

                const table = view.nodeDOM(pos)?.querySelector?.('table');
                const cols = table ? [...table.querySelectorAll(':scope > colgroup > col')] : [];
                const cells = table?.rows?.[0] ? [...table.rows[0].cells] : [];

                if (cells.length === 0) return false;

                let rowPos = pos + 1;
                node.forEach((tableRow) => {
                    let cellPos = rowPos + 1;
                    let column = 0;

                    tableRow.forEach((cell) => {
                        const span = cell.attrs.colspan ?? 1;

                        if (!(Array.isArray(cell.attrs.colwidth) && cell.attrs.colwidth.every((w) => w > 0))) {
                            const colwidth = Array.from({ length: span }, (_, j) => {
                                const col = cols[column + j];
                                const px = col ? parseFloat(col.style.width) || col.getBoundingClientRect().width : 0;

                                return Math.max(25, Math.round(px || cells[Math.min(column + j, cells.length - 1)].getBoundingClientRect().width));
                            });
                            tr.setNodeMarkup(cellPos, undefined, { ...cell.attrs, colwidth });
                        }

                        column += span;
                        cellPos += cell.nodeSize;
                    });

                    rowPos += tableRow.nodeSize;
                });

                return false;
            });

            if (tr.docChanged) view.dispatch(tr);
        },
    }),
});

export const PlacedTable = Table.extend({
    addProseMirrorPlugins() {
        return [...(this.parent?.() ?? []), completeWidths];
    },

    addAttributes() {
        return {
            ...this.parent?.(),
            tableStyle: attribute('tableStyle', (v) => oneOf(v, TABLE_STYLES.map((s) => s.key))),
            fontSize: attribute('fontSize', (v) => oneOf(v, ['small', 'normal'])),
            split: attribute('split', (v) => oneOf(v, ['auto', 'avoid', 'allow'])),
            repeatHeader: attribute('repeatHeader', bool),
            captionPosition: attribute('captionPosition', (v) => oneOf(v, CAPTIONS)),
        };
    },

    addCommands() {
        return {
            ...this.parent?.(),
            setTablePlacement:
                (changes) =>
                ({ state, tr, dispatch }) => {
                    const table = selectedTable(state);

                    if (!table) return false;

                    if (dispatch) {
                        const { captionPosition, ...rest } = changes;
                        tr.setNodeMarkup(table.pos, undefined, { ...table.node.attrs, ...rest });

                        if (captionPosition) placeCaption(tr, table.pos, tr.doc.nodeAt(table.pos), captionPosition);
                    }

                    return true;
                },

            // The column the cursor is in (or every column a cell selection
            // spans), every cell of it, header included.
            alignColumn:
                (align) =>
                ({ state, tr, dispatch }) => {
                    let rect;

                    try {
                        rect = selectedRect(state);
                    } catch {
                        return false;
                    }

                    if (dispatch) {
                        const value = oneOf(align, ALIGNS);
                        const seen = new Set();

                        for (let row = 0; row < rect.map.height; row++) {
                            for (let col = rect.left; col < rect.right; col++) {
                                const at = rect.map.map[row * rect.map.width + col];

                                if (seen.has(at)) continue;
                                seen.add(at);

                                const pos = rect.tableStart + at;
                                const cell = tr.doc.nodeAt(pos);

                                if (cell) tr.setNodeMarkup(pos, undefined, { ...cell.attrs, align: value === 'left' ? null : value });
                            }
                        }
                    }

                    return true;
                },

            // "Numbers right": every column whose body cells are mostly
            // figures is right-aligned, the rest left as they are.
            alignNumbersRight:
                () =>
                ({ state, tr, dispatch }) => {
                    let rect;

                    try {
                        rect = selectedRect(state);
                    } catch {
                        return false;
                    }

                    const { map, tableStart, table } = rect;

                    for (let col = 0; col < map.width; col++) {
                        const cells = [];

                        for (let row = 0; row < map.height; row++) {
                            const at = map.map[row * map.width + col];
                            cells.push({ at, cell: table.nodeAt(at) });
                        }

                        const body = cells.filter(({ cell }) => cell && cell.type.name !== 'tableHeader' && cell.textContent.trim() !== '');
                        const numeric = body.filter(({ cell }) => /^[-+−–(]?[$€£%]?\s?[\d\s.,]+\s?[%$€£)]?$/u.test(cell.textContent.trim()));

                        if (body.length === 0 || numeric.length * 2 <= body.length) continue;

                        if (dispatch) {
                            for (const { at, cell } of cells) {
                                if (cell) tr.setNodeMarkup(tableStart + at, undefined, { ...cell.attrs, align: 'right' });
                            }
                        }
                    }

                    return true;
                },
        };
    },
});
