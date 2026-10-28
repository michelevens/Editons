import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import LayerEditor from './LayerEditor';
import Framing from './Framing';
import { backSurface, spineSurface } from './surfaces';

/**
 * Cover Studio's back, spine and full-wrap views.
 *
 * The print wrap is drawn for one print service, paper and page count
 * (GET /books/{id}/cover/wrap): its real geometry, the same render the PDF
 * uses (CoverWrap), the bleed, trim, folds and safe areas, and the barcode
 * box. The back and the spine are generated until the author customises
 * them; then each is the same layer editor as the front, on its own
 * surface (surfaces.jsx), saving through the panel's save().
 */
export default function WrapEditor({ bookId, data, view, save, savedAt, onUpload, images, onNotify }) {
    const [wrap, setWrap] = useState(null);
    const [error, setError] = useState(null);
    const [choice, setChoice] = useState({ service: null, paper: null, spine: '' });
    const [epoch, setEpoch] = useState(0);
    const [busy, setBusy] = useState(false);
    // Book text the back shows, typed here before the server has it.
    const [typed, setTyped] = useState({});
    const asked = useRef(0);

    const params = useMemo(() => {
        const p = {};

        if (choice.service) p.service = choice.service;
        if (choice.paper) p.paper = choice.paper;
        if (choice.spine !== '') p.spine = choice.spine;

        return p;
    }, [choice]);

    const load = useCallback(
        async (fresh = false) => {
            const mine = ++asked.current;

            try {
                const response = await window.axios.get(`/books/${bookId}/cover/wrap`, { params });

                if (mine !== asked.current) return;

                setWrap(response.data);
                setError(null);

                if (fresh) setEpoch((e) => e + 1);
            } catch (e) {
                if (mine !== asked.current) return;

                setError(e.response?.data?.message ?? 'The print cover could not be drawn.');
                setWrap((w) => (w ? { ...w, services: e.response?.data?.services ?? w.services } : null));
            }
        },
        [bookId, params],
    );

    useEffect(() => {
        load(true);
    }, [load]);

    // After each save, once the server has it: new warnings and a new picture.
    useEffect(() => {
        if (!savedAt) return undefined;

        const timer = setTimeout(() => load(false), 300);

        return () => clearTimeout(timer);
    }, [savedAt, load]);

    const texts = useMemo(() => ({ ...(wrap?.texts ?? {}), ...typed }), [wrap, typed]);

    const onText = useCallback(
        (field, value) => {
            setTyped((t) => ({ ...t, [field]: field === 'description' ? value : String(value ?? '').trim() }));
            save({ [field]: value });
        },
        [save],
    );

    const run = async (request, fresh = true) => {
        setBusy(true);

        try {
            const response = await request();

            if (response?.data?.layers) {
                setWrap(response.data);
                if (fresh) setEpoch((e) => e + 1);
            } else {
                await load(fresh);
            }
        } catch (e) {
            onNotify?.(e.response?.data?.message ?? 'That did not work.');
        } finally {
            setBusy(false);
        }
    };

    const customise = (panel) => run(() => window.axios.post(`/books/${bookId}/cover/${panel}-layers`, params));

    const reset = (panel) => {
        const what = panel === 'back' ? 'the generated back (title, description, publisher)' : 'the generated spine';

        if (!window.confirm(`Go back to ${what}? Your ${panel} layers will be removed.`)) return;

        run(() => window.axios.delete(`/books/${bookId}/cover/${panel}-layers`, { params }));
    };

    const setDirection = (direction) =>
        run(async () => {
            await window.axios.patch(`/books/${bookId}/cover`, { spine_direction: direction });

            return null;
        }, false);

    const uploadBack = (file) =>
        file &&
        run(async () => {
            const form = new FormData();
            form.append('back', file);
            await window.axios.post(`/books/${bookId}/print-kit/back`, form);

            return null;
        }, false);

    const removeBack = () =>
        run(async () => {
            await window.axios.delete(`/books/${bookId}/print-kit/back`);

            return null;
        }, false);

    const setBackFraming = (framing) => {
        setWrap((w) => ({ ...w, back_framing: framing.fit === 'fill' ? framing : null }));
        save({ back_framing: framing }, { immediate: true });
    };

    if (!wrap) {
        return (
            <p className="p-6 text-center text-[12px] text-text-secondary" data-a="cover:wrap-loading">
                {error ?? 'Drawing the print cover…'}
            </p>
        );
    }

    const g = wrap.geometry;
    // The spine view says why a thin spine carries no type in a note of
    // its own, so not twice.
    const warnings = wrap.warnings.filter((w) => view === 'wrap' || (w.panel === view && !(view === 'spine' && w.kind === 'thin')));
    const shadows = data.editor.shadows;

    return (
        <div className="space-y-3" data-a={`cover:wrap-view:${view}`}>
            <ServicePicker wrap={wrap} choice={choice} onChoice={setChoice} error={error} />

            {warnings.length > 0 && (
                <ul className="space-y-1" data-a="cover:warnings">
                    {warnings.map((w, i) => (
                        <li key={i} data-kind={w.kind} className="rounded-control bg-status-warning-bg px-2.5 py-1.5 text-[11.5px] leading-snug text-status-warning">
                            {view === 'wrap' && <span className="font-semibold capitalize">{w.panel}: </span>}
                            {w.message}
                        </li>
                    ))}
                </ul>
            )}

            {view === 'back' && (
                <>
                    {wrap.layers.back ? (
                        <LayerEditor
                            key={`back-${epoch}`}
                            data={data}
                            surface={{ ...backSurface(wrap), shadows }}
                            doc={wrap.layers.back}
                            texts={texts}
                            images={images}
                            onLayers={(doc) => save({ back_layers: doc })}
                            onText={onText}
                            onReset={() => reset('back')}
                            resetLabel="Reset to generated back"
                            onUpload={onUpload}
                            onNotify={onNotify}
                        />
                    ) : (
                        <Generated
                            title="The back is generated"
                            body="Your title, the description set at the largest size that fits, the publisher at the foot, and a white box for the barcode. Customise it to place each piece yourself, add pictures and your own text; from then on nothing re-fits as you type."
                            action="Customise back"
                            data-a="cover:customise-back"
                            busy={busy}
                            onAction={() => customise('back')}
                        >
                            <PanelPicture src={wrap.preview} sheet={wrap.sheet} box={{ x: 0, y: 0, width: wrap.back.width, height: wrap.back.height }} label="The back cover as it prints" />
                        </Generated>
                    )}

                    <BackArtwork wrap={wrap} busy={busy} onUpload={uploadBack} onRemove={removeBack} onFraming={setBackFraming} />
                </>
            )}

            {view === 'spine' && (
                <>
                    <Direction spine={wrap.spine} onChange={setDirection} busy={busy} />

                    {!wrap.spine.text && (
                        <p role="note" data-a="cover:spine-reason" className="rounded-control bg-status-info-bg px-2.5 py-1.5 text-[11.5px] leading-snug text-status-info">
                            {wrap.spine.reason}
                        </p>
                    )}

                    {wrap.layers.spine ? (
                        <LayerEditor
                            key={`spine-${epoch}-${wrap.spine.direction}`}
                            data={data}
                            surface={{ ...spineSurface(wrap), shadows }}
                            doc={wrap.layers.spine}
                            texts={texts}
                            images={images}
                            onLayers={(doc) => save({ spine_layers: doc })}
                            onText={onText}
                            onReset={() => reset('spine')}
                            resetLabel="Reset to generated spine"
                            onUpload={onUpload}
                            onNotify={onNotify}
                        />
                    ) : (
                        <Generated
                            title="The spine is generated"
                            body="The title, then the series, author and publisher, as large as the spine allows. Customise it to choose what it says, its size and typeface, and to add a logo."
                            action="Customise spine"
                            data-a="cover:customise-spine"
                            busy={busy}
                            onAction={() => customise('spine')}
                        >
                            <PanelPicture
                                src={wrap.preview}
                                sheet={wrap.sheet}
                                box={{ x: wrap.spine.x - wrap.edge, y: 0, width: wrap.spine.height + 2 * wrap.edge, height: wrap.sheet.height }}
                                label="The spine as it prints"
                                className="h-[300px] w-auto"
                            />
                        </Generated>
                    )}
                </>
            )}

            {view === 'wrap' && <FullWrap wrap={wrap} />}
        </div>
    );
}

