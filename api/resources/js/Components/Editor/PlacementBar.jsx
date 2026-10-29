import { IMAGE_WIDTHS, MIN_DPI, TABLE_STYLES, clampSize, printDpi, selectedImage, selectedTable } from './Placement';

/**
 * The toolbar's controls for the picture or the table under the cursor
 * (ADR-00XX — Image and table placement). Every control is a button, a
 * select or a number field in the toolbar's tab order, so placement needs
 * no pointer: the handle on the picture is a shortcut, not the only way.
 */
function Choice({ label, title, active, onClick, children, disabled = false, reason = null }) {
    return (
        <button
            type="button"
            title={reason ?? title}
            aria-label={title}
            aria-pressed={active}
            aria-disabled={disabled || undefined}
            data-a={label}
            onMouseDown={(event) => event.preventDefault()}
            onClick={disabled ? undefined : onClick}
            className={`grid h-[28px] min-w-[28px] shrink-0 place-items-center rounded-button px-1.5 text-[11.5px] font-medium transition max-md:h-[40px] max-md:min-w-[40px] pointer-coarse:h-[38px] ${
                disabled
                    ? 'cursor-not-allowed text-text-muted opacity-60'
                    : active
                      ? 'bg-brand-primary-subtle text-brand-primary'
                      : 'text-text-body hover:bg-bg-subtle'
            }`}
        >
            {children}
        </button>
    );
}

const Divider = () => <span className="mx-1 h-[18px] w-px shrink-0 bg-border-default" />;

const selectClass =
    'mr-1 h-[28px] shrink-0 rounded-control border max-md:h-[40px] border-border-input bg-bg-surface py-0 pl-2 pr-7 text-[12px] text-text-body focus:border-brand-primary focus:ring-0';

const ALIGN_GLYPHS = { left: '⇤', center: '↔', right: '⇥' };

/** The resolution the picture under the cursor prints at, from the image on the canvas. */
function useDpi(editor, image) {
    const block = editor.storage.image?.block;

    if (!block || !image) return null;

    const element = editor.view.nodeDOM(image.pos)?.querySelector?.('img');

    if (!element?.naturalWidth) return null;

    return printDpi(image.node.attrs, element.naturalWidth, element.naturalHeight, block);
}

export function ImageBar({ editor }) {
    const image = selectedImage(editor.state);
    const dpi = useDpi(editor, image);

    if (!image) return null;

    const attrs = image.node.attrs;
    const set = (changes) => editor.chain().focus().setImagePlacement(changes).run();
    const wrap = attrs.wrap ?? 'none';

    return (
        <>
            <Divider />
            <span className="mr-1 shrink-0 text-[11.5px] text-text-secondary">Picture</span>
            {IMAGE_WIDTHS.map((width) => (
                <Choice
                    key={width}
                    label={`image:width-${width}`}
                    title={`Width ${width}% of the text`}
                    active={!attrs.page && attrs.size === width}
                    onClick={() => set({ size: width, page: null })}
                >
                    {width}%
                </Choice>
            ))}
            <Choice
                label="image:width-original"
                title="Original size (no wider than the text)"
                active={!attrs.page && !attrs.size}
                onClick={() => set({ size: null, page: null })}
            >
                Orig.
            </Choice>
            <label className="mr-1 flex shrink-0 items-center gap-1 text-[11.5px] text-text-secondary">
                <span className="sr-only">Width, percent of the text</span>
                <input
                    type="number"
                    min={10}
                    max={100}
                    step={1}
                    data-a="image:width"
                    value={attrs.size ?? ''}
                    placeholder="—"
                    onChange={(event) => set({ size: clampSize(event.target.value), page: null })}
                    className="h-[28px] w-[56px] rounded-control border border-border-input bg-bg-surface px-1.5 text-[12px] max-md:h-[40px] focus:border-brand-primary focus:ring-0"
                />
                %
            </label>

            <Divider />
            {['left', 'center', 'right'].map((align) => (
                <Choice
                    key={align}
                    label={`image:align-${align}`}
                    title={`Align ${align}`}
                    active={wrap === 'none' && (attrs.align ?? 'center') === align}
                    onClick={() => set({ align: align === 'center' ? null : align, wrap: null })}
                >
                    {ALIGN_GLYPHS[align]}
                </Choice>
            ))}
            <select
                value={wrap}
                data-a="image:wrap"
                aria-label="Text wrap"
                title="Text beside the picture"
                onChange={(event) => set({ wrap: event.target.value === 'none' ? null : event.target.value })}
                className={selectClass}
            >
                <option value="none">No wrap</option>
                <option value="left">Wrap: picture left</option>
                <option value="right">Wrap: picture right</option>
            </select>

            <Divider />
            <Choice
                label="image:full-page"
                title="On a page of its own, scaled to the text block"
                active={attrs.page === 'full'}
                onClick={() => set({ page: attrs.page === 'full' ? null : 'full', wrap: null })}
            >
                Page
            </Choice>
            <Choice
                label="image:full-bleed"
                title="Full bleed (print)"
                active={attrs.page === 'bleed'}
                // Interiors are set without bleed (ADR-0041); the server
                // prints a stored full-bleed picture as a full page.
                disabled
                reason="Full bleed needs an interior with bleed. This book’s interior is set at the trim without bleed, so use “Page” for a picture on a page of its own."
            >
                Bleed
            </Choice>
            <Choice
                label="image:caption-above"
                title="Caption above the picture"
                active={attrs.captionPosition === 'above'}
                onClick={() => set({ captionPosition: attrs.captionPosition === 'above' ? 'below' : 'above' })}
            >
                Cap ↑
            </Choice>
            <Choice
                label="image:keep"
                title="Keep the picture and its caption on one page"
                active={attrs.keep === true}
                onClick={() => set({ keep: attrs.keep === true ? null : true })}
            >
                Keep
            </Choice>
            {dpi !== null && dpi < MIN_DPI && (
                <span
                    role="status"
                    data-a="image:dpi-warning"
                    className="ml-1 shrink-0 rounded-badge bg-status-warning-bg px-2 py-0.5 text-[11px] font-medium text-status-warning"
                    title={`At this width it prints at about ${dpi} dpi; below ${MIN_DPI} it may look soft.`}
                >
                    {dpi} dpi
                </span>
            )}
        </>
    );
}

