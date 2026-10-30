import { useMemo, useState } from 'react';
import { t, useI18n } from '../../i18n';

/**
 * Export profiles on Export & Publish (ADR-0043 — Export profiles).
 *
 * The presets and this book's saved profiles as one-click chips; the chosen
 * one applies to whichever format is generated next. "Customise…" opens the
 * options with a one-line summary, and "Save as profile" keeps them on the
 * book. Every check is the server's (App\Publishing\ExportOptions): this
 * only gathers the choice and says what each option does where.
 *
 * Controlled: `choice` is { key, options } where key is a preset key,
 * "saved:<id>" or "custom", and options the full option set.
 */

/** The keys the server accepts; anything else stays on the client. */
const KEYS = ['preset', 'id', 'name', 'range', 'cover', 'exclude', 'watermark', 'opacity', 'manuscript', 'manuscript_font', 'contact', 'notes', 'toc_depth', 'closing'];

/** What to send as `profile` for a choice: nothing for an untouched Full book. */
export function profileInput(choice) {
    if (!choice || choice.key === 'full') return undefined;

    if (choice.key.startsWith('saved:') && !choice.changed) {
        return { id: choice.options.id };
    }

    if (!choice.changed && choice.key !== 'custom') {
        return { preset: choice.key };
    }

    const input = Object.fromEntries(Object.entries(choice.options).filter(([key]) => KEYS.includes(key)));
    delete input.name;

    if (choice.key.startsWith('saved:')) {
        return { ...input, id: choice.options.id, preset: undefined };
    }

    return { ...input, id: undefined, preset: 'custom' };
}

/** Whether the choice can make a file in this format, and why not. */
export function formatProblem(choice, format, profiles) {
    if (!choice || choice.key === 'full') return null;

    if (!profiles.formats.includes(format)) {
        return t('export.not_applicable');
    }

    if (choice.options.manuscript && !profiles.manuscript_formats.includes(format)) {
        return t('export.manuscript_formats');
    }

    return null;
}

/** The options in a line, as the server words a preset's. */
export function summarise(options) {
    const parts = [];
    const range = options.range;

    if (range?.first) parts.push(t('export.sum_first', { count: range.first }));
    else if (range?.from) parts.push(range.chapters ? t('export.sum_chapters', { from: range.chapters[0], to: range.chapters[1] }) : t('export.sum_range'));
    else parts.push(t('export.sum_whole'));

    if (options.manuscript) parts.push(t('export.sum_manuscript'));
    if (!options.cover) parts.push(t('export.sum_no_cover'));
    if (options.exclude?.length) parts.push(t('export.sum_excluded', { count: options.exclude.length }));
    if (options.watermark) parts.push(t('export.sum_watermark', { text: options.watermark }));
    if (options.notes === 'book') parts.push(t('export.sum_notes_book'));
    if (options.toc_depth) parts.push(t('export.sum_contents', { count: options.toc_depth }));
    if (options.closing) parts.push(t('export.closing'));

    return parts.join(' · ');
}

