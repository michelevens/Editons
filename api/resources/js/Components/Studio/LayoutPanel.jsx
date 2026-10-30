import { useCallback, useEffect, useRef, useState } from 'react';
import { t, useI18n } from '../../i18n';
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
    auto: { tKey: 'layout.label_auto' },
    words: { tKey: 'layout.label_words' },
    numerals: { tKey: 'layout.label_numerals' },
    roman: { tKey: 'layout.label_roman' },
    none: { tKey: 'layout.label_none' },
    custom: { tKey: 'layout.label_custom' },
};

const HEAD_SOURCES = {
    book: { tKey: 'layout.head_book' },
    author: { tKey: 'layout.head_author' },
    part: { tKey: 'layout.head_part' },
    chapter: { tKey: 'layout.head_chapter' },
    section: { tKey: 'layout.head_section' },
    none: { tKey: 'layout.head_none' },
};

const HEAD_STYLES = {
    classic: { tKey: 'layout.head_style_classic' },
    italic: { tKey: 'layout.italic' },
    smallcaps: { tKey: 'layout.small_capitals' },
    plain: { tKey: 'layout.head_style_plain' },
};

const HEAD_SIZES = {
    small: { tKey: 'layout.head_size_small' },
    normal: { tKey: 'layout.head_size_normal' },
    large: { tKey: 'layout.head_size_large' },
};

const FOLIOS = {
    'bottom-center': { tKey: 'layout.folio_bottom_center' },
    'bottom-outside': { tKey: 'layout.folio_bottom_outside' },
    'top-outside': { tKey: 'layout.folio_top_outside' },
    'with-head': { tKey: 'layout.folio_with_head' },
};

const DEPTHS = {
    1: { tKey: 'layout.depth_chapters' },
    2: { tKey: 'layout.depth_sections' },
    3: { tKey: 'layout.depth_subsections' },
};

const MM = 25.4;

const BORDERS = {
    box: { tKey: 'layout.border_box' },
    'heavy-left': { tKey: 'layout.border_heavy_left' },
    left: { tKey: 'layout.border_left' },
    rules: { tKey: 'layout.border_rules' },
    dashed: { tKey: 'layout.border_dashed' },
    none: { tKey: 'layout.border_none' },
};

const CALLOUT_PRESETS = {
    classic: { tKey: 'layout.preset_classic' },
    minimal: { tKey: 'layout.preset_minimal' },
    boxed: { tKey: 'layout.preset_boxed' },
    shaded: { tKey: 'layout.preset_shaded' },
};

/** One choice's name in the current language; the value itself when it has none. */
const nameOf = (map, key, fallback = key) => (map[key] ? t(map[key].tKey) : fallback);

/** A whole { value: { tKey } } map as a select's options. */
const named = (map) => Object.fromEntries(Object.keys(map).map((key) => [key, nameOf(map, key)]));

const lines = () => Object.fromEntries([1, 2, 3, 4, 5].map((n) => [n, t('layout.lines', { count: n })]));

const capitalised = (key) => key[0].toUpperCase() + key.slice(1);

