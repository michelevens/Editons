import { useCallback, useEffect, useRef, useState } from 'react';
import { t, useI18n } from '../../i18n';
import Icon from './Icon';
import BackgroundEditor, { scopeName } from './cover/BackgroundEditor';
import CoverTemplateCard from './cover/CoverTemplateCard';
import Framing from './cover/Framing';
import LayerEditor from './cover/LayerEditor';
import WrapEditor from './cover/WrapEditor';

/**
 * Cover Studio.
 *
 * The preview is the cover itself: the server draws it at 1600 × 2560, the
 * size stores ask for, and that same image downloads and goes into the EPUB.
 * The browser used to draw its own version, which no file ever matched.
 *
 * Two ways to set the type. A layout places and fits it for the author.
 * "Customise text" turns that into text layers (LayerEditor): boxes the
 * author drags, sizes and styles, drawn live in the browser by the same
 * algorithm and the same font files as the server's JPEG.
 *
 * The print cover's back and spine, and the whole wrap, are views of their
 * own (WrapEditor): the same layers, on the print service's real geometry.
 */

const VIEWS = [
    { key: 'front', tKey: 'cover.view_front' },
    { key: 'back', tKey: 'cover.view_back' },
    { key: 'spine', tKey: 'cover.view_spine' },
    { key: 'wrap', tKey: 'cover.view_wrap' },
];

/** Cover fields whose text a bound layer shows, mirrored into cover.texts. */
const TEXT_FIELDS = ['title', 'subtitle', 'author', 'tagline'];

/** A cover with changes applied locally, bound layers' texts included. */
function overlay(cover, changes) {
    const texts = { ...cover.texts };

    for (const field of TEXT_FIELDS) {
        if (field in changes) texts[field] = String(changes[field] ?? '').trim();
    }

    return { ...cover, ...changes, texts };
}

