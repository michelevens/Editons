import { useCallback, useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import LayerEditor from './cover/LayerEditor';

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
 */

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
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
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
            }
        } catch (error) {
            notify.current?.(
                error.response?.data?.message ?? 'That change was not saved.',
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

    const customise = async () => {
        setBusy(true);

        try {
            clearTimeout(pending.current);
            await send();
            const response = await window.axios.post(`/books/${bookId}/cover/layers`);
            setData(response.data);
            setEpoch((e) => e + 1);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'The text could not be made editable.');
        } finally {
            setBusy(false);
        }
    };

    const resetToLayout = async () => {
        if (!window.confirm('Go back to the layout? Your text boxes, their positions and styles will be replaced by the layout.')) {
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
                    ?? 'That image could not be used. Try a JPEG, PNG or WebP under 12 MB.',
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
                    ?? 'That did not work. Nothing was charged.',
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
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { cover, palettes, layouts } = data;
    const layered = Boolean(cover.layers);

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">Cover Studio</h2>
                <p className="text-[11.5px] text-text-secondary">
                    Stores want 1600 × 2560 pixels or larger.
                </p>
            </header>

            <div className="min-h-0 flex-1 space-y-4 p-4">
                {layered ? (
                    <LayerEditor
                        key={epoch}
                        data={data}
                        onLayers={onLayers}
                        onText={onText}
                        onReset={resetToLayout}
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[300px_1fr]">
                        <section className="flex flex-col items-center gap-3 rounded-tile border border-border-default bg-bg-subtle p-4">
                            <SteadyImage
                                src={cover.image}
                                alt={`Cover of ${cover.title ?? 'the book'}`}
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
                                Download cover (1600 × 2560 JPEG)
                            </a>
                        </section>

                        <div className="min-w-0 space-y-4">
                            <section className="rounded-tile border border-brand-primary/30 bg-brand-primary-subtle p-3.5">
                                <h3 className="text-[13px] font-semibold text-text-primary">Place the text yourself</h3>
                                <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                                    The layout fits the title for you, so it moves as the text changes. Customise
                                    the text to drag each line where you want it, and choose its typeface, size,
                                    colour and spacing. It stays where you put it.
                                </p>
                                <button
                                    type="button"
                                    data-a="cover:customise"
                                    onClick={customise}
                                    disabled={busy}
                                    className="mt-2.5 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                                >
                                    Customise text
                                </button>
                            </section>

                            <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                                <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">Text</h3>

                                <Field label="Title">
                                    <input
                                        value={cover.title ?? ''}
                                        data-a="cover:title"
                                        onChange={(event) => save({ title: event.target.value })}
                                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                    />
                                </Field>

                                <Field label="Subtitle">
                                    <input
                                        value={cover.subtitle ?? ''}
                                        data-a="cover:subtitle"
                                        onChange={(event) => save({ subtitle: event.target.value })}
                                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                    />
                                </Field>

                                <Field label="Author">
                                    <input
                                        value={cover.author ?? ''}
                                        data-a="cover:author"
                                        onChange={(event) => save({ author: event.target.value })}
                                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                    />
                                </Field>

                                <Field label="Typeface">
                                    <select
                                        value={cover.font}
                                        data-a="cover:font"
                                        onChange={(event) => save({ font: event.target.value }, { immediate: true })}
                                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                    >
                                        <option value="serif">Serif</option>
                                        <option value="sans">Sans</option>
                                    </select>
                                </Field>
                            </section>

                            <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                                <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">Layout</h3>

                                <div role="group" aria-label="Layout" className="flex flex-wrap gap-2">
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
                            {layered ? 'Background' : 'Artwork'}
                        </h3>

                        <div
                            role="group"
                            aria-label="Colour scheme"
                            className="flex flex-wrap gap-2"
                        >
                            {palettes.map((palette, index) => (
                                <button
                                    key={palette.name}
                                    aria-pressed={!cover.artwork && cover.palette === index}
                                    data-a={`cover:palette:${index}`}
                                    title={palette.name}
                                    onClick={() => save({ palette: index }, { immediate: true })}
                                    className={`h-[44px] w-[44px] rounded-control border-2 transition ${
                                        !cover.artwork && cover.palette === index
                                            ? 'border-brand-primary'
                                            : 'border-transparent hover:border-border-input'
                                    }`}
                                    style={{
                                        background: `linear-gradient(170deg, ${palette.from}, ${palette.to})`,
                                    }}
                                >
                                    <span className="sr-only">{palette.name}</span>
                                </button>
                            ))}
                        </div>

                        {cover.artwork && (
                            <>
                                <p className="mt-2.5 rounded bg-status-info-bg px-2 py-1.5 text-[11px] text-status-info">
                                    Your uploaded artwork is in use. Remove it to go back to a
                                    colour scheme.
                                </p>

                                {/* Darkens the picture under the type, so the title
                                    reads on any photograph. Layers keep the layout's
                                    treatment: classic and bold darken, band and a
                                    finished cover do not. */}
                                {(!layered || ['classic', 'bold'].includes(cover.layout)) && (
                                    <label className="mt-3 block">
                                        <span className="mb-1 flex justify-between text-[11.5px] font-medium text-text-secondary">
                                            <span>Darken behind the text</span>
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
                                    {busy ? 'Working…' : 'Upload artwork'}
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
                                    Remove artwork
                                </button>
                            )}
                        </div>

                        <p className="mt-2 text-[11px] text-text-secondary">
                            JPEG, PNG or WebP, up to 12 MB.
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
        </div>
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
    const { ai, concepts, palettes, cover } = data;

    if (!ai.concepts_available && !ai.artwork_available) {
        return (
            <section className="rounded-tile border border-dashed border-border-input bg-bg-surface p-3.5">
                <h3 className="text-[13px] font-semibold text-text-primary">AI cover designer</h3>
                <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                    Not switched on yet. When it is, it will propose three designs for this
                    book and paint artwork for the one you choose.
                </p>
            </section>
        );
    }

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">AI cover designer</h3>

                {ai.concepts_available && (
                    <button
                        data-a="cover:ai:concepts"
                        onClick={onPropose}
                        disabled={thinking !== null}
                        className="rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        {thinking === 'concepts'
                            ? 'Designing…'
                            : `${concepts ? 'New' : 'Suggest'} concepts · ${ai.concepts_cost} credits`}
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
                                    Use this
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
                            Picture to paint. No lettering: the title is set in real type.
                        </span>
                        <textarea
                            rows={3}
                            value={brief}
                            data-a="cover:ai:brief"
                            onChange={(event) => onBrief(event.target.value)}
                            placeholder="A calm harbour at dawn, seen from above, muted blues and warm light…"
                            className="w-full resize-y rounded-control border border-border-input px-2 py-1.5 text-[12px] leading-relaxed focus:border-brand-primary focus:ring-0"
                        />
                    </label>

                    <button
                        data-a="cover:ai:paint"
                        onClick={() => onPaint(brief)}
                        disabled={thinking !== null || brief.trim().length < 10}
                        className="mt-1.5 rounded-control border border-brand-primary px-3 py-1.5 text-[12px] font-semibold text-brand-primary transition hover:bg-brand-primary-subtle disabled:opacity-50"
                    >
                        {thinking === 'paint' ? 'Painting… (up to a minute)' : `Paint artwork · ${ai.artwork_cost} credits`}
                    </button>
                </div>
            )}

            {cover.artwork_source?.type === 'ai' && (
                <p className="mt-2.5 text-[11px] leading-relaxed text-text-secondary">
                    The current artwork was made with AI ({cover.artwork_source.model}). Some
                    stores ask you to disclose that, and AI images have limited copyright
                    protection.
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
