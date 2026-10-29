import { useEffect, useState } from 'react';
import Framing from './Framing';

/**
 * A cover background of the author's own (CoverBackground on the server):
 * a solid colour, a linear gradient at an angle, or a radial one around a
 * centre, with 2 to 4 colour stops; across the whole wrap also one picture,
 * framed around a focal point and zoomed. The six palettes remain as quick
 * picks. The swatch is CSS's own gradient, which the server draws the same
 * way (sRGB, the CSS gradient line), so what is picked here is what prints.
 *
 * `value` is the scope's background or null; `onChange(next)` saves it
 * (null removes it). `scopes` lists the scopes to choose between; one alone
 * shows no picker.
 */

export const SCOPE_NAMES = { front: 'Front', back: 'Back', spine: 'Spine', wrap: 'Whole wrap' };

/** The CSS for a background, for swatches and previews. */
export function backgroundCss(bg) {
    if (!bg) return undefined;

    const stops = (bg.stops ?? []).map((s) => `${s.color} ${Math.round(s.at * 1000) / 10}%`).join(', ');

    switch (bg.kind) {
        case 'solid':
            return bg.color;
        case 'linear':
            return `linear-gradient(${bg.angle}deg, ${stops})`;
        case 'radial':
            return `radial-gradient(circle farthest-corner at ${bg.x * 100}% ${bg.y * 100}%, ${stops})`;
        default:
            return undefined;
    }
}

const KINDS = [
    ['solid', 'Colour'],
    ['linear', 'Linear'],
    ['radial', 'Radial'],
];

const input = 'rounded-control border border-border-input bg-bg-surface px-2 py-1 text-[12px] focus:border-brand-primary focus:ring-0';

function fromPalette(palette) {
    return { kind: 'linear', angle: 170, stops: [{ color: palette.from, at: 0 }, { color: palette.to, at: 1 }] };
}

/** A sensible starting point for a kind, keeping the colours there are. */
function convert(bg, kind) {
    const colors = bg?.kind === 'solid' ? [bg.color, '#ffffff'] : (bg?.stops ?? []).map((s) => s.color);
    const first = colors[0] ?? '#1e3a5f';
    const stops = colors.length >= 2 ? bg.stops : [{ color: first, at: 0 }, { color: colors[1] ?? '#4a6fa5', at: 1 }];

    switch (kind) {
        case 'solid':
            return { kind: 'solid', color: first };
        case 'linear':
            return { kind: 'linear', angle: bg?.angle ?? 180, stops };
        case 'radial':
            return { kind: 'radial', x: bg?.x ?? 0.5, y: bg?.y ?? 0.5, stops };
        default:
            return null;
    }
}