export default function LayoutPanel({ bookId, onNotify, onPreview }) {
    useI18n();
    const [data, setData] = useState(null);
    const [sectionId, setSectionId] = useState(null);
    const [busy, setBusy] = useState(false);
    // A custom trim's width and height fields, shown when the size is not
    // a listed one or the author asks for one.
    const [customTrim, setCustomTrim] = useState(false);
    const [level, setLevel] = useState('h2');

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

    const storedTrim = data?.layout?.trim;
    const listed = data?.trims?.some((trim) => trim.value === storedTrim);

    useEffect(() => {
        if (storedTrim && !listed) setCustomTrim(true);
    }, [storedTrim, listed]);

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
                onNotify?.(error.response?.data?.message ?? t('layout.not_saved'));
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
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { layout, trims, sections, design, margins } = data;
    // A book that follows a house style takes its design from it: the
    // controls are locked until it is detached (HouseStyleCard).
    const locked = Boolean(data.house_style?.following);
    const unit = layout.page?.unit ?? 'in';
    const show = (inches) => (unit === 'mm' ? (inches * MM).toFixed(1) : String(Number(Number(inches).toFixed(3))));
    const mirrored = margins.mirrored;
    const currentTrim = trims.find((trim) => trim.value === layout.trim);
    const trimSize = (layout.trim.match(/([\d.]+)\s*x\s*([\d.]+)/) ?? [0, 6, 9]).slice(1, 3).map(Number);
    const text = layout.text;
    const heading = layout.headings[level];
    const setHeading = (changes, immediate = false) => group('headings', { [level]: { ...heading, ...changes } }, { immediate });
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
                        {t('layout.title')}
                    </h2>
                    <p className="text-[11.5px] text-text-secondary">
                        {t('layout.subtitle')}
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
                        {t('layout.preview_book')}
                    </button>
                )}

                <button
                    data-a="layout:reset"
                    onClick={reset}
                    disabled={busy || Boolean(data?.house_style?.following)}
                    className={`${onPreview ? '' : 'ml-auto '}rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50`}
                >
                    {t('layout.reset')}
                </button>
            </header>

            <div className="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-auto p-4 lg:grid-cols-[340px_1fr]">
                <div className="space-y-4">
                    <HouseStyleCard
                        bookId={bookId}
                        info={data.house_style}
                        onChanged={() => load(sectionId)}
                        onNotify={onNotify}
                    />

                    <fieldset disabled={locked} className="m-0 min-w-0 space-y-4 border-0 p-0 disabled:opacity-60" data-a="layout:controls">
                    <Card title={t('layout.card_page')}>
                        <Field label={t('layout.trim')}>
                            <select
                                value={customTrim ? 'custom' : layout.trim}
                                data-a="layout:trim"
                                onChange={(event) => {
                                    if (event.target.value === 'custom') {
                                        setCustomTrim(true);
                                    } else {
                                        setCustomTrim(false);
                                        save({ trim: event.target.value }, { immediate: true });
                                    }
                                }}
                                className="w-full min-w-0 rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            >
                                {trims.map((trim) => (
                                    <option key={trim.value} value={trim.value}>
                                        {trim.value}{trim.name ? ` — ${trim.name}` : ''} ({trim.mm})
                                    </option>
                                ))}
                                <option value="custom">{t('layout.custom_size')}</option>
                            </select>
                        </Field>

                        {customTrim && (
                            <div className="mb-2.5" data-a="layout:custom-trim">
                                <div className="grid grid-cols-2 gap-2">
                                    <Measure label={t('layout.width')} unit={unit} value={trimSize[0]} name="trim-width"
                                        min={design.custom_trim.width[0]} max={design.custom_trim.width[1]}
                                        onCommit={(inches) => save({ trim: `${inches} x ${trimSize[1]} in` }, { immediate: true })} />
                                    <Measure label={t('layout.height')} unit={unit} value={trimSize[1]} name="trim-height"
                                        min={design.custom_trim.height[0]} max={design.custom_trim.height[1]}
                                        onCommit={(inches) => save({ trim: `${trimSize[0]} x ${inches} in` }, { immediate: true })} />
                                </div>
                                <p className="text-[11px] leading-snug text-text-secondary">
                                    {t('layout.custom_range', {
                                        min_width: show(design.custom_trim.width[0]),
                                        min_height: show(design.custom_trim.height[0]),
                                        max_width: show(design.custom_trim.width[1]),
                                        max_height: show(design.custom_trim.height[1]),
                                        unit,
                                    })}
                                </p>
                            </div>
                        )}

                        {!customTrim && currentTrim && (
                            <p className="-mt-1.5 mb-2.5 text-[11px] leading-snug text-text-secondary" data-a="layout:trim-services">
                                {currentTrim.services.length ? t('layout.listed_by', { services: currentTrim.services.join(', ') }) : t('layout.not_listed')}
                            </p>
                        )}

                        <div className="mb-2.5 grid grid-cols-[1fr_auto] items-end gap-2">
                            <Field label={t('layout.margins')}>
                                <Select value={layout.margins} name="margins"
                                    onChange={(value) => save({ margins: value, page: { ...layout.page, top: null, bottom: null, inside: null, outside: null } }, { immediate: true })}
                                    options={Object.fromEntries(design.margin_presets.map((preset) => [preset.key, preset.name]))} />
                            </Field>
                            <div className="mb-2.5 flex overflow-hidden rounded-control border border-border-input" role="group" aria-label={t('layout.units')}>
                                {['in', 'mm'].map((key) => (
                                    <button key={key} type="button" data-a={`layout:unit-${key}`} aria-pressed={unit === key}
                                        onClick={() => group('page', { unit: key }, { immediate: true })}
                                        className={`px-2 py-1 text-[11.5px] font-medium ${unit === key ? 'bg-brand-fill text-white' : 'text-text-body'}`}>
                                        {key}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-2" data-a="layout:margins-grid">
                            {[['top', t('layout.margin_top')], ['bottom', t('layout.margin_bottom')], ['inside', t('layout.margin_inside')], ['outside', t('layout.margin_outside')]].map(([key, label]) => (
                                <Measure key={key} label={label} unit={unit} value={margins.set[key]} name={`margin-${key}`}
                                    min={design.margin_range[0]} max={design.margin_range[1]}
                                    onCommit={(inches) => group('page', mirrored || !['inside', 'outside'].includes(key) ? { [key]: inches } : { inside: inches, outside: inches }, { immediate: true })} />
                            ))}
                        </div>

                        <Toggle
                            label={t('layout.mirrored')}
                            hint={mirrored
                                ? t('layout.mirrored_on')
                                : t('layout.mirrored_off', { inside: show(margins.recommended.inside), outside: show(margins.recommended.outside), unit })}
                            checked={mirrored}
                            name="mirrored"
                            onChange={(value) => group('page', value ? margins.recommended : { inside: null, outside: null }, { immediate: true })}
                        />

                        <p className="mt-1 text-[11px] leading-snug text-text-secondary" data-a="layout:margin-effective">
                            {t('layout.inside_is')} <strong className="font-semibold text-text-body">{show(margins.effective.inside)} {unit}</strong>
                            {margins.widened
                                ? t('layout.margin_widened', { set: show(margins.set.inside), service: margins.service, required: show(margins.required), unit, exact: margins.exact ? 'yes' : 'no', pages: margins.pages })
                                : margins.service
                                  ? t('layout.margin_needs_service', { service: margins.service, required: show(margins.required), unit, exact: margins.exact ? 'yes' : 'no', pages: margins.pages })
                                  : t('layout.margin_needs_any', { required: show(margins.required), unit, exact: margins.exact ? 'yes' : 'no', pages: margins.pages })}
                        </p>
                    </Card>

                    <Card title={t('layout.card_type')}>
                        <Field label={t('layout.body_face')}>
                            <Select value={layout.font} name="font" onChange={(value) => save({ font: value }, { immediate: true })}
                                options={Object.fromEntries(design.fonts.map((face) => [face.key, face.name]))} />
                        </Field>

                        <Field label={t('layout.heading_face')}>
                            <Select value={layout.heading_font ?? ''} name="heading-font" onChange={(value) => save({ heading_font: value === '' ? null : value }, { immediate: true })}
                                options={{ '': t('layout.same_as_text'), ...Object.fromEntries(design.fonts.map((face) => [face.key, face.name])) }} />
                        </Field>

                        <Field label={t('layout.font_size', { size: Number(layout.size).toFixed(1) })}>
                            <input
                                type="range"
                                min={design.size_range[0]}
                                max={design.size_range[1]}
                                step="0.5"
                                value={layout.size}
                                data-a="layout:size"
                                onChange={(event) => save({ size: Number(event.target.value) })}
                                className="w-full accent-brand-primary"
                            />
                        </Field>

                        <Field label={t('layout.line_spacing', { value: Number(layout.leading).toFixed(2) })}>
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

                    <Card title={t('layout.paragraphs')}>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label={t('layout.paragraphs')}>
                                <Select value={text.paragraph} name="text-paragraph" onChange={(value) => group('text', { paragraph: value }, { immediate: true })}
                                    options={{ indent: t('layout.first_line_indent'), block: t('layout.space_between') }} />
                            </Field>
                            <Field label={t('layout.alignment')}>
                                <Select value={text.align} name="text-align" onChange={(value) => group('text', { align: value }, { immediate: true })}
                                    options={{ justify: t('layout.justified'), left: t('layout.ragged_right') }} />
                            </Field>
                        </div>

                        {text.paragraph === 'indent' ? (
                            <Field label={t('layout.indent', { value: Number(text.indent).toFixed(2) })}>
                                <input type="range" min={design.ranges.indent[0]} max={design.ranges.indent[1]} step="0.05" value={text.indent}
                                    data-a="layout:text-indent" onChange={(event) => group('text', { indent: Number(event.target.value) })}
                                    className="w-full accent-brand-primary" />
                            </Field>
                        ) : (
                            <Field label={t('layout.spacing', { value: Number(text.spacing).toFixed(1) })}>
                                <input type="range" min={design.ranges.spacing[0]} max={design.ranges.spacing[1]} step="0.1" value={text.spacing}
                                    data-a="layout:text-spacing" onChange={(event) => group('text', { spacing: Number(event.target.value) })}
                                    className="w-full accent-brand-primary" />
                            </Field>
                        )}

                        <Toggle
                            label={t('layout.hyphenation')}
                            hint={t('layout.hyphenation_hint')}
                            checked={text.hyphenate}
                            name="text-hyphenate"
                            onChange={(value) => group('text', { hyphenate: value }, { immediate: true })}
                        />

                        <div className="mt-2 grid grid-cols-2 gap-2">
                            <Field label={t('layout.orphans')}>
                                <Select value={String(text.orphans)} name="text-orphans" onChange={(value) => group('text', { orphans: Number(value) }, { immediate: true })}
                                    options={lines()} />
                            </Field>
                            <Field label={t('layout.widows')}>
                                <Select value={String(text.widows)} name="text-widows" onChange={(value) => group('text', { widows: Number(value) }, { immediate: true })}
                                    options={lines()} />
                            </Field>
                        </div>
                        <p className="text-[11px] leading-snug text-text-secondary">
                            {t('layout.orphans_note')}
                        </p>
                    </Card>

                    <Card title={t('layout.card_headings')}>
                        <div className="mb-2 flex flex-wrap gap-1" role="tablist" aria-label={t('layout.heading_level')}>
                            {design.heading_levels.map((key) => (
                                <button key={key} type="button" role="tab" aria-selected={level === key} data-a={`layout:heading-${key}`}
                                    onClick={() => setLevel(key)}
                                    className={`rounded-control border px-2 py-1 text-[11.5px] font-medium ${level === key ? 'border-brand-primary bg-brand-fill text-white' : 'border-border-input text-text-body'}`}>
                                    {key === 'h4' ? 'H4–H6' : key.toUpperCase()}
                                </button>
                            ))}
                        </div>

                        <div className="grid grid-cols-2 gap-2" data-a="layout:heading-style">
                            <Field label={t('layout.heading_size', { value: Number(heading.size).toFixed(2) })}>
                                <input type="range" min={design.ranges.size[0]} max={design.ranges.size[1]} step="0.02" value={heading.size}
                                    data-a="layout:heading-size" onChange={(event) => setHeading({ size: Number(event.target.value) })}
                                    className="w-full accent-brand-primary" />
                            </Field>
                            <Field label={t('layout.weight')}>
                                <Select value={heading.weight} name="heading-weight" onChange={(value) => setHeading({ weight: value }, true)}
                                    options={{ normal: t('layout.regular'), bold: t('layout.bold') }} />
                            </Field>
                            <Field label={t('layout.case')}>
                                <Select value={heading.case} name="heading-case" onChange={(value) => setHeading({ case: value }, true)}
                                    options={{ none: t('layout.as_written'), upper: t('layout.capitals'), smallcaps: t('layout.small_capitals') }} />
                            </Field>
                            <Field label={t('layout.alignment')}>
                                <Select value={heading.align} name="heading-align" onChange={(value) => setHeading({ align: value }, true)}
                                    options={{ left: t('layout.align_left'), center: t('layout.centred'), right: t('layout.align_right') }} />
                            </Field>
                            <Field label={t('layout.space_above', { value: Number(heading.above).toFixed(2) })}>
                                <input type="range" min={design.ranges.above[0]} max={design.ranges.above[1]} step="0.05" value={heading.above}
                                    data-a="layout:heading-above" onChange={(event) => setHeading({ above: Number(event.target.value) })}
                                    className="w-full accent-brand-primary" />
                            </Field>
                            <Field label={t('layout.space_below', { value: Number(heading.below).toFixed(2) })}>
                                <input type="range" min={design.ranges.below[0]} max={design.ranges.below[1]} step="0.05" value={heading.below}
                                    data-a="layout:heading-below" onChange={(event) => setHeading({ below: Number(event.target.value) })}
                                    className="w-full accent-brand-primary" />
                            </Field>
                            <Field label={t('layout.face')}>
                                <Select value={heading.face} name="heading-face" onChange={(value) => setHeading({ face: value }, true)}
                                    options={{ heading: t('layout.heading_face'), body: t('layout.text_face') }} />
                            </Field>
                        </div>
                        <label className="mt-1 flex items-center gap-1.5 text-[12px] text-text-body">
                            <input type="checkbox" checked={heading.italic} data-a="layout:heading-italic"
                                onChange={(event) => setHeading({ italic: event.target.checked }, true)} />
                            {t('layout.italic')}
                        </label>
                    </Card>

                    <Card title={t('layout.card_openers')}>
                        <Field label={t('layout.opener_style')}>
                            <Select value={opener.style} name="opener-style" onChange={(value) => group('opener', { style: value }, { immediate: true })}
                                options={Object.fromEntries(design.opener_styles.map((style) => [style.key, style.name]))} />
                        </Field>

                        <Field label={t('layout.chapter_label')}>
                            <Select value={opener.label} name="opener-label" onChange={(value) => group('opener', { label: value }, { immediate: true })}
                                options={Object.fromEntries(design.labels.map((key) => [key, nameOf(LABELS, key)]))} />
                        </Field>

                        {opener.label === 'custom' && (
                            <Field label={t('layout.word_before')}>
                                <input
                                    type="text"
                                    maxLength={30}
                                    value={opener.custom}
                                    placeholder={t('layout.word_before_placeholder')}
                                    data-a="layout:opener-custom"
                                    onChange={(event) => group('opener', { custom: event.target.value })}
                                    className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                />
                            </Field>
                        )}

                        <Toggle
                            label={t('layout.chapters_recto')}
                            hint={t('layout.chapters_recto_hint')}
                            checked={opener.chapters_recto}
                            name="opener-chapters-recto"
                            onChange={(value) => group('opener', { chapters_recto: value }, { immediate: true })}
                        />
                        <Toggle
                            label={t('layout.parts_recto')}
                            hint={t('layout.parts_recto_hint')}
                            checked={opener.parts_recto}
                            name="opener-parts-recto"
                            onChange={(value) => group('opener', { parts_recto: value }, { immediate: true })}
                        />

                        <p className="mb-1 mt-3 text-[11.5px] font-medium text-text-secondary">{t('layout.first_paragraph')}</p>
                        <Toggle
                            label={t('layout.lead')}
                            hint={t('layout.lead_hint')}
                            checked={opener.lead}
                            name="opener-lead"
                            onChange={(value) => group('opener', { lead: value }, { immediate: true })}
                        />
                        <Toggle
                            label={t('layout.dropcap')}
                            hint={t('layout.dropcap_hint')}
                            checked={layout.dropcap}
                            name="dropcap"
                            onChange={(value) => save({ dropcap: value }, { immediate: true })}
                        />
                        <Toggle
                            label={t('layout.indent_first')}
                            hint={t('layout.indent_first_hint')}
                            checked={opener.indent_first}
                            name="opener-indent-first"
                            onChange={(value) => group('opener', { indent_first: value }, { immediate: true })}
                        />
                    </Card>

                    <Card title={t('layout.card_heads')}>
                        <Toggle
                            label={t('layout.running_heads')}
                            hint={t('layout.running_heads_hint')}
                            checked={layout.headers}
                            name="headers"
                            onChange={(value) => save({ headers: value }, { immediate: true })}
                        />

                        <div className="mt-2 grid grid-cols-2 gap-2">
                            <Field label={t('layout.left_page')}>
                                <Select value={heads.verso} name="heads-verso" disabled={!layout.headers} onChange={(value) => group('heads', { verso: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_sources.map((key) => [key, nameOf(HEAD_SOURCES, key)]))} />
                            </Field>
                            <Field label={t('layout.right_page')}>
                                <Select value={heads.recto} name="heads-recto" disabled={!layout.headers} onChange={(value) => group('heads', { recto: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_sources.map((key) => [key, nameOf(HEAD_SOURCES, key)]))} />
                            </Field>
                            <Field label={t('layout.style')}>
                                <Select value={heads.style} name="heads-style" disabled={!layout.headers} onChange={(value) => group('heads', { style: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_styles.map((key) => [key, nameOf(HEAD_STYLES, key)]))} />
                            </Field>
                            <Field label={t('layout.size')}>
                                <Select value={heads.size} name="heads-size" onChange={(value) => group('heads', { size: value }, { immediate: true })}
                                    options={Object.fromEntries(design.head_sizes.map((key) => [key, nameOf(HEAD_SIZES, key, capitalised(key))]))} />
                            </Field>
                            <Field label={t('layout.position')}>
                                <Select value={heads.align} name="heads-align" disabled={!layout.headers} onChange={(value) => group('heads', { align: value }, { immediate: true })}
                                    options={{ center: t('layout.centred'), outside: t('layout.outside_edge') }} />
                            </Field>
                            <Field label={t('layout.page_numbers')}>
                                <Select value={layout.folio} name="folio" onChange={(value) => save({ folio: value }, { immediate: true })}
                                    options={Object.fromEntries(design.folios.map((key) => [key, nameOf(FOLIOS, key)]))} />
                            </Field>
                        </div>

                        <Toggle
                            label={t('layout.heads_openers')}
                            hint={t('layout.heads_openers_hint')}
                            checked={heads.openers}
                            name="heads-openers"
                            onChange={(value) => group('heads', { openers: value }, { immediate: true })}
                        />
                        <p className="mt-2 text-[11px] leading-snug text-text-secondary">
                            {t('layout.folio_note')}
                        </p>
                    </Card>

                    <Card title={t('layout.card_contents')}>
                        <Field label={t('layout.printed_toc')}>
                            <Select value={String(layout.toc.depth)} name="toc-depth" onChange={(value) => group('toc', { depth: Number(value) }, { immediate: true })}
                                options={named(DEPTHS)} />
                        </Field>
                        <Field label={t('layout.ebook_toc')}>
                            <Select value={String(layout.toc.epub)} name="toc-epub" onChange={(value) => group('toc', { epub: Number(value) }, { immediate: true })}
                                options={named(DEPTHS)} />
                        </Field>
                        <Field label={t('layout.numbering')}>
                            <Select value={layout.numbering ?? ''} name="numbering" onChange={(value) => save({ numbering: value === '' ? null : value }, { immediate: true })}
                                options={named(NUMBERING)} />
                        </Field>
                        <p className="-mt-1 mb-1 text-[11px] leading-snug text-text-secondary">
                            {t('layout.numbering_note')}
                        </p>
                    </Card>

                    <Card title={t('layout.card_boxes')}>
                        <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                            {t('layout.boxes_note')}
                        </p>
                        <Field label={t('layout.style')}>
                            <Select value={callouts.preset} name="callouts-preset" onChange={(value) => save({ callouts: { preset: value, types: {} } }, { immediate: true })}
                                options={Object.fromEntries(design.callout_presets.map((key) => [key, nameOf(CALLOUT_PRESETS, key, capitalised(key))]))} />
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
                                                {t('layout.icon')}
                                            </label>
                                        </div>
                                        <div className="grid grid-cols-[1fr_72px] gap-1.5">
                                            <select
                                                value={style.border}
                                                aria-label={t('layout.box_border', { name })}
                                                data-a={`layout:callout:${key}:border`}
                                                onChange={(event) => setBox(key, { border: event.target.value })}
                                                className="min-w-0 rounded-control border border-border-input px-1.5 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                                            >
                                                {design.borders.map((border) => (
                                                    <option key={border} value={border}>{nameOf(BORDERS, border)}</option>
                                                ))}
                                            </select>
                                            <select
                                                value={style.tint}
                                                aria-label={t('layout.box_tint', { name })}
                                                data-a={`layout:callout:${key}:tint`}
                                                onChange={(event) => setBox(key, { tint: Number(event.target.value) })}
                                                className="rounded-control border border-border-input px-1.5 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                                            >
                                                {design.tints.map((tint) => (
                                                    <option key={tint} value={tint}>{tint === 0 ? t('layout.no_tint') : `${tint}%`}</option>
                                                ))}
                                            </select>
                                            <input
                                                type="text"
                                                maxLength={40}
                                                value={style.label ?? ''}
                                                placeholder={t('layout.box_label_placeholder')}
                                                aria-label={t('layout.box_label', { name })}
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
                    </fieldset>
                </div>

                <section className="min-w-0 rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <h3 className="text-[13px] font-semibold text-text-primary">{t('layout.preview')}</h3>
                        <span className="text-[11px] text-text-secondary" aria-live="polite" data-a="layout:preview-state">
                            {busy ? t('layout.updating') : t('layout.as_pdf')}
                        </span>

                        {sections.length > 1 && (
                            <select
                                value={sectionId ?? ''}
                                data-a="layout:preview-section"
                                aria-label={t('layout.chapter_to_preview')}
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
                                {t('layout.preview_caption', {
                                    trim: layout.trim,
                                    font: design.fonts.find((face) => face.key === layout.font)?.name ?? '',
                                    size: Number(layout.size).toFixed(1),
                                    margins: mirrored ? t('layout.mirrored_word') : (design.margin_presets.find((p) => p.key === layout.margins)?.name ?? '').toLowerCase(),
                                })}
                            </p>
                        </>
                    ) : (
                        <p className="py-8 text-center text-[12px] text-text-secondary">
                            {t('layout.no_chapter')}
                        </p>
                    )}
                </section>
            </div>
        </div>
    );
}

/**
 * A length in the author's unit: typed in inches or millimetres, stored in
 * inches. Committed on Enter or when the field is left, so a half-typed
 * number is never sent.
 */
function Measure({ label, unit, value, name, min, max, onCommit }) {
    const shown = unit === 'mm' ? (value * MM).toFixed(1) : String(Number(Number(value).toFixed(3)));
    const [draft, setDraft] = useState(shown);

    useEffect(() => setDraft(shown), [shown]);

    const commit = () => {
        const number = Number(String(draft).replace(',', '.'));

        if (!Number.isFinite(number) || number <= 0) {
            setDraft(shown);

            return;
        }

        const inches = Math.round((unit === 'mm' ? number / MM : number) * 10000) / 10000;

        if (inches < min - 1e-6 || inches > max + 1e-6) {
            setDraft(shown);

            return;
        }

        if (Math.abs(inches - value) > 1e-6) onCommit(inches);
    };

    return (
        <label className="mb-2.5 block min-w-0">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">{label}</span>
            <span className="flex items-center gap-1">
                <input
                    type="text"
                    inputMode="decimal"
                    value={draft}
                    data-a={`layout:${name}`}
                    onChange={(event) => setDraft(event.target.value)}
                    onBlur={commit}
                    onKeyDown={(event) => event.key === 'Enter' && commit()}
                    className="w-full min-w-0 rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                />
                <span className="text-[11px] text-text-secondary">{unit}</span>
            </span>
        </label>
    );
}

/**
 * House styles: what this book follows, and saving, applying and updating
 * the account's named designs (HouseStyleController). Following locks the
 * book's design to the style; "Customise this book" detaches it.
 */
function HouseStyleCard({ bookId, info, onChanged, onNotify }) {
    const [name, setName] = useState('');
    const [pick, setPick] = useState('');
    const [follow, setFollow] = useState(true);
    const [busy, setBusy] = useState(false);

    if (!info?.can_manage && !info?.following) return null;

    const styles = info.styles ?? [];
    const chosen = pick || styles[0]?.id || '';
    const following = info.following;

    const run = async (request, done) => {
        setBusy(true);

        try {
            const response = await request();
            onNotify?.(done(response.data));
            onChanged();
        } catch (error) {
            const errors = error.response?.data?.errors;
            onNotify?.(errors ? Object.values(errors).flat().join(' ') : (error.response?.data?.message ?? t('layout.failed')));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5" data-a="layout:house-style">
            <h3 className="mb-2 text-[13px] font-semibold text-text-primary">{t('layout.house_style')}</h3>

            {following ? (
                <div className="rounded-control border border-brand-primary/30 bg-brand-primary/5 p-2.5" data-a="layout:house-style:following">
                    <p className="text-[12.5px] text-text-body">
                        {t('layout.follows_before')} <strong className="font-semibold">{following.name}</strong>
                        {t('layout.follows_after')}
                    </p>
                    {info.can_manage && (
                        <button
                            type="button"
                            data-a="layout:house-style:detach"
                            disabled={busy}
                            onClick={() => run(() => window.axios.post(`/books/${bookId}/house-style/detach`), () => t('layout.detached'))}
                            className="mt-2 rounded-control border border-border-input bg-bg-surface px-2.5 py-1.5 text-[12px] font-semibold text-text-body disabled:opacity-50"
                        >
                            {t('layout.customise')}
                        </button>
                    )}
                </div>
            ) : (
                <>
                    {styles.length > 0 && (
                        <div className="mb-3">
                            <Field label={t('layout.apply_style')}>
                                <select
                                    value={chosen}
                                    data-a="layout:house-style:pick"
                                    onChange={(event) => setPick(Number(event.target.value))}
                                    className="w-full min-w-0 rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                >
                                    {styles.map((style) => (
                                        <option key={style.id} value={style.id}>
                                            {t('layout.style_option', { name: style.name, count: style.books })}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <p className="-mt-1.5 mb-2 text-[11px] text-text-secondary">{styles.find((style) => style.id === chosen)?.summary}</p>
                            <label className="mb-2 flex items-center gap-1.5 text-[12px] text-text-body">
                                <input type="checkbox" checked={follow} data-a="layout:house-style:follow" onChange={(event) => setFollow(event.target.checked)} />
                                {t('layout.follow_it')}
                            </label>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    data-a="layout:house-style:apply"
                                    disabled={busy || !chosen}
                                    onClick={() => run(() => window.axios.post(`/house-styles/${chosen}/apply`, { books: [bookId], follow }), (data) => data.results[0]?.message)}
                                    className="rounded-control bg-brand-fill px-2.5 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                                >
                                    {t('layout.apply')}
                                </button>
                                <button
                                    type="button"
                                    data-a="layout:house-style:update"
                                    disabled={busy || !chosen}
                                    onClick={() =>
                                        run(() => window.axios.put(`/house-styles/${chosen}`, { book: bookId }), (data) => t('layout.style_updated', { count: data.updated }))
                                    }
                                    className="rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-semibold text-text-body disabled:opacity-50"
                                >
                                    {t('layout.update_style')}
                                </button>
                            </div>
                        </div>
                    )}

                    <form
                        className="flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const saved = name.trim();
                            run(() => window.axios.post(`/books/${bookId}/house-style`, { name: saved }), () => {
                                setName('');

                                return t('layout.style_saved', { name: saved });
                            });
                        }}
                    >
                        <input
                            type="text"
                            value={name}
                            maxLength={80}
                            placeholder={t('layout.style_name_placeholder')}
                            aria-label={t('layout.style_name')}
                            data-a="layout:house-style:name"
                            onChange={(event) => setName(event.target.value)}
                            className="min-w-0 flex-1 rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                        />
                        <button
                            type="submit"
                            data-a="layout:house-style:save"
                            disabled={busy || name.trim().length < 2}
                            className="shrink-0 rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-semibold text-text-body disabled:opacity-50"
                        >
                            {t('layout.save_as_style')}
                        </button>
                    </form>
                    <p className="mt-1.5 text-[11px] leading-snug text-text-secondary">
                        {t('layout.house_note')}
                    </p>
                </>
            )}
        </section>
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

/** Figure and table numbering (InteriorDesign `numbering`; null infers it from the captions). */
const NUMBERING = {
    '': { tKey: 'layout.numbering_auto' },
    continuous: { tKey: 'layout.numbering_continuous' },
    chapter: { tKey: 'layout.numbering_chapter' },
    part: { tKey: 'layout.numbering_part' },
};

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
