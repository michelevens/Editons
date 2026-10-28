import { useCallback, useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import PrintSpread from './PrintSpread';

/**
 * Design & Layout.
 *
 * The preview is the author's own chapter as the printed book sets it:
 * PrintBook's HTML and stylesheet, in the faces the PDF embeds, with the
 * running heads and folios the PDF draws (PrintSpread). A sample paragraph,
 * or a mock-up with its own margins and fonts, would answer a different
 * question from the only one this screen exists for: how will my book set?
 *
 * The interior design controls (openers, running heads and folios, contents
 * depth, boxes) are InteriorDesign's; the server sends the choices it
 * accepts, and the defaults print the book exactly as it was.
 */
const LABELS = {
    auto: 'The book’s convention',
    words: 'Spelled out (Chapter Four)',
    numerals: 'Numerals (Chapter 4)',
    roman: 'Roman (Chapter IV)',
    none: 'No label',
    custom: 'My own word (Lesson 4)',
};

const HEAD_SOURCES = {
    book: 'Book title',
    author: 'Author',
    part: 'Part title',
    chapter: 'Chapter title',
    section: 'Section heading',
    none: 'Nothing',
};

const HEAD_STYLES = {
    classic: 'Classic (capitals left, italic right)',
    italic: 'Italic',
    smallcaps: 'Small capitals',
    plain: 'Roman',
};

const FOLIOS = {
    'bottom-center': 'Bottom centre',
    'bottom-outside': 'Bottom, outside edge',
    'top-outside': 'Top, outside edge',
    'with-head': 'Beside the running head',
};

const DEPTHS = { 1: 'Parts and chapters', 2: '+ sections (H2)', 3: '+ subsections (H3)' };

const BORDERS = {
    box: 'Ruled box',
    'heavy-left': 'Box, heavy left rule',
    left: 'Rule at left',
    rules: 'Rules above and below',
    dashed: 'Dashed box',
    none: 'No rule',
};

export default function LayoutPanel({ bookId, onNotify, onPreview }) {
    const [data, setData] = useState(null);
    const [sectionId, setSectionId] = useState(null);
    const [busy, setBusy] = useState(false);

    // Sliders fire continuously. Without this, dragging the font size sends
    // a request per pixel and the preview arrives out of order.
    const pending = useRef(null);
    // Every change since the last send. A single timer used to send only
    // the last edit, so a second field typed within the debounce dropped
    // the first, and the server's reply then put the old value back.
    const queued = useRef({});
    // Replies can overtake each other; only the newest is shown.
    const sent = useRef(0);

    const load = useCallback(async (id) => {
        const query = id ? `?print=1&section=${id}` : '?print=1';
        const response = await window.axios.get(`/books/${bookId}/design${query}`);
        setData(response.data);
        setSectionId(response.data.print?.section_id ?? response.data.preview?.id ?? null);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const save = (changes, { immediate = false } = {}) => {
        // Optimistic: the controls track the input at once; the page follows
        // when its new render is ready.
        setData((current) => ({ ...current, layout: { ...current.layout, ...changes } }));

        queued.current = { ...queued.current, ...changes };
        clearTimeout(pending.current);

        const send = async () => {
            const batch = queued.current;
            queued.current = {};
            const ticket = ++sent.current;
            setBusy(true);

            try {
                const response = await window.axios.patch(
                    `/books/${bookId}/layout${sectionId ? `?section=${sectionId}` : ''}`,
                    batch,
                );

                if (ticket === sent.current) {
                    // Keep edits made while this one was on its way.
                    setData({ ...response.data, layout: { ...response.data.layout, ...queued.current } });
                }
            } catch (error) {
                onNotify?.(error.response?.data?.message ?? 'That layout change was not saved.');
                load(sectionId);
            } finally {
                if (ticket === sent.current) setBusy(false);
            }
        };

        immediate ? send() : (pending.current = setTimeout(send, 400));
    };

    const group = (key, changes, options) => save({ [key]: { ...data.layout[key], ...changes } }, options);

    const reset = async () => {
        setBusy(true);

        try {
            const response = await window.axios.post(`/books/${bookId}/layout/reset${sectionId ? `?section=${sectionId}` : ''}`);
            setData(response.data);
        } finally {
            setBusy(false);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { layout, trims, sections, design } = data;
    const opener = layout.opener;
    const heads = layout.heads;
    const callouts = layout.callouts;
    const preset = design.callout_styles[callouts.preset] ?? design.callout_styles.classic;
    const boxStyle = (type) => ({ ...preset[type], label: null, ...(callouts.types?.[type] ?? {}) });
    const setBox = (type, changes) =>
        group('callouts', { types: { ...callouts.types, [type]: { ...(callouts.types?.[type] ?? {}), ...changes } } }, { immediate: !('label' in changes) });

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">
                        Design &amp; Layout
                    </h2>
                    <p className="text-[11.5px] text-text-secondary">
                        Interior layout for print and eBook
                    </p>
                </div>

                {onPreview && (
                    <button
                        type="button"
                        data-a="layout:preview"
                        onClick={onPreview}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover"
                    >
                        <Icon name="eye" size={13} strokeWidth={2} />
                        Preview book
                    </button>
                )}

                <button
                    data-a="layout:reset"
                    onClick={reset}
                    disabled={busy}
                    className={`${onPreview ? '' : 'ml-auto '}rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50`}
                >
                    Reset to defaults
                </button>
            </header>

            <div className="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-auto p-4 lg:grid-cols-[340px_1fr]">
                <div className="space-y-4">
                    <Card title="Page">
                        <Field label="Trim size">
                            <Select value={layout.trim} name="trim" onChange={(value) => save({ trim: value }, { immediate: true })}
                                options={Object.fromEntries(trims.map((trim) => [trim, trim]))} />
                        </Field>

                        <Field label="Margins">
                            <Select value={layout.margins} name="margins" onChange={(value) => save({ margins: value }, { immediate: true })}
                                options={{ Narrow: 'Narrow', Standard: 'Standard', Wide: 'Wide' }} />
                        </Field>
                    </Card>

                    <Card title="Type">
                        <Field label="Body font">
                            <Select value={layout.font} name="font" onChange={(value) => save({ font: value }, { immediate: true })}
                                options={{ serif: 'Serif (EB Garamond)', sans: 'Sans (Source Sans 3)' }} />
                        </Field>

                        <Field label={`Font size — ${Number(layout.size).toFixed(1)} pt`}>
                            <input
                                type="range"
                                min="9"
                                max="14"
                                step="0.5"
                                value={layout.size}
                                data-a="layout:size"
                                onChange={(event) => save({ size: Number(event.target.value) })}
                                className="w-full accent-brand-primary"
                            />
                        </Field>

                        <Field label={`Line spacing — ${Number(layout.leading).toFixed(2)}`}>
                            <input
                                type="range"
                                min="1.2"
                                max="1.8"
                                step="0.05"
                                value={layout.leading}
                                data-a="layout:leading"
                                onChange={(event) => save({ leading: Number(event.target.value) })}
                                className="w-full accent-brand-primary"
                            />
                        </Field>
                    </Card>

                    <Card title="Chapter and part openers">
                        <Field label="Opener style">
                            <Select value={opener.style} name="opener-style" onChange={(value) => group('opener', { style: value }, { immediate: true })}
                                options={Object.fromEntries(design.opener_styles.map((style) => [style.key, style.name]))} />
                        </Field>

                        <Field label="Chapter label">
                            <Select value={opener.label} name="opener-label" onChange={(value) => group('opener', { label: value }, { immediate: true })}
                                options={Object.fromEntries(design.labels.map((key) => [key, LABELS[key] ?? key]))} />
                        </Field>

                        {opener.label === 'custom' && (
                            <Field label="Word before the number">
                                <input
                                    type="text"
                                    maxLength={30}
                                    value={opener.custom}
                                    placeholder="Lesson, Leçon, Chapit…"
                                    data-a="layout:opener-custom"
                                    onChange={(event) => group('opener', { custom: event.target.value })}
                                    className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                />
                            </Field>
                        )}

                        <Toggle
                            label="Chapters start on a right-hand page"
                            hint="A blank left-hand page is added where needed in print. eBooks are not affected."
                            checked={opener.chapters_recto}
                            name="opener-chapters-recto"
                            onChange={(value) => group('opener', { chapters_recto: value }, { immediate: true })}
                        />
                        <Toggle
                            label="Parts start on a right-hand page"
                            hint="With a blank page behind each part title."
                            checked={opener.parts_recto}
                            name="opener-parts-recto"
                            onChange={(value) => group('opener', { parts_recto: value }, { immediate: true })}
                        />

                        <p className="mb-1 mt-3 text-[11.5px] font-medium text-text-secondary">First paragraph</p>
                        <Toggle
                            label="Small capitals for the opening words"
                            hint="The first words of each chapter in spaced capitals."
                            checked={opener.lead}
                            name="opener-lead"
                            onChange={(value) => group('opener', { lead: value }, { immediate: true })}
                        />
                        <Toggle
                            label="Drop cap"
                            hint="A large first letter at the start of each chapter."
                            checked={layout.dropcap}
                            name="dropcap"
                            onChange={(value) => save({ dropcap: value }, { immediate: true })}
                        />
                        <Toggle
                            label="Indent the first paragraph"
                            hint="Off, the first paragraph starts flush left, as books usually set it."
                            checked={opener.indent_first}
                            name="opener-indent-first"
                            onChange={(value) => group('opener', { indent_first: value }, { immediate: true })}
                        />
                    </Card>

                    <Card title="Running heads and page numbers">
                        <Toggle
                            label="Running heads"
                            hint="A line at the top of each text page, chosen for each side below."
                            checked={layout.headers}
                            name="headers"
                            onChange={(value) => save({ headers: value }, { immediate: true })}
                        />

                        <div className="mt-2 grid grid-cols-2 gap-2">
                            <Field label="Left-hand page">
                                <Select value={heads.verso} name="heads-verso" disabled={!layout.headers} onChange={(value) => group('heads', { verso: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_sources.map((key) => [key, HEAD_SOURCES[key] ?? key]))} />
                            </Field>
                            <Field label="Right-hand page">
                                <Select value={heads.recto} name="heads-recto" disabled={!layout.headers} onChange={(value) => group('heads', { recto: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_sources.map((key) => [key, HEAD_SOURCES[key] ?? key]))} />
                            </Field>
                            <Field label="Style">
                                <Select value={heads.style} name="heads-style" disabled={!layout.headers} onChange={(value) => group('heads', { style: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_styles.map((key) => [key, HEAD_STYLES[key] ?? key]))} />
                            </Field>
                            <Field label="Size">
                                <Select value={heads.size} name="heads-size" onChange={(value) => group('heads', { size: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_sizes.map((key) => [key, key[0].toUpperCase() + key.slice(1)]))} />
                            </Field>
                            <Field label="Position">
                                <Select value={heads.align} name="heads-align" disabled={!layout.headers} onChange={(value) => group('heads', { align: value }, { immediate: true })}
                                    options={{ center: 'Centred', outside: 'Outside edge' }} />
                            </Field>
                            <Field label="Page numbers">
                                <Select value={layout.folio} name="folio" onChange={(value) => save({ folio: value }, { immediate: true })}
                                    options={Object.fromEntries(design.folios.map((key) => [key, FOLIOS[key] ?? key]))} />
                            </Field>
                        </div>

                        <Toggle
                            label="Show running heads on chapter openers"
                            hint="Usually off: an opening page carries only its page number."
                            checked={heads.openers}
                            name="heads-openers"
                            onChange={(value) => group('heads', { openers: value }, { immediate: true })}
                        />
                        <p className="mt-2 text-[11px] leading-snug text-text-secondary">
                            Front matter is numbered i, ii, iii; the text starts again at 1.
                        </p>
                    </Card>

                    <Card title="Contents">
                        <Field label="Printed contents lists">
                            <Select value={String(layout.toc.depth)} name="toc-depth" onChange={(value) => group('toc', { depth: Number(value) }, { immediate: true })}
                                options={DEPTHS} />
                        </Field>
                        <Field label="eBook contents list">
                            <Select value={String(layout.toc.epub)} name="toc-epub" onChange={(value) => group('toc', { epub: Number(value) }, { immediate: true })}
                                options={DEPTHS} />
                        </Field>
                    </Card>

                    <Card title="Boxes">
                        <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                            How each kind of box prints, in black and white, in the PDF, the eBook and the editor. Choose a box&rsquo;s kind in the
                            editor from the quotation menu; boxes you have not chosen are recognised from their bold label.
                        </p>
                        <Field label="Style">
                            <Select value={callouts.preset} name="callouts-preset" onChange={(value) => save({ callouts: { preset: value, types: {} } }, { immediate: true })}
                                options={Object.fromEntries(design.callout_presets.map((key) => [key, key[0].toUpperCase() + key.slice(1)]))} />
                        </Field>

                        <div className="mt-1 space-y-2" data-a="layout:callout-table">
                            {design.callout_types.map(({ key, name }) => {
                                const style = boxStyle(key);

                                return (
                                    <div key={key} className="rounded-control border border-border-default p-2" data-a={`layout:callout:${key}`}>
                                        <div className="mb-1.5 flex items-center gap-2">
                                            <span
                                                aria-hidden="true"
                                                className="inline-block h-4 w-6 shrink-0"
                                                style={swatch(style)}
                                            />
                                            <span className="text-[12px] font-medium text-text-primary">{name}</span>
                                            <label className="ml-auto flex items-center gap-1 text-[11px] text-text-secondary">
                                                <input
                                                    type="checkbox"
                                                    checked={style.icon}
                                                    data-a={`layout:callout:${key}:icon`}
                                                    onChange={(event) => setBox(key, { icon: event.target.checked })}
                                                />
                                                Icon
                                            </label>
                                        </div>
                                        <div className="grid grid-cols-[1fr_72px] gap-1.5">
                                            <select
                                                value={style.border}
                                                aria-label={`${name} border`}
                                                data-a={`layout:callout:${key}:border`}
                                                onChange={(event) => setBox(key, { border: event.target.value })}
                                                className="min-w-0 rounded-control border border-border-input px-1.5 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                                            >
                                                {design.borders.map((border) => (
                                                    <option key={border} value={border}>{BORDERS[border] ?? border}</option>
                                                ))}
                                            </select>
                                            <select
                                                value={style.tint}
                                                aria-label={`${name} tint`}
                                                data-a={`layout:callout:${key}:tint`}
                                                onChange={(event) => setBox(key, { tint: Number(event.target.value) })}
                                                className="rounded-control border border-border-input px-1.5 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                                            >
                                                {design.tints.map((tint) => (
                                                    <option key={tint} value={tint}>{tint === 0 ? 'No tint' : `${tint}%`}</option>
                                                ))}
                                            </select>
                                            <input
                                                type="text"
                                                maxLength={40}
                                                value={style.label ?? ''}
                                                placeholder="Label for boxes without one"
                                                aria-label={`${name} label`}
                                                data-a={`layout:callout:${key}:label`}
                                                onChange={(event) => setBox(key, { label: event.target.value === '' ? null : event.target.value })}
                                                className="col-span-2 rounded-control border border-border-input px-1.5 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </Card>
                </div>

                <section className="min-w-0 rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <h3 className="text-[13px] font-semibold text-text-primary">Preview</h3>
                        <span className="text-[11px] text-text-secondary" aria-live="polite" data-a="layout:preview-state">
                            {busy ? 'Updating…' : 'As the PDF sets it'}
                        </span>

                        {sections.length > 1 && (
                            <select
                                value={sectionId ?? ''}
                                data-a="layout:preview-section"
                                aria-label="Chapter to preview"
                                onChange={(event) => load(Number(event.target.value))}
                                className="ml-auto min-w-0 max-w-full rounded-control border border-border-input px-2 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                            >
                                {sections.map((section) => (
                                    <option key={section.id} value={section.id}>
                                        {section.title}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    {data.print ? (
                        <>
                            <PrintSpread preview={data.print} />
                            <p className="mt-2.5 text-[11.5px] text-text-secondary">
                                {layout.trim} · {layout.font === 'serif' ? 'EB Garamond' : 'Source Sans 3'}{' '}
                                {Number(layout.size).toFixed(1)} pt · {layout.margins.toLowerCase()} margins. Line breaks can differ slightly from the
                                PDF, which stays the proof.
                            </p>
                        </>
                    ) : (
                        <p className="py-8 text-center text-[12px] text-text-secondary">
                            Write a chapter to see how the book will set.
                        </p>
                    )}
                </section>
            </div>
        </div>
    );
}

/** A small picture of a box style, in the colours it prints in. */
function swatch(style) {
    const ink = '#1a1a1a';
    const grey = Math.round(255 * (1 - style.tint / 100));
    const base = { background: style.tint ? `rgb(${grey},${grey},${grey})` : 'transparent' };

    return {
        ...base,
        ...{
            box: { border: `1px solid ${ink}` },
            'heavy-left': { border: `1px solid ${ink}`, borderLeft: `3px solid ${ink}` },
            left: { borderLeft: '2px solid #555' },
            rules: { borderTop: `1px solid ${ink}`, borderBottom: `1px solid ${ink}` },
            dashed: { border: `1px dashed ${ink}` },
            none: {},
        }[style.border],
    };
}

function Card({ title, children }) {
    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">{title}</h3>
            {children}
        </section>
    );
}

function Select({ value, name, options, onChange, disabled = false }) {
    return (
        <select
            value={value}
            data-a={`layout:${name}`}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
            className="w-full min-w-0 rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0 disabled:opacity-50"
        >
            {Object.entries(options).map(([key, label]) => (
                <option key={key} value={key}>{label}</option>
            ))}
        </select>
    );
}

function Field({ label, children }) {
    return (
        <label className="mb-2.5 block">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                {label}
            </span>
            {children}
        </label>
    );
}

function Toggle({ label, hint, checked, name, onChange }) {
    return (
        <div className="flex items-start justify-between gap-3 border-b border-border-default py-2 last:border-0">
            <div className="min-w-0">
                <p className="text-[12.5px] font-medium text-text-primary">{label}</p>
                <p className="text-[11px] leading-snug text-text-secondary">{hint}</p>
            </div>

            <button
                role="switch"
                aria-checked={checked}
                aria-label={label}
                data-a={`layout:${name}`}
                onClick={() => onChange(!checked)}
                className={`mt-0.5 h-[20px] w-[34px] shrink-0 rounded-full p-[2px] transition ${
                    checked ? 'bg-brand-fill' : 'bg-border-input'
                }`}
            >
                <span
                    className={`block h-4 w-4 rounded-full bg-white transition-transform ${
                        checked ? 'translate-x-[14px]' : ''
                    }`}
                />
            </button>
        </div>
    );
}