export default function BackgroundEditor({ scope, scopes = null, onScope, value, onChange, palettes = [], onPalette, paletteIndex = null, images = [], onUpload, sheet = null, prefix = 'cover:bg' }) {
    // Typed colours land on blur or a valid hex; sliders save as they move.
    const [draft, setDraft] = useState(value);
    useEffect(() => setDraft(value), [value]);

    const set = (next) => {
        setDraft(next);
        onChange(next);
    };

    const setStop = (i, patch) => set({ ...draft, stops: draft.stops.map((s, j) => (j === i ? { ...s, ...patch } : s)) });

    const kinds = scope === 'wrap' ? [...KINDS, ['image', 'Picture']] : KINDS;

    return (
        <div className="space-y-2.5" data-a={prefix}>
            {scopes && scopes.length > 1 && (
                <div role="radiogroup" aria-label="Background of" className="flex flex-wrap gap-1">
                    {scopes.map((s) => (
                        <button
                            key={s}
                            type="button"
                            role="radio"
                            aria-checked={scope === s}
                            data-a={`${prefix}:scope:${s}`}
                            onClick={() => onScope(s)}
                            className={`min-h-[32px] rounded-control border px-2.5 text-[12px] ${scope === s ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body hover:border-brand-primary/40'}`}
                        >
                            {SCOPE_NAMES[s]}
                        </button>
                    ))}
                </div>
            )}

            {palettes.length > 0 && (
                <div role="group" aria-label="Quick picks" className="flex flex-wrap gap-2">
                    {palettes.map((palette, index) => {
                        const pressed = scope === 'front' && !draft && paletteIndex === index;

                        return (
                            <button
                                key={palette.name}
                                type="button"
                                aria-pressed={pressed}
                                data-a={scope === 'front' ? `cover:palette:${index}` : `${prefix}:preset:${index}`}
                                title={palette.name}
                                onClick={() => (scope === 'front' && onPalette ? onPalette(index) : set(fromPalette(palette)))}
                                className={`h-[40px] w-[40px] rounded-control border-2 transition ${pressed ? 'border-brand-primary' : 'border-transparent hover:border-border-input'}`}
                                style={{ background: `linear-gradient(170deg, ${palette.from}, ${palette.to})` }}
                            >
                                <span className="sr-only">{palette.name}</span>
                            </button>
                        );
                    })}
                </div>
            )}

            <div role="radiogroup" aria-label="Background kind" className="flex flex-wrap gap-1">
                {kinds.map(([key, name]) => (
                    <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={draft?.kind === key}
                        data-a={`${prefix}:kind:${key}`}
                        onClick={() => {
                            if (key === 'image') {
                                if (images[0]) set({ kind: 'image', image: images[0].id, x: 0.5, y: 0.5, zoom: 1 });
                                else setDraft({ kind: 'image' });
                            } else {
                                set(convert(draft, key));
                            }
                        }}
                        className={`min-h-[30px] rounded-control border px-2.5 text-[11.5px] ${draft?.kind === key ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body'}`}
                    >
                        {name}
                    </button>
                ))}
                {draft && (
                    <button type="button" data-a={`${prefix}:remove`} onClick={() => set(null)} className="ml-auto px-2 text-[11.5px] font-semibold text-brand-primary hover:underline">
                        {scope === 'front' ? 'Use a palette' : 'Remove'}
                    </button>
                )}
            </div>

            {draft && draft.kind !== 'image' && (
                <div className="flex items-start gap-3">
                    <span
                        aria-hidden="true"
                        data-a={`${prefix}:swatch`}
                        className={`shrink-0 rounded-[3px] border border-border-default ${scope === 'wrap' ? 'h-[54px] w-[110px]' : scope === 'spine' ? 'h-[80px] w-[18px]' : 'h-[80px] w-[50px]'}`}
                        style={{ background: backgroundCss(draft) }}
                    />
                    <div className="min-w-0 flex-1 space-y-1.5">
                        {draft.kind === 'solid' ? (
                            <ColorField label="Colour" value={draft.color} onChange={(color) => set({ ...draft, color })} dataA={`${prefix}:color`} />
                        ) : (
                            <>
                                {draft.stops.map((stop, i) => (
                                    <div key={i} className="flex flex-wrap items-center gap-1.5">
                                        <ColorField label={`Colour ${i + 1}`} value={stop.color} onChange={(color) => setStop(i, { color })} dataA={`${prefix}:stop:${i}`} />
                                        <label className="flex min-w-0 flex-1 items-center gap-1 text-[11px] text-text-secondary">
                                            <span className="sr-only">Position of colour {i + 1}</span>
                                            <input
                                                type="range"
                                                min={0}
                                                max={100}
                                                value={Math.round(stop.at * 100)}
                                                data-a={`${prefix}:stop:${i}:at`}
                                                onChange={(e) => {
                                                    const at = Number(e.target.value) / 100;
                                                    const lo = draft.stops[i - 1]?.at ?? 0;
                                                    const hi = draft.stops[i + 1]?.at ?? 1;
                                                    setStop(i, { at: Math.min(hi, Math.max(lo, at)) });
                                                }}
                                                className="min-w-[60px] flex-1 accent-brand-primary"
                                            />
                                            <span className="w-[30px] text-right tabular-nums">{Math.round(stop.at * 100)}%</span>
                                        </label>
                                        {draft.stops.length > 2 && (
                                            <button type="button" aria-label={`Remove colour ${i + 1}`} onClick={() => set({ ...draft, stops: draft.stops.filter((_, j) => j !== i) })} className="px-1 text-[13px] text-text-secondary hover:text-status-danger">
                                                ×
                                            </button>
                                        )}
                                    </div>
                                ))}
                                {draft.stops.length < 4 && (
                                    <button
                                        type="button"
                                        data-a={`${prefix}:add-stop`}
                                        onClick={() => {
                                            const last = draft.stops[draft.stops.length - 1];
                                            const before = draft.stops[draft.stops.length - 2];
                                            const stops = [...draft.stops.slice(0, -1), { color: before.color, at: (before.at + last.at) / 2 }, last];
                                            set({ ...draft, stops });
                                        }}
                                        className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                    >
                                        + Add a colour
                                    </button>
                                )}
                                {draft.kind === 'linear' ? (
                                    <Slider label="Angle" unit="°" min={0} max={360} value={draft.angle} onChange={(angle) => set({ ...draft, angle })} dataA={`${prefix}:angle`} />
                                ) : (
                                    <>
                                        <Slider label="Centre across" unit="%" min={0} max={100} value={Math.round(draft.x * 100)} onChange={(v) => set({ ...draft, x: v / 100 })} dataA={`${prefix}:x`} />
                                        <Slider label="Centre down" unit="%" min={0} max={100} value={Math.round(draft.y * 100)} onChange={(v) => set({ ...draft, y: v / 100 })} dataA={`${prefix}:y`} />
                                    </>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}

            {draft?.kind === 'image' && (
                <WrapPicture draft={draft} images={images} onUpload={onUpload} sheet={sheet} onChange={set} prefix={prefix} />
            )}
        </div>
    );
}

function WrapPicture({ draft, images, onUpload, sheet, onChange, prefix }) {
    const image = images.find((i) => i.id === draft.image);

    return (
        <div className="space-y-2" data-a={`${prefix}:picture`}>
            <p className="text-[11px] leading-snug text-text-secondary">
                One picture across the back, spine and front, bleed included. Print wants 300 dpi over the whole sheet
                {sheet ? ` (at least ${Math.ceil(sheet.width_in * 300)} × ${Math.ceil(sheet.height_in * 300)} pixels here)` : ''}.
            </p>
            <div className="flex flex-wrap items-center gap-2">
                {images.length > 0 && (
                    <select value={draft.image ?? ''} data-a={`${prefix}:picture:pick`} onChange={(e) => onChange({ kind: 'image', image: e.target.value, x: draft.x ?? 0.5, y: draft.y ?? 0.5, zoom: draft.zoom ?? 1 })} className={input}>
                        {!draft.image && <option value="">Choose a picture</option>}
                        {images.map((i) => (
                            <option key={i.id} value={i.id}>
                                {i.name} ({i.width} × {i.height})
                            </option>
                        ))}
                    </select>
                )}
                <label className="inline-flex cursor-pointer items-center rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body focus-within:ring-2 focus-within:ring-brand-primary hover:border-brand-primary/40">
                    Upload a picture
                    <input
                        type="file"
                        accept="image/png,image/jpeg"
                        className="sr-only"
                        data-a={`${prefix}:picture:upload`}
                        onChange={async (e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            if (!file) return;
                            const uploaded = await onUpload?.(file);
                            if (uploaded) onChange({ kind: 'image', image: uploaded.id, x: 0.5, y: 0.5, zoom: 1 });
                        }}
                    />
                </label>
            </div>
            {image && sheet && (
                <Framing
                    image={image.url}
                    framing={{ x: draft.x, y: draft.y, zoom: draft.zoom }}
                    frameWidth={sheet.width}
                    frameHeight={sheet.height}
                    prefix={`${prefix}:framing`}
                    label="Wrap picture position"
                    onChange={(f) => onChange({ ...draft, ...f })}
                />
            )}
        </div>
    );
}

function ColorField({ label, value, onChange, dataA }) {
    const [text, setText] = useState(value);
    useEffect(() => setText(value), [value]);

    return (
        <span className="inline-flex items-center gap-1">
            <input type="color" aria-label={label} value={value} data-a={`${dataA}:picker`} onChange={(e) => onChange(e.target.value)} className="h-[28px] w-[34px] cursor-pointer rounded border border-border-input bg-transparent p-0" />
            <input
                aria-label={`${label} (hex)`}
                value={text}
                data-a={dataA}
                maxLength={7}
                onChange={(e) => {
                    setText(e.target.value);
                    if (/^#[0-9a-fA-F]{6}$/.test(e.target.value)) onChange(e.target.value.toLowerCase());
                }}
                onBlur={() => setText(value)}
                className={`${input} w-[78px] font-mono`}
            />
        </span>
    );
}

function Slider({ label, unit, min, max, value, onChange, dataA }) {
    return (
        <label className="flex items-center gap-2 text-[11.5px] text-text-secondary">
            <span className="w-[86px] shrink-0">{label}</span>
            <input type="range" min={min} max={max} value={value} data-a={dataA} onChange={(e) => onChange(Number(e.target.value))} className="min-w-0 flex-1 accent-brand-primary" />
            <span className="w-[36px] text-right tabular-nums">
                {value}
                {unit}
            </span>
        </label>
    );
}