export function TableBar({ editor }) {
    const table = selectedTable(editor.state);

    if (!table) return null;

    const attrs = table.node.attrs;
    const set = (changes) => editor.chain().focus().setTablePlacement(changes).run();
    const cellAlign = editor.getAttributes('tableCell').align ?? editor.getAttributes('tableHeader').align ?? 'left';

    return (
        <>
            <Divider />
            <select
                value={attrs.tableStyle ?? 'booktabs'}
                data-a="table:style"
                aria-label="Table style"
                onChange={(event) => set({ tableStyle: event.target.value === 'booktabs' ? null : event.target.value })}
                className={selectClass}
            >
                {TABLE_STYLES.map((style) => (
                    <option key={style.key} value={style.key}>{style.name}</option>
                ))}
            </select>
            {['left', 'center', 'right'].map((align) => (
                <Choice
                    key={align}
                    label={`table:align-${align}`}
                    title={`Align this column ${align}`}
                    active={cellAlign === align}
                    onClick={() => editor.chain().focus().alignColumn(align).run()}
                >
                    {ALIGN_GLYPHS[align]}
                </Choice>
            ))}
            <Choice
                label="table:numbers-right"
                title="Right-align every column of figures"
                onClick={() => editor.chain().focus().alignNumbersRight().run()}
            >
                12→
            </Choice>
            <Choice
                label="table:size"
                title={attrs.fontSize === 'normal' ? 'Type at the text size (click for small)' : 'Small type (click for the text size)'}
                active={attrs.fontSize === 'normal'}
                onClick={() => set({ fontSize: attrs.fontSize === 'normal' ? null : 'normal' })}
            >
                Aa
            </Choice>
            <select
                value={attrs.split ?? 'auto'}
                data-a="table:split"
                aria-label="Page breaks"
                title="Whether the table may break across pages"
                onChange={(event) => set({ split: event.target.value === 'auto' ? null : event.target.value })}
                className={selectClass}
            >
                <option value="auto">Breaks: auto</option>
                <option value="avoid">Keep on one page</option>
                <option value="allow">May break</option>
            </select>
            <Choice
                label="table:repeat-header"
                title="Repeat the header row on each page"
                active={attrs.repeatHeader !== false}
                onClick={() => set({ repeatHeader: attrs.repeatHeader === false ? null : false })}
            >
                Hdr ↻
            </Choice>
            <Choice
                label="table:caption-below"
                title="Caption below the table"
                active={attrs.captionPosition === 'below'}
                onClick={() => set({ captionPosition: attrs.captionPosition === 'below' ? 'above' : 'below' })}
            >
                Cap ↓
            </Choice>
        </>
    );
}