export default function CoverPanel({ bookId, onNotify }) {
    useI18n();
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
    const [view, setView] = useState('front');
    const [bgScope, setBgScope] = useState('front');
    // Bumped when a save lands, so the wrap views redraw from the server.
    const [savedAt, setSavedAt] = useState(0);
    // Bumped when the layers change from outside the editor (customise,
    // reset, an AI concept, a reload), so the editor starts from them.
    const [epoch, setEpoch] = useState(0);
    const fileInput = useRef(null);
    const pending = useRef(null);
    // Every change since the last send. A single timer used to send only
    // the last edit, so a second field typed within the debounce dropped
    // the first, and the server's reply then put the old value back.
    const queued = useRef({});
    // Which send is the latest: an older reply arriving after a newer
    // request was sent is stale and must not replace what is on screen.
    const sent = useRef(0);
    const notify = useRef(onNotify);
    notify.current = onNotify;

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/design`);
        setData(response.data);
        setEpoch((e) => e + 1);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const send = useCallback(async () => {
        const batch = queued.current;
        queued.current = {};

        if (Object.keys(batch).length === 0) return;

        const mine = ++sent.current;

        try {
            const response = await window.axios.patch(`/books/${bookId}/cover`, batch);

            // The text kept moving: each reply replaced the cover with the
            // values of its own request, so whatever was typed while it was
            // in flight vanished from the field and came back a save later.
            // A reply now only lands if it is the latest, and under what is
            // still waiting to be sent.
            if (mine === sent.current) {
                setData({ ...response.data, cover: overlay(response.data.cover, queued.current) });
                setSavedAt(Date.now());
            }
        } catch (error) {
            notify.current?.(
                error.response?.data?.message ?? t('cover.change_not_saved'),
            );
            load();
        }
    }, [bookId, load]);

    const save = useCallback(
        (changes, { immediate = false } = {}) => {
            setData((current) => ({ ...current, cover: overlay(current.cover, changes) }));

            queued.current = { ...queued.current, ...changes };
            clearTimeout(pending.current);

            // Typing in a text field debounces; picking a colour saves at once.
            if (immediate) send();
            else pending.current = setTimeout(send, 500);
        },
        [send],
    );

    // Leaving the pane inside the debounce still saves the last edit.
    useEffect(
        () => () => {
            clearTimeout(pending.current);
            send();
        },
        [send],
    );

    const onLayers = useCallback((doc) => save({ layers: doc }), [save]);
    const onText = useCallback((field, value) => save({ [field]: value }), [save]);

    /** A picture for image layers; resolves to it, or null with a notice. */
    const uploadPicture = useCallback(
        async (file) => {
            const body = new FormData();
            body.append('image', file);

            try {
                const response = await window.axios.post(`/books/${bookId}/cover/images`, body);
                setData((current) => ({ ...current, cover: { ...current.cover, images: response.data.images } }));

                return response.data.image;
            } catch (error) {
                notify.current?.(error.response?.data?.errors?.image?.[0] ?? error.response?.data?.message ?? t('cover.picture_failed'));

                return null;
            }
        },
        [bookId],
    );

    const customise = async () => {
        setBusy(true);

        try {
            clearTimeout(pending.current);
            await send();
            const response = await window.axios.post(`/books/${bookId}/cover/layers`);
            setData(response.data);
            setEpoch((e) => e + 1);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('cover.customise_failed'));
        } finally {
            setBusy(false);
        }
    };

    const resetToLayout = async () => {
        if (!window.confirm(t('cover.confirm_reset_layout'))) {
            return;
        }

        setBusy(true);

        try {
            clearTimeout(pending.current);
            queued.current = {};
            const response = await window.axios.delete(`/books/${bookId}/cover/layers`);
            sent.current++;
            setData(response.data);
            setEpoch((e) => e + 1);
        } finally {
            setBusy(false);
        }
    };

    const upload = async (file) => {
        if (!file) return;

        setBusy(true);

        const body = new FormData();
        body.append('artwork', file);

        try {
            const response = await window.axios.post(
                `/books/${bookId}/cover/artwork`,
                body,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );

            setData({ ...response.data, cover: overlay(response.data.cover, queued.current) });
        } catch (error) {
            // The server names the allowed types; echoing its message beats
            // inventing one that might disagree with it.
            onNotify?.(
                error.response?.data?.errors?.artwork?.[0]
                    ?? t('cover.artwork_failed'),
            );
        } finally {
            setBusy(false);

            // Cleared so re-picking the same file fires a change event.
            if (fileInput.current) fileInput.current.value = '';
        }
    };

    // AI: Claude proposes concepts; an image model paints the artwork.
    // Each call is charged by the server, which says so if it cannot run.
    const [thinking, setThinking] = useState(null); // 'concepts' | 'apply' | 'paint' | null
    const [brief, setBrief] = useState(null);

    const runAi = async (kind, request) => {
        setThinking(kind);

        try {
            await request();
            await load();
        } catch (error) {
            onNotify?.(
                error.response?.data?.errors?.credits?.[0]
                    ?? error.response?.data?.message
                    ?? t('cover.ai_failed'),
            );
        } finally {
            setThinking(null);
        }
    };

    const propose = () =>
        runAi('concepts', () => window.axios.post(`/books/${bookId}/cover/concepts`));

    const applyConcept = (index) =>
        runAi('apply', async () => {
            await window.axios.post(`/books/${bookId}/cover/concepts/${index}/apply`);
            setBrief(null);
        });

    const paint = (text) =>
        runAi('paint', () => window.axios.post(`/books/${bookId}/cover/artwork/generate`, { brief: text }));

    const removeArtwork = async () => {
        setBusy(true);

        try {
            const response = await window.axios.delete(`/books/${bookId}/cover/artwork`);
            setData({ ...response.data, cover: overlay(response.data.cover, queued.current) });
        } finally {
            setBusy(false);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { cover, palettes, layouts } = data;
    const layered = Boolean(cover.layers);
    // A book that follows a cover template takes its design from it; only
    // its own text is edited until it is detached (CoverTemplates).
    const following = Boolean(cover.template?.following);
    const backgrounds = { front: null, back: null, spine: null, wrap: null, ...(cover.backgrounds ?? {}) };
    const setBackground = (scope, bg) => save({ backgrounds: { ...backgrounds, [scope]: bg } });

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">{t('cover.studio')}</h2>
                <p className="text-[11.5px] text-text-secondary">
                    {view === 'front'
                        ? t('cover.front_size')
                        : t('cover.wrap_intro')}
                </p>
                <div role="tablist" aria-label={t('cover.part')} className="mt-2 flex flex-wrap gap-1">
                    {VIEWS.map(({ key, tKey }) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={view === key}
                            data-a={`cover:view:${key}`}
                            onClick={() => {
                                clearTimeout(pending.current);
                                send();
                                setView(key);
                            }}
                            className={`min-h-[34px] rounded-control border px-3 text-[12px] font-medium transition ${
                                view === key ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body hover:border-brand-primary/40'
                            }`}
                        >
                            {t(tKey)}
                        </button>
                    ))}
                </div>
            </header>

            <div className="space-y-3 px-4 pt-3">
                <CoverTemplateCard bookId={bookId} template={cover.template} onChanged={load} onNotify={onNotify} />
                <ContrastList bookId={bookId} savedAt={savedAt} epoch={epoch} view={view} />
            </div>

            <div inert={following || undefined} aria-disabled={following || undefined} className={following ? 'pointer-events-none select-none opacity-60' : undefined} data-a="cover:editing">
            {view !== 'front' ? (
                <div className="min-h-0 flex-1 p-4">
                    <WrapEditor
                        bookId={bookId}
                        data={data}
                        view={view}
                        save={save}
                        savedAt={savedAt}
                        images={cover.images ?? []}
                        onUpload={uploadPicture}
                        onNotify={onNotify}
                        backgrounds={backgrounds}
                        onBackground={setBackground}
                        palettes={palettes}
                    />
                </div>
            ) : (
                <div className="min-h-0 flex-1 space-y-4 p-4">
                    {layered ? (
                        <LayerEditor
                            key={epoch}
                            data={data}
                            images={cover.images ?? []}
                            onLayers={onLayers}
                            onText={onText}
                            onReset={resetToLayout}
                            onUpload={uploadPicture}
                            onNotify={onNotify}
                        />
                    ) : (
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[300px_1fr]">
                            <section className="flex flex-col items-center gap-3 rounded-tile border border-border-default bg-bg-subtle p-4">
                                <SteadyImage
                                    src={cover.image}
                                    alt={t('cover.alt', { title: cover.title ?? t('cover.the_book') })}
                                    data-a="cover:preview"
                                    width={250}
                                    height={400}
                                    className="h-[400px] w-[250px] rounded-[3px] bg-bg-surface object-cover shadow-[0_2px_14px_rgba(0,0,0,0.22)]"
                                />

                                <a
                                    href={cover.download}
                                    data-a="cover:download"
                                    className="inline-flex items-center gap-1.5 rounded-control border border-border-input bg-bg-surface px-3 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40"
                                >
                                    <Icon name="exp" size={13} strokeWidth={2} />
                                    {t('cover.download')}
                                </a>
                            </section>

                            <div className="min-w-0 space-y-4">
                                <section className="rounded-tile border border-brand-primary/30 bg-brand-primary-subtle p-3.5">
                                    <h3 className="text-[13px] font-semibold text-text-primary">{t('cover.place_title')}</h3>
                                    <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                                        {t('cover.place_body')}
                                    </p>
                                    <button
                                        type="button"
                                        data-a="cover:customise"
                                        onClick={customise}
                                        disabled={busy}
                                        className="mt-2.5 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                                    >
                                        {t('cover.customise')}
                                    </button>
                                </section>

                                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                                    <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">{t('cover.text')}</h3>

                                    <Field label={t('cover.field_title')}>
                                        <input
                                            value={cover.title ?? ''}
                                            data-a="cover:title"
                                            onChange={(event) => save({ title: event.target.value })}
                                            className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                        />
                                    </Field>

                                    <Field label={t('cover.field_subtitle')}>
                                        <input
                                            value={cover.subtitle ?? ''}
                                            data-a="cover:subtitle"
                                            onChange={(event) => save({ subtitle: event.target.value })}
                                            className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                        />
                                    </Field>

                                    <Field label={t('cover.field_author')}>
                                        <input
                                            value={cover.author ?? ''}
                                            data-a="cover:author"
                                            onChange={(event) => save({ author: event.target.value })}
                                            className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                        />
                                    </Field>

                                    <Field label={t('cover.typeface')}>
                                        <select
                                            value={cover.font}
                                            data-a="cover:font"
                                            onChange={(event) => save({ font: event.target.value }, { immediate: true })}
                                            className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                        >
                                            <option value="serif">{t('cover.font_serif')}</option>
                                            <option value="sans">{t('cover.font_sans')}</option>
                                        </select>
                                    </Field>
                                </section>

                                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                                    <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">{t('cover.layout')}</h3>

                                    <div role="group" aria-label={t('cover.layout')} className="flex flex-wrap gap-2">
                                        {layouts.map((layout) => (
                                            <button
                                                key={layout.key}
                                                aria-pressed={cover.layout === layout.key}
                                                data-a={`cover:layout:${layout.key}`}
                                                onClick={() => save({ layout: layout.key }, { immediate: true })}
                                                className={`rounded-control border px-3 py-1.5 text-[12px] font-medium transition ${
                                                    cover.layout === layout.key
                                                        ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary'
                                                        : 'border-border-input text-text-body hover:border-brand-primary/40'
                                                }`}
                                            >
                                                {layout.name}
                                            </button>
                                        ))}
                                    </div>
                                </section>
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                            <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">
                                {layered ? t('cover.background') : t('cover.artwork')}
                            </h3>

                            {/* Any colour or gradient, for the front, the back,
                                the spine or across the whole wrap; the six
                                palettes are the quick picks. */}
                            <BackgroundEditor
                                scope={bgScope}
                                scopes={['front', 'back', 'spine', 'wrap']}
                                onScope={setBgScope}
                                value={backgrounds[bgScope]}
                                onChange={(bg) => setBackground(bgScope, bg)}
                                palettes={palettes}
                                paletteIndex={cover.artwork ? null : cover.palette}
                                onPalette={(index) => save({ palette: index, backgrounds: { ...backgrounds, front: null } }, { immediate: true })}
                                images={cover.images ?? []}
                                onUpload={uploadPicture}
                            />
                            {bgScope === 'front' && cover.artwork && (
                                <p className="mt-2 text-[11px] text-text-secondary">{t('cover.artwork_covers_front')}</p>
                            )}
                            {bgScope === 'wrap' && backgrounds.wrap?.kind === 'image' && (
                                <p className="mt-2 text-[11px] text-text-secondary">{t('cover.frame_in_wrap')}</p>
                            )}

                            {cover.artwork && (
                                <>
                                    <p className="mt-2.5 rounded bg-status-info-bg px-2 py-1.5 text-[11px] text-status-info">
                                        {t('cover.artwork_in_use')}
                                    </p>

                                    {/* Darkens the picture under the type, so the title
                                        reads on any photograph. Layers keep the layout's
                                        treatment: classic and bold darken, band and a
                                        finished cover do not. */}
                                    {cover.artwork_image && (
                                        <div className="mt-3">
                                            <Framing
                                                image={cover.artwork_image}
                                                framing={cover.framing}
                                                frameWidth={data.editor.width}
                                                frameHeight={data.editor.height}
                                                onChange={(framing) => save({ framing })}
                                            />
                                        </div>
                                    )}

                                    {(!layered || ['classic', 'bold'].includes(cover.layout)) && (
                                        <label className="mt-3 block">
                                            <span className="mb-1 flex justify-between text-[11.5px] font-medium text-text-secondary">
                                                <span>{t('cover.darken')}</span>
                                                <span>{cover.shade}%</span>
                                            </span>
                                            <input
                                                type="range"
                                                min={0}
                                                max={100}
                                                step={5}
                                                value={cover.shade}
                                                data-a="cover:shade"
                                                onChange={(event) => save({ shade: Number(event.target.value) })}
                                                className="w-full accent-brand-primary"
                                            />
                                        </label>
                                    )}
                                </>
                            )}

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <label
                                    htmlFor="cover-artwork"
                                    className="cursor-pointer rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40"
                                >
                                    <span className="inline-flex items-center gap-1.5">
                                        <Icon name="image" size={13} strokeWidth={2} />
                                        {busy ? t('cover.working') : t('cover.upload_artwork')}
                                    </span>
                                </label>

                                <input
                                    id="cover-artwork"
                                    ref={fileInput}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    data-a="cover:artwork"
                                    disabled={busy}
                                    onChange={(event) => upload(event.target.files?.[0])}
                                    className="sr-only"
                                />

                                {cover.artwork && (
                                    <button
                                        data-a="cover:artwork:remove"
                                        onClick={removeArtwork}
                                        disabled={busy}
                                        className="rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-status-danger/40 disabled:opacity-50"
                                    >
                                        {t('cover.remove_artwork')}
                                    </button>
                                )}
                            </div>

                            <p className="mt-2 text-[11px] text-text-secondary">
                                {t('cover.artwork_types')}
                            </p>
                        </section>

                        <AiDesigner
                            data={data}
                            brief={brief ?? cover.brief ?? ''}
                            onBrief={setBrief}
                            thinking={thinking}
                            onPropose={propose}
                            onApply={applyConcept}
                            onPaint={paint}
                        />
                    </div>
                </div>
            )}
            </div>
        </div>
    );
}

function sourceName(source) {
    return {
        title: t('cover.field_title'), subtitle: t('cover.field_subtitle'), author: t('cover.field_author'), series: t('cover.source_series'),
        number: t('cover.source_number'), edition: t('cover.source_edition'), tagline: t('cover.source_tagline'), description: t('cover.source_description'),
        publisher: t('cover.source_publisher'), price: t('cover.source_price'), category: t('cover.source_category'), isbn: t('cover.source_isbn'), custom: t('cover.text'),
    }[source] ?? t('cover.text');
}

/**
 * Each text layer's contrast with what is under it (CoverContrast), asked
 * for a moment after each save: the WCAG ratio, what it needs (3:1 for
 * large type, 4.5:1 otherwise), and a warning below it.
 */
function ContrastList({ bookId, savedAt, epoch, view }) {
    useI18n();
    const [rows, setRows] = useState(null);

    useEffect(() => {
        let live = true;
        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.get(`/books/${bookId}/cover/contrast`);
                if (live) setRows(data);
            } catch {
                // The list is advice; a failed check leaves the last one.
            }
        }, 900);

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [bookId, savedAt, epoch]);

    if (!rows) return null;

    const panels = view === 'wrap' ? ['front', 'back', 'spine'] : [view];
    const shown = panels.flatMap((panel) => (rows[panel] ?? []).map((row) => ({ ...row, panel })));

    if (shown.length === 0) return null;

    const failing = shown.filter((r) => !r.ok).length;

    return (
        <details className="rounded-tile border border-border-default bg-bg-surface px-3 py-2" data-a="cover:contrast" open={failing > 0}>
            <summary className="cursor-pointer text-[12px] font-semibold text-text-primary">
                {t('cover.contrast')}{' '}
                <span className={failing ? 'text-status-warning' : 'text-status-success'} data-a="cover:contrast:summary">
                    {failing ? t('cover.contrast_failing', { count: failing }) : t('cover.contrast_ok')}
                </span>
            </summary>
            <ul className="mt-1.5 space-y-1">
                {shown.map((row) => (
                    <li
                        key={`${row.panel}-${row.id}`}
                        data-a={`cover:contrast:${row.panel}:${row.id}`}
                        data-ok={row.ok ? 'yes' : 'no'}
                        className={`flex flex-wrap items-baseline gap-x-2 rounded-control px-2 py-1 text-[11.5px] ${row.ok ? 'text-text-body' : 'bg-status-warning-bg text-status-warning'}`}
                    >
                        <span className="font-semibold">
                            {panels.length > 1 && <span className="capitalize">{t('cover.panel_prefix', { panel: scopeName(row.panel) })}</span>}
                            {sourceName(row.source)}
                        </span>
                        <span className="tabular-nums">{row.ratio.toFixed(1)}:1</span>
                        <span className="text-text-secondary">
                            {row.ok
                                ? t('cover.contrast_pass', { needed: row.needed, size: row.large ? 'large' : 'small', points: row.points })
                                : t('cover.contrast_below', { needed: row.needed, size: row.large ? 'large' : 'small', points: row.points })}
                            {row.approximate ? t('cover.contrast_approximate') : ''}
                        </span>
                    </li>
                ))}
            </ul>
        </details>
    );
}

/**
 * The rendered cover, swapped only once the new one has loaded: keyed by
 * its URL, the old image vanished on every save and the page jumped.
 */
function SteadyImage({ src, ...rest }) {
    const [shown, setShown] = useState(src);

    useEffect(() => {
        if (src === shown) return undefined;

        let live = true;
        const next = new Image();
        next.onload = () => live && setShown(src);
        next.onerror = () => live && setShown(src);
        next.src = src;

        return () => {
            live = false;
        };
    }, [src, shown]);

    return <img src={shown} {...rest} />;
}

/**
 * The AI designer: three concepts, and a picture painted to a brief.
 *
 * Says what each step costs before it runs, and labels AI-made artwork as
 * such, because stores ask and the author should not have to remember.
 */
function AiDesigner({ data, brief, onBrief, thinking, onPropose, onApply, onPaint }) {
    useI18n();
    const { ai, concepts, palettes, cover } = data;

    if (!ai.concepts_available && !ai.artwork_available) {
        return (
            <section className="rounded-tile border border-dashed border-border-input bg-bg-surface p-3.5">
                <h3 className="text-[13px] font-semibold text-text-primary">{t('cover.ai_title')}</h3>
                <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                    {t('cover.ai_off')}
                </p>
            </section>
        );
    }

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">{t('cover.ai_title')}</h3>

                {ai.concepts_available && (
                    <button
                        data-a="cover:ai:concepts"
                        onClick={onPropose}
                        disabled={thinking !== null}
                        className="rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        {thinking === 'concepts'
                            ? t('cover.ai_designing')
                            : concepts
                              ? t('cover.ai_new_concepts', { credits: ai.concepts_cost })
                              : t('cover.ai_suggest_concepts', { credits: ai.concepts_cost })}
                    </button>
                )}
            </div>

            {/* The kill switch's sentence (App\Platform\Ai\AiControls): the
                buttons stay, and refuse without charging, but say so first. */}
            {(ai.concepts_paused || ai.artwork_paused) && (
                <p role="status" data-a="cover:ai:paused" className="mt-2 rounded-control bg-status-warning-bg px-2.5 py-1.5 text-[11.5px] text-status-warning">
                    {ai.concepts_paused ?? ai.artwork_paused}
                </p>
            )}

            {concepts?.items?.length > 0 && (
                <ul className="mt-3 space-y-2">
                    {concepts.items.map((concept, index) => {
                        const palette = palettes[concept.palette] ?? palettes[0];

                        return (
                            <li
                                key={index}
                                className="flex gap-3 rounded-control border border-border-default p-2.5"
                            >
                                <span
                                    aria-hidden="true"
                                    className="h-[58px] w-[38px] shrink-0 rounded-[2px]"
                                    style={{ background: `linear-gradient(170deg, ${palette.from}, ${palette.to})` }}
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="text-[12.5px] font-semibold text-text-primary">{concept.name}</p>
                                    <p className="mt-0.5 text-[11.5px] leading-snug text-text-secondary">
                                        {concept.rationale}
                                    </p>
                                    <p className="mt-1 text-[10.5px] uppercase tracking-[0.08em] text-text-secondary">
                                        {concept.layout} · {concept.font} · {palette.name}
                                    </p>
                                </div>
                                <button
                                    data-a={`cover:ai:apply:${index}`}
                                    onClick={() => onApply(index)}
                                    disabled={thinking !== null}
                                    className="self-start rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50"
                                >
                                    {t('cover.ai_use')}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            {ai.artwork_available && (
                <div className="mt-3">
                    <label className="block">
                        <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                            {t('cover.ai_brief_label')}
                        </span>
                        <textarea
                            rows={3}
                            value={brief}
                            data-a="cover:ai:brief"
                            onChange={(event) => onBrief(event.target.value)}
                            placeholder={t('cover.ai_brief_placeholder')}
                            className="w-full resize-y rounded-control border border-border-input px-2 py-1.5 text-[12px] leading-relaxed focus:border-brand-primary focus:ring-0"
                        />
                    </label>

                    <button
                        data-a="cover:ai:paint"
                        onClick={() => onPaint(brief)}
                        disabled={thinking !== null || brief.trim().length < 10}
                        className="mt-1.5 rounded-control border border-brand-primary px-3 py-1.5 text-[12px] font-semibold text-brand-primary transition hover:bg-brand-primary-subtle disabled:opacity-50"
                    >
                        {thinking === 'paint' ? t('cover.ai_painting') : t('cover.ai_paint', { credits: ai.artwork_cost })}
                    </button>
                </div>
            )}

            {cover.artwork_source?.type === 'ai' && (
                <p className="mt-2.5 text-[11px] leading-relaxed text-text-secondary">
                    {t('cover.ai_made', { model: cover.artwork_source.model })}
                </p>
            )}
        </section>
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