function ServicePicker({ wrap, choice, onChoice, error }) {
    const g = wrap.geometry;
    const service = wrap.services.find((s) => s.service === (choice.service ?? g.service)) ?? wrap.services[0];
    const pages = wrap.pages;

    return (
        <div className="rounded-tile border border-border-default bg-bg-surface p-2.5">
            <div className="flex flex-wrap items-end gap-2">
                <label className="block">
                    <span className="mb-0.5 block text-[11px] font-medium text-text-secondary">Print service</span>
                    <select
                        value={service.service}
                        data-a="cover:wrap-service"
                        onChange={(event) => onChoice({ service: event.target.value, paper: null, spine: '' })}
                        className="rounded-control border border-border-input px-2 py-1 text-[12px]"
                    >
                        {wrap.services.map((s) => (
                            <option key={s.service} value={s.service}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </label>
                {service.papers.length > 1 && (
                    <label className="block">
                        <span className="mb-0.5 block text-[11px] font-medium text-text-secondary">Paper</span>
                        <select
                            value={choice.paper ?? g.paper}
                            data-a="cover:wrap-paper"
                            onChange={(event) => onChoice({ ...choice, service: service.service, paper: event.target.value })}
                            className="rounded-control border border-border-input px-2 py-1 text-[12px]"
                        >
                            {service.papers.map((p) => (
                                <option key={p} value={p}>
                                    {p === 'white' ? 'White' : 'Cream'}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {service.spine_entered && (
                    <label className="block">
                        <span className="mb-0.5 block text-[11px] font-medium text-text-secondary">Spine width (in), from its template</span>
                        <input
                            defaultValue={choice.spine}
                            inputMode="decimal"
                            data-a="cover:wrap-spine"
                            onBlur={(event) => onChoice({ ...choice, service: service.service, spine: event.target.value })}
                            className="w-[90px] rounded-control border border-border-input px-2 py-1 text-[12px]"
                        />
                    </label>
                )}
            </div>
            <p className="mt-1.5 text-[11px] leading-snug text-text-secondary" data-a="cover:wrap-size">
                {g.width.toFixed(3)} × {g.height.toFixed(3)} in · spine {g.spine.toFixed(3)} in · bleed {g.bleed} in ·{' '}
                {pages.exact ? `${g.pages} pages` : `about ${g.pages} pages (estimated)`} · {g.label}
            </p>
            {error && <p className="mt-1 text-[11.5px] text-status-danger">{error}</p>}
        </div>
    );
}

function Generated({ title, body, action, busy, onAction, children, ...rest }) {
    return (
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,320px)_1fr]">
            <div className="flex justify-center rounded-tile border border-border-default bg-bg-subtle p-3">{children}</div>
            <section className="rounded-tile border border-brand-primary/30 bg-brand-primary-subtle p-3.5">
                <h3 className="text-[13px] font-semibold text-text-primary">{title}</h3>
                <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">{body}</p>
                <button
                    type="button"
                    data-a={rest['data-a']}
                    onClick={onAction}
                    disabled={busy}
                    className="mt-2.5 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {action}
                </button>
            </section>
        </div>
    );
}

/** One region of the wrap's render, cut out with a view box. */
function PanelPicture({ src, sheet, box, label, className = 'h-auto w-full max-w-[300px]' }) {
    return (
        <svg viewBox={`${box.x} ${box.y} ${box.width} ${box.height}`} className={className} role="img" aria-label={label}>
            <image href={src} x={0} y={0} width={sheet.width} height={sheet.height} preserveAspectRatio="none" />
        </svg>
    );
}

function Direction({ spine, onChange, busy }) {
    const names = { ttb: 'Top to bottom', btt: 'Bottom to top' };
    const notes = { ttb: 'US and UK convention', btt: 'French convention' };

    return (
        <fieldset className="rounded-tile border border-border-default bg-bg-surface p-2.5">
            <legend className="px-1 text-[12px] font-semibold text-text-primary">Spine reads</legend>
            <div role="radiogroup" aria-label="Spine direction" className="flex flex-wrap gap-1.5">
                {['ttb', 'btt'].map((d) => (
                    <button
                        key={d}
                        type="button"
                        role="radio"
                        aria-checked={spine.direction === d}
                        disabled={busy}
                        data-a={`cover:spine-direction:${d}`}
                        onClick={() => onChange(d)}
                        className={`min-h-[36px] rounded-control border px-3 text-left text-[12px] ${
                            spine.direction === d ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body hover:border-brand-primary/40'
                        }`}
                    >
                        <span className="font-semibold">{names[d]}</span>
                        <span className="block text-[10.5px] text-text-secondary">{notes[d]}</span>
                    </button>
                ))}
            </div>
            <p className="mt-1.5 text-[11px] leading-snug text-text-secondary" data-a="cover:spine-suggested">
                {spine.chosen
                    ? `Chosen for this book. Books in its language usually read ${names[spine.suggested].toLowerCase()}.`
                    : `Not chosen yet: the spine reads top to bottom, as it always has. Books in this book's language usually read ${names[spine.suggested].toLowerCase()}; Customise spine starts from that.`}
            </p>
        </fieldset>
    );
}

function BackArtwork({ wrap, busy, onUpload, onRemove, onFraming }) {
    const fill = Boolean(wrap.back_framing);
    const framing = wrap.back_framing ?? { x: 0.5, y: 0.5, zoom: 1 };

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-3" data-a="cover:back-artwork">
            <h3 className="text-[12.5px] font-semibold text-text-primary">Finished back image</h3>
            <p className="mt-0.5 text-[11px] leading-snug text-text-secondary">
                {wrap.back_artwork
                    ? 'Your uploaded back is printed; any back layers sit on top of it. Leave the barcode area clear in the image.'
                    : 'Optional: upload a finished back (PNG or JPEG) to print instead of the generated one.'}
            </p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <label className="inline-flex cursor-pointer items-center rounded-control border border-border-input px-2.5 py-1.5 text-[11.5px] font-medium text-text-body focus-within:ring-2 focus-within:ring-brand-primary hover:border-brand-primary/40">
                    {wrap.back_artwork ? 'Replace back image' : 'Upload back image'}
                    <input type="file" accept="image/jpeg,image/png" className="sr-only" disabled={busy} data-a="cover:back-upload" onChange={(event) => onUpload(event.target.files?.[0])} />
                </label>
                {wrap.back_artwork && (
                    <button type="button" onClick={onRemove} disabled={busy} data-a="cover:back-remove" className="text-[11.5px] font-semibold text-brand-primary hover:underline">
                        Remove it
                    </button>
                )}
            </div>

            {wrap.back_artwork && (
                <div className="mt-3 space-y-2">
                    <div role="radiogroup" aria-label="Fit the back image" className="flex gap-1.5">
                        {[['whole', 'Whole image'], ['fill', 'Fill the back']].map(([key, name]) => (
                            <button
                                key={key}
                                type="button"
                                role="radio"
                                aria-checked={(key === 'fill') === fill}
                                data-a={`cover:back-fit:${key}`}
                                onClick={() => onFraming(key === 'fill' ? { fit: 'fill', ...framing } : { fit: 'whole' })}
                                className={`min-h-[32px] rounded-control border px-2.5 text-[12px] ${(key === 'fill') === fill ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body'}`}
                            >
                                {name}
                            </button>
                        ))}
                    </div>
                    {fill && wrap.back_artwork_image && (
                        <Framing
                            image={wrap.back_artwork_image}
                            framing={framing}
                            frameWidth={wrap.back.width}
                            frameHeight={wrap.back.height}
                            prefix="cover:back-framing"
                            label="Back image position"
                            onChange={(f) => onFraming({ fit: 'fill', ...f })}
                        />
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * The whole sheet as the PDF draws it, with what a printer checks drawn
 * over it: the bleed (shaded, trimmed off), the trim, the spine folds, the
 * safe areas, the spine's margins and the barcode box.
 */
function FullWrap({ wrap }) {
    const { sheet, back, spine, geometry: g } = wrap;
    const bleed = wrap.bleed;
    const edge = wrap.edge;
    const stroke = sheet.width / 900;
    const frontX = spine.x + spine.height;
    const label = (x, text) => (
        <text x={x} y={bleed + edge * 3} fontSize={sheet.height / 34} textAnchor="middle" fill="#0e7490" stroke="#ffffff" strokeWidth={stroke * 1.4} paintOrder="stroke">
            {text}
        </text>
    );

    return (
        <figure className="space-y-2">
            <svg viewBox={`0 0 ${sheet.width} ${sheet.height}`} className="h-auto w-full rounded-control border border-border-default" role="img" aria-label="The full print cover: back, spine and front" data-a="cover:wrap">
                <image href={wrap.preview} x={0} y={0} width={sheet.width} height={sheet.height} preserveAspectRatio="none" data-a="cover:wrap-image" />

                {/* Bleed: printed, then trimmed off. */}
                <path
                    d={`M0 0H${sheet.width}V${sheet.height}H0Z M${bleed} ${bleed}V${sheet.height - bleed}H${sheet.width - bleed}V${bleed}Z`}
                    fill="rgba(239,68,68,0.18)"
                    fillRule="evenodd"
                    data-a="cover:wrap-bleed"
                />
                <rect x={bleed} y={bleed} width={sheet.width - 2 * bleed} height={sheet.height - 2 * bleed} fill="none" stroke="#ef4444" strokeWidth={stroke} data-a="cover:wrap-trim" />

                {/* Spine folds. */}
                {[spine.x, frontX].map((x) => (
                    <line key={x} x1={x} x2={x} y1={0} y2={sheet.height} stroke="#7c3aed" strokeWidth={stroke} strokeDasharray={`${stroke * 6} ${stroke * 4}`} data-a="cover:wrap-fold" />
                ))}

                {/* Safe areas: the back, the front, and the spine within its margins. */}
                <g fill="none" stroke="#22d3ee" strokeWidth={stroke} strokeDasharray={`${stroke * 3} ${stroke * 3}`} data-a="cover:wrap-safe">
                    <rect x={back.safe.x} y={back.safe.y} width={back.safe.width} height={back.safe.height} />
                    <rect x={frontX + edge} y={bleed + edge} width={g.trim[0] * wrap.dpi - 2 * edge} height={sheet.height - 2 * (bleed + edge)} />
                    {spine.safe.height > 0 && (
                        <rect x={spine.x + spine.margin} y={spine.safe.x} width={spine.safe.height} height={spine.safe.width} />
                    )}
                </g>

                {back.barcode && (
                    <g data-a="cover:wrap-barcode">
                        <rect x={back.barcode.x} y={back.barcode.y} width={back.barcode.width} height={back.barcode.height} fill="none" stroke="#ef4444" strokeWidth={stroke * 1.5} />
                        <text x={back.barcode.x + back.barcode.width / 2} y={back.barcode.y + back.barcode.height / 2} fontSize={back.barcode.height / 5} textAnchor="middle" dominantBaseline="middle" fill="#b91c1c">
                            Barcode
                        </text>
                    </g>
                )}

                {label(back.width / 2, 'Back')}
                {label(frontX + (sheet.width - frontX) / 2, 'Front')}
            </svg>
            <figcaption className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-text-secondary">
                <span><span className="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-400/40 align-middle" />Bleed (trimmed off)</span>
                <span><span className="mr-1 inline-block h-0.5 w-3 bg-red-500 align-middle" />Trim</span>
                <span><span className="mr-1 inline-block h-0.5 w-3 bg-violet-600 align-middle" />Spine folds</span>
                <span><span className="mr-1 inline-block h-0.5 w-3 bg-cyan-400 align-middle" />Safe area</span>
                <span>The picture is the print file's own render, at {g.label}'s size for this page count.</span>
            </figcaption>
        </figure>
    );
}
