import { useRef, useState } from 'react';
import { t, useI18n } from '../../../i18n';

/**
 * Where the artwork sits in its frame: a focal point and a zoom.
 *
 * Shows the whole picture with the part the frame keeps outlined (the
 * same sum as CoverRenderer::frame, so the outline is the crop the server
 * makes); click or drag on it to move the focal point, and zoom with the
 * slider. The cover itself follows once the server has drawn it.
 */
export function frame(w, h, frameW, frameH, { x, y, zoom }) {
    const scale = Math.max(frameW / w, frameH / h) * zoom;
    const cropW = Math.min(w, frameW / scale);
    const cropH = Math.min(h, frameH / scale);

    return {
        x: Math.max(0, Math.min(w - cropW, x * w - cropW / 2)),
        y: Math.max(0, Math.min(h - cropH, y * h - cropH / 2)),
        width: cropW,
        height: cropH,
    };
}

export default function Framing({ image, framing, frameWidth, frameHeight, onChange, label: given, prefix = 'cover:framing' }) {
    useI18n();
    const label = given ?? t('cover.artwork_position');
    const [size, setSize] = useState(null);
    const box = useRef(null);
    const dragging = useRef(false);

    const pick = (event) => {
        const rect = box.current.getBoundingClientRect();
        const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
        const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));

        onChange({ ...framing, x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000 });
    };

    const crop = size ? frame(size.w, size.h, frameWidth, frameHeight, framing) : null;

    return (
        <div className="space-y-2" data-a={prefix}>
            <p className="text-[11.5px] font-medium text-text-secondary">{label}</p>
            <div className="flex flex-wrap items-start gap-3">
                <div
                    ref={box}
                    role="slider"
                    tabIndex={0}
                    aria-label={t('cover.focal_point', { label })}
                    aria-valuetext={t('cover.focal_value', { x: Math.round(framing.x * 100), y: Math.round(framing.y * 100) })}
                    data-a={`${prefix}:picker`}
                    onPointerDown={(event) => {
                        dragging.current = true;
                        event.currentTarget.setPointerCapture?.(event.pointerId);
                        pick(event);
                    }}
                    onPointerMove={(event) => dragging.current && pick(event)}
                    onPointerUp={() => (dragging.current = false)}
                    onKeyDown={(event) => {
                        const step = event.shiftKey ? 0.1 : 0.02;
                        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };

                        if (!moves[event.key]) return;

                        event.preventDefault();
                        const [dx, dy] = moves[event.key];
                        onChange({
                            ...framing,
                            x: Math.round(Math.min(1, Math.max(0, framing.x + dx)) * 1000) / 1000,
                            y: Math.round(Math.min(1, Math.max(0, framing.y + dy)) * 1000) / 1000,
                        });
                    }}
                    className="relative w-[160px] max-w-full shrink-0 cursor-crosshair touch-none select-none overflow-hidden rounded-control border border-border-input"
                >
                    <img
                        src={image}
                        alt=""
                        draggable={false}
                        onLoad={(event) => setSize({ w: event.currentTarget.naturalWidth, h: event.currentTarget.naturalHeight })}
                        className="block h-auto w-full"
                    />
                    {crop && (
                        <svg viewBox={`0 0 ${size.w} ${size.h}`} className="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true">
                            <path
                                d={`M0 0H${size.w}V${size.h}H0Z M${crop.x} ${crop.y}V${crop.y + crop.height}H${crop.x + crop.width}V${crop.y}Z`}
                                fill="rgba(0,0,0,0.5)"
                                fillRule="evenodd"
                            />
                            <rect x={crop.x} y={crop.y} width={crop.width} height={crop.height} fill="none" stroke="#ffffff" strokeWidth={size.w / 120} />
                            <circle cx={framing.x * size.w} cy={framing.y * size.h} r={size.w / 30} fill="none" stroke="#22d3ee" strokeWidth={size.w / 120} />
                        </svg>
                    )}
                </div>

                <div className="min-w-[140px] flex-1 space-y-2">
                    <label className="block">
                        <span className="mb-0.5 flex justify-between text-[11.5px] font-medium text-text-secondary">
                            <span>{t('cover.zoom')}</span>
                            <span>{Number(framing.zoom).toFixed(2)}×</span>
                        </span>
                        <input
                            type="range"
                            min={1}
                            max={4}
                            step={0.05}
                            value={framing.zoom}
                            data-a={`${prefix}:zoom`}
                            onChange={(event) => onChange({ ...framing, zoom: Number(event.target.value) })}
                            className="w-full accent-brand-primary"
                        />
                    </label>
                    <p className="text-[11px] leading-snug text-text-secondary">{t('cover.framing_help')}</p>
                    <button
                        type="button"
                        data-a={`${prefix}:centre`}
                        onClick={() => onChange({ x: 0.5, y: 0.5, zoom: 1 })}
                        className="rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body hover:border-brand-primary/40"
                    >
                        {t('cover.centre_no_zoom')}
                    </button>
                </div>
            </div>
        </div>
    );
}