export default function ExportProfiles({ bookId, profiles, choice, onChoose, onSaved, onNotify }) {
    useI18n();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});

    const chips = useMemo(
        () => [
            ...profiles.presets.map((preset) => ({ key: preset.key, name: preset.name, options: preset.options, summary: preset.summary })),
            ...profiles.saved.map((saved) => ({ key: `saved:${saved.id}`, name: saved.name, options: saved.options, summary: saved.summary })),
        ],
        [profiles],
    );

    const options = choice.options;
    const savedId = choice.key.startsWith('saved:') ? choice.options.id : null;

    const pick = (chip) => {
        setErrors({});
        onChoose({ key: chip.key, options: { ...chip.options }, changed: false });
    };

    const change = (changes) => {
        setErrors({});
        onChoose({
            key: choice.key === 'full' ? 'custom' : choice.key,
            options: { ...options, ...changes },
            changed: true,
        });
    };

    const request = async (method, url, body) => {
        setBusy(true);
        setErrors({});

        try {
            const response = await window.axios[method](url, body);
            onSaved(response.data);

            return response.data;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? {});
            onNotify?.(error.response?.data?.message ?? t('export.not_saved'));

            return null;
        } finally {
            setBusy(false);
        }
    };

    const optionsToSave = () => {
        const input = profileInput({ ...choice, changed: true }) ?? {};
        delete input.id;

        return { ...input, preset: 'custom' };
    };

    const saveAs = async () => {
        const data = await request('post', `/books/${bookId}/export-profiles`, { name, options: optionsToSave() });

        if (data) {
            const saved = data.profiles.saved.find((item) => item.id === data.profile);
            onNotify?.(t('export.saved_as', { name: saved.name }));
            setName('');
            onChoose({ key: `saved:${saved.id}`, options: { ...saved.options }, changed: false });
        }
    };

    const update = async () => {
        const data = await request('put', `/books/${bookId}/export-profiles/${savedId}`, { options: optionsToSave() });

        if (data) {
            const saved = data.profiles.saved.find((item) => item.id === savedId);
            onNotify?.(t('export.updated', { name: saved.name }));
            onChoose({ key: `saved:${saved.id}`, options: { ...saved.options }, changed: false });
        }
    };

    const remove = async () => {
        if (!window.confirm(t('export.confirm_delete', { name: options.name }))) return;

        const data = await request('delete', `/books/${bookId}/export-profiles/${savedId}`);

        if (data) {
            onChoose({ key: 'full', options: { ...profiles.presets[0].options }, changed: false });
        }
    };

    const rangeMode = options.range?.first ? 'first' : options.range?.from ? 'between' : 'all';
    const sections = profiles.sections;
    const firstChapter = sections.find((section) => section.chapter)?.id;
    const error = (key) => errors[`profile.${key}`]?.[0] ?? errors[key]?.[0];
    const summary = choice.changed || choice.key === 'custom' ? summarise(options) : chips.find((chip) => chip.key === choice.key)?.summary ?? summarise(options);

    return (
        <div className="mb-3 border-b border-border-default pb-3" data-a="export-profiles">
            <p id="export-profile-label" className="mb-1.5 text-[11.5px] font-medium text-text-secondary">
                {t('export.next_file')}
            </p>

            <div role="radiogroup" aria-labelledby="export-profile-label" className="flex flex-wrap gap-1.5">
                {chips.map((chip) => {
                    const active = choice.key === chip.key;

                    return (
                        <button
                            key={chip.key}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            data-a={`export-profile:${chip.key}`}
                            title={chip.summary}
                            onClick={() => pick(chip)}
                            className={`rounded-full border px-2.5 py-1 text-[11.5px] font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary ${
                                active
                                    ? 'border-brand-primary bg-brand-fill text-white'
                                    : 'border-border-input text-text-body hover:border-brand-primary/40'
                            }`}
                        >
                            {chip.name}
                            {active && choice.changed ? ` ${t('export.changed')}` : ''}
                        </button>
                    );
                })}

                {choice.key === 'custom' && (
                    <span role="radio" aria-checked="true" data-a="export-profile:custom"
                        className="rounded-full border border-brand-primary bg-brand-fill px-2.5 py-1 text-[11.5px] font-medium text-white">
                        {t('export.custom')}
                    </span>
                )}

                <button
                    type="button"
                    data-a="export-profile:customise"
                    aria-expanded={open}
                    aria-controls="export-profile-panel"
                    onClick={() => setOpen((value) => !value)}
                    className="rounded-full px-2 py-1 text-[11.5px] font-semibold text-brand-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary"
                >
                    {open ? t('export.close_options') : t('export.customise')}
                </button>
            </div>

            <p className="mt-1.5 text-[11px] leading-snug text-text-secondary" data-a="export-profile:summary" aria-live="polite">
                {summary}
                {choice.key !== 'full' && ` · ${t('export.not_for_stores')}`}
            </p>

            {open && (
                <div id="export-profile-panel" data-a="export-profile:panel" className="mt-3 space-y-3 rounded-tile border border-border-default bg-bg-subtle p-3">
                    <fieldset>
                        <legend className="mb-1 text-[11.5px] font-semibold text-text-primary">{t('export.chapters')}</legend>
                        <div className="space-y-1 text-[12px] text-text-body">
                            <label className="flex items-center gap-2">
                                <input type="radio" name="ep-range" checked={rangeMode === 'all'} data-a="export-profile:range-all"
                                    onChange={() => change({ range: null })} />
                                {t('export.whole')}
                            </label>
                            <label className="flex flex-wrap items-center gap-2">
                                <input type="radio" name="ep-range" checked={rangeMode === 'first'} data-a="export-profile:range-first"
                                    onChange={() => change({ range: { first: Math.min(2, profiles.chapters || 1) } })} />
                                {t('export.the_first')}
                                <input type="number" min={1} max={profiles.chapters} aria-label={t('export.number_of_chapters')}
                                    data-a="export-profile:first"
                                    value={options.range?.first ?? ''} disabled={rangeMode !== 'first'}
                                    onChange={(event) => change({ range: { first: Number(event.target.value) || 1 } })}
                                    className="w-16 rounded-control border border-border-input bg-bg-surface px-1.5 py-0.5 text-[12px]" />
                                {t('export.of_chapters', { count: profiles.chapters })}
                            </label>
                            <label className="flex flex-wrap items-center gap-2">
                                <input type="radio" name="ep-range" checked={rangeMode === 'between'} data-a="export-profile:range-between"
                                    onChange={() => change({ range: { from: firstChapter, to: firstChapter } })} />
                                {t('export.from')}
                                <SectionSelect label={t('export.first_section')} sections={sections} disabled={rangeMode !== 'between'}
                                    value={options.range?.from} onChange={(from) => change({ range: { ...options.range, from, chapters: undefined } })} />
                                {t('export.to')}
                                <SectionSelect label={t('export.last_section')} sections={sections} disabled={rangeMode !== 'between'}
                                    value={options.range?.to} onChange={(to) => change({ range: { ...options.range, to, chapters: undefined } })} />
                            </label>
                        </div>
                        <Error message={error('range')} />
                    </fieldset>

                    <label className="flex items-start gap-2 text-[12px] text-text-body">
                        <input type="checkbox" checked={options.cover} data-a="export-profile:cover"
                            onChange={(event) => change({ cover: event.target.checked })} className="mt-0.5" />
                        <span>
                            {t('export.include_cover')}
                            <span className="block text-[11px] text-text-secondary">{t('export.include_cover_help')}</span>
                        </span>
                    </label>

                    <details className="text-[12px] text-text-body">
                        <summary className="cursor-pointer text-[11.5px] font-semibold text-text-primary">
                            {t('export.leave_out')}{options.exclude.length ? ` (${options.exclude.length})` : ''}
                        </summary>
                        <MatterChecklist matter={profiles.matter} selected={options.exclude} name="export-profile:exclude"
                            onChange={(exclude) => change({ exclude })} />
                    </details>
                    <Error message={error('exclude')} />

                    <div>
                        <label className="block text-[11.5px] font-semibold text-text-primary" htmlFor="ep-watermark">{t('export.watermark')}</label>
                        <input id="ep-watermark" data-a="export-profile:watermark" maxLength={profiles.watermark_max}
                            value={options.watermark ?? ''} placeholder={t('export.none')}
                            onChange={(event) => change({ watermark: event.target.value || null })}
                            className="mt-1 w-full rounded-control border border-border-input bg-bg-surface px-2 py-1 text-[12px]" />
                        {options.watermark && (
                            <label className="mt-1 flex items-center gap-2 text-[11px] text-text-secondary">
                                {t('export.opacity')}
                                <input type="range" min={5} max={60} step={5} aria-label={t('export.watermark_opacity')} data-a="export-profile:opacity"
                                    value={Math.round((options.opacity ?? 0.15) * 100)}
                                    onChange={(event) => change({ opacity: Number(event.target.value) / 100 })} />
                                {Math.round((options.opacity ?? 0.15) * 100)} %
                            </label>
                        )}
                        <p className="mt-1 text-[11px] text-text-secondary">
                            {t('export.watermark_help')}
                        </p>
                        <Error message={error('watermark') ?? error('opacity')} />
                    </div>

                    <div>
                        <label className="flex items-start gap-2 text-[12px] text-text-body">
                            <input type="checkbox" checked={options.manuscript} data-a="export-profile:manuscript"
                                onChange={(event) => change({ manuscript: event.target.checked })} className="mt-0.5" />
                            <span>
                                {t('export.manuscript')}
                                <span className="block text-[11px] text-text-secondary">{t('export.manuscript_help')}</span>
                            </span>
                        </label>
                        {options.manuscript && (
                            <div className="mt-1.5 grid gap-2 pl-6 sm:grid-cols-2">
                                <label className="text-[11px] text-text-secondary">
                                    {t('export.typeface')}
                                    <select value={options.manuscript_font} data-a="export-profile:manuscript-font"
                                        onChange={(event) => change({ manuscript_font: event.target.value })}
                                        className="mt-0.5 block w-full rounded-control border border-border-input bg-bg-surface px-1.5 py-1 text-[12px] text-text-body">
                                        <option value="times">{t('export.font_times')}</option>
                                        <option value="courier">{t('export.font_courier')}</option>
                                    </select>
                                </label>
                                <label className="text-[11px] text-text-secondary sm:col-span-2">
                                    {t('export.contact')}
                                    <textarea rows={3} maxLength={300} value={options.contact ?? ''} data-a="export-profile:contact"
                                        placeholder={t('export.contact_placeholder')}
                                        onChange={(event) => change({ contact: event.target.value || null })}
                                        className="mt-0.5 block w-full rounded-control border border-border-input bg-bg-surface px-2 py-1 text-[12px] text-text-body" />
                                </label>
                            </div>
                        )}
                        <Error message={error('manuscript') ?? error('contact')} />
                    </div>

                    <fieldset>
                        <legend className="mb-1 text-[11.5px] font-semibold text-text-primary">{t('export.notes')}</legend>
                        <div className="space-y-1 text-[12px] text-text-body">
                            {[['chapter', t('export.notes_chapter')], ['book', t('export.notes_book')]].map(([key, label]) => (
                                <label key={key} className="flex items-center gap-2">
                                    <input type="radio" name="ep-notes" checked={options.notes === key} data-a={`export-profile:notes-${key}`}
                                        onChange={() => change({ notes: key })} />
                                    {label}
                                </label>
                            ))}
                            <label className="flex items-start gap-2 text-text-secondary">
                                <input type="radio" name="ep-notes" disabled className="mt-0.5" />
                                <span>
                                    {t('export.notes_page')}
                                    <span className="block text-[11px]">{profiles.page_notes_reason}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-[11.5px] font-semibold text-text-primary">
                            {t('export.toc_depth')}
                            <select value={options.toc_depth ?? ''} data-a="export-profile:toc-depth"
                                onChange={(event) => change({ toc_depth: event.target.value ? Number(event.target.value) : null })}
                                className="mt-1 block w-full rounded-control border border-border-input bg-bg-surface px-1.5 py-1 text-[12px] font-normal text-text-body">
                                <option value="">{t('export.toc_design')}</option>
                                <option value="1">{t('export.toc_1')}</option>
                                <option value="2">{t('export.toc_2')}</option>
                                <option value="3">{t('export.toc_3')}</option>
                            </select>
                        </label>
                        <label className="flex items-start gap-2 pt-4 text-[12px] text-text-body">
                            <input type="checkbox" checked={options.closing} data-a="export-profile:closing"
                                onChange={(event) => change({ closing: event.target.checked })} className="mt-0.5" />
                            <span>
                                {t('export.closing')}
                                <span className="block text-[11px] text-text-secondary">{t('export.closing_help')}</span>
                            </span>
                        </label>
                    </div>

                    <div className="flex flex-wrap items-end gap-2 border-t border-border-default pt-3">
                        <label className="min-w-[10rem] flex-1 text-[11.5px] font-semibold text-text-primary">
                            {t('export.save_as')}
                            <input value={name} maxLength={60} data-a="export-profile:name" placeholder={t('export.name_placeholder')}
                                onChange={(event) => setName(event.target.value)}
                                className="mt-1 block w-full rounded-control border border-border-input bg-bg-surface px-2 py-1 text-[12px] font-normal text-text-body" />
                        </label>
                        <button type="button" data-a="export-profile:save" disabled={busy || name.trim() === ''} onClick={saveAs}
                            className="rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50">
                            {t('export.save_as')}
                        </button>
                        {savedId && (
                            <>
                                <button type="button" data-a="export-profile:update" disabled={busy || !choice.changed} onClick={update}
                                    className="rounded-control border border-border-input px-3 py-1.5 text-[12px] font-medium text-text-body disabled:opacity-50">
                                    {t('export.update', { name: options.name })}
                                </button>
                                <button type="button" data-a="export-profile:delete" disabled={busy} onClick={remove}
                                    className="rounded-control px-2 py-1.5 text-[12px] font-medium text-status-danger hover:underline disabled:opacity-50">
                                    {t('export.delete')}
                                </button>
                            </>
                        )}
                    </div>
                    <Error message={error('name')} />

                    <BookMatter bookId={bookId} profiles={profiles} onSaved={onSaved} onNotify={onNotify} />
                </div>
            )}
        </div>
    );
}

