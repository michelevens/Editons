import { useCallback, useEffect, useRef, useState } from 'react';
import Icon from './Icon';

/**
 * Cover Studio.
 *
 * The preview is drawn at 1600x2560 proportions — the size stores ask for —
 * so what the author approves is the shape of the file they will upload.
 */
export default function CoverPanel({ bookId, onNotify }) {
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
    const fileInput = useRef(null);
    const pending = useRef(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/design`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const save = (changes, { immediate = false } = {}) => {
        setData((current) => ({ ...current, cover: { ...current.cover, ...changes } }));

        clearTimeout(pending.current);

        const send = async () => {
            try {
                const response = await window.axios.patch(`/books/${bookId}/cover`, changes);
                setData(response.data);
            } catch (error) {
                onNotify?.(error.response?.data?.message ?? 'That change was not saved.');
                load();
            }
        };

        // Typing in a text field debounces; picking a colour saves at once.
        immediate ? send() : (pending.current = setTimeout(send, 500));
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

            setData(response.data);
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

    const removeArtwork = async () => {
        setBusy(true);

        try {
            const response = await window.axios.delete(`/books/${bookId}/cover/artwork`);
            setData(response.data);
        } finally {
            setBusy(false);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { cover, palettes } = data;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">Cover Studio</h2>
                <p className="text-[11.5px] text-text-secondary">
                    Stores want 1600 × 2560 pixels or larger.
                </p>
            </header>

            <div className="grid min-h-0 flex-1 gap-4 overflow-auto p-4 lg:grid-cols-[300px_1fr]">
                <section className="flex items-start justify-center rounded-tile border border-border-default bg-bg-subtle p-4">
                    <CoverArt cover={cover} palette={palettes[cover.palette] ?? palettes[0]} />
                </section>

                <div className="min-w-0 space-y-4">
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
                        <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">
                            Artwork
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
                            <p className="mt-2.5 rounded bg-status-info-bg px-2 py-1.5 text-[11px] text-status-info">
                                Your uploaded artwork is in use. Remove it to go back to a
                                colour scheme.
                            </p>
                        )}

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <label
                                htmlFor="cover-artwork"
                                className="cursor-pointer rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40"
                            >
                                <span className="inline-flex items-center gap-1.5">
                                    <Icon name="image" size={13} strokeWidth={2} />
                                    {busy ? 'Uploading…' : 'Upload artwork'}
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
                </div>
            </div>
        </div>
    );
}

/**
 * The cover, at store proportions.
 *
 * Text sizes are a share of the cover's width rather than fixed pixels, so
 * the preview looks the same whatever size it is drawn at — which is what
 * makes it a preview rather than an approximation.
 */
function CoverArt({ cover, palette }) {
    const width = 250;
    const height = Math.round(width * (2560 / 1600));

    const font = cover.font === 'serif'
        ? "'Source Serif 4', Georgia, serif"
        : 'Inter, system-ui, sans-serif';

    return (
        <div
            data-a="cover:preview"
            className="relative flex flex-col overflow-hidden rounded-[3px] shadow-[0_2px_14px_rgba(0,0,0,0.22)]"
            style={{
                width,
                height,
                fontFamily: font,
                color: cover.artwork ? '#ffffff' : palette.ink,
                background: cover.artwork
                    ? `linear-gradient(180deg, rgba(0,0,0,.18), rgba(0,0,0,.62)), url(${cover.artwork}) center/cover`
                    : `linear-gradient(170deg, ${palette.from}, ${palette.to})`,
                // A text shadow only over photography, where contrast cannot
                // be guaranteed. The palettes are chosen to pass without it.
                textShadow: cover.artwork ? '0 1px 10px rgba(0,0,0,.5)' : 'none',
            }}
        >
            <div className="px-[18px] pt-[34px]">
                <p
                    className="font-bold uppercase leading-[1.12] tracking-[0.01em]"
                    style={{ fontSize: width * 0.108 }}
                >
                    {cover.title || 'Untitled'}
                </p>

                {cover.subtitle && (
                    <p
                        className="mt-[10px] leading-snug opacity-90"
                        style={{ fontSize: width * 0.052 }}
                    >
                        {cover.subtitle}
                    </p>
                )}
            </div>

            {cover.author && (
                <p
                    className="mt-auto px-[18px] pb-[26px] font-semibold uppercase tracking-[0.07em]"
                    style={{ fontSize: width * 0.05 }}
                >
                    {cover.author}
                </p>
            )}
        </div>
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