function SectionSelect({ label, sections, value, onChange, disabled }) {
    return (
        <select aria-label={label} value={value ?? ''} disabled={disabled} onChange={(event) => onChange(Number(event.target.value))}
            className="max-w-[12rem] rounded-control border border-border-input bg-bg-surface px-1.5 py-0.5 text-[12px] disabled:opacity-50">
            {sections.map((section) => (
                <option key={section.id} value={section.id}>
                    {section.chapter ? `${section.chapter}. ` : ''}
                    {section.title}
                </option>
            ))}
        </select>
    );
}

function MatterChecklist({ matter, selected, onChange, name }) {
    const toggle = (key, on) => onChange(on ? [...selected, key] : selected.filter((item) => item !== key));

    return (
        <div className="mt-1.5 grid gap-3 sm:grid-cols-2">
            {[['front', t('export.front')], ['back', t('export.back')]].map(([group, heading]) => (
                <fieldset key={group}>
                    <legend className="mb-0.5 text-[11px] font-semibold uppercase tracking-wide text-text-secondary">{heading}</legend>
                    {matter[group].map((item) => (
                        <label key={item.key} className="flex items-center gap-2 py-0.5 text-[12px]">
                            <input type="checkbox" data-a={`${name}:${item.key}`} checked={selected.includes(item.key)}
                                onChange={(event) => toggle(item.key, event.target.checked)} />
                            {item.label}
                        </label>
                    ))}
                </fieldset>
            ))}
        </div>
    );
}

/**
 * The book's own matter per format family (E3): left out of every export
 * of it, Full book included. Saved at once.
 */
function BookMatter({ bookId, profiles, onSaved, onNotify }) {
    const [family, setFamily] = useState('ebook');
    const selected = profiles.book_matter[family];

    const save = async (next) => {
        try {
            const response = await window.axios.put(`/books/${bookId}/export-matter`, { ...profiles.book_matter, [family]: next });
            onSaved(response.data);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('publish.change_not_saved'));
        }
    };

    return (
        <details className="border-t border-border-default pt-3 text-[12px] text-text-body" data-a="export-profile:book-matter">
            <summary className="cursor-pointer text-[11.5px] font-semibold text-text-primary">
                {t('export.book_matter')}
            </summary>
            <p className="mt-1 text-[11px] text-text-secondary">
                {t('export.book_matter_help')}
            </p>
            <div role="group" aria-label={t('export.format')} className="mt-1.5 inline-flex overflow-hidden rounded-control border border-border-input">
                {[['ebook', 'EPUB'], ['print', t('export.print_pdf')]].map(([key, label]) => (
                    <button key={key} type="button" aria-pressed={family === key} data-a={`export-matter:${key}`} onClick={() => setFamily(key)}
                        className={`px-2 py-1 text-[11.5px] font-medium ${family === key ? 'bg-brand-fill text-white' : 'text-text-body'}`}>
                        {label}
                    </button>
                ))}
            </div>
            <MatterChecklist matter={profiles.matter} selected={selected} name={`export-matter:${family}`} onChange={save} />
        </details>
    );
}

function Error({ message }) {
    return message ? <p role="alert" className="mt-1 text-[11px] font-medium text-status-danger">{message}</p> : null;
}
