import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { t, useI18n } from '../../../i18n';
import Icon from '../Icon';
import CoverCanvas, { textOf } from './CoverCanvas';
import { cssFamily, facesOf, ready, useFaceMetrics, useFontFiles } from './coverFonts';
import { frontSurface, imageBox, overlaps, placeLayer } from './surfaces';

/**
 * Cover Studio's layers: a canvas to drag type and pictures around on, and
 * an inspector for the selected layer. The same editor for the front, the
 * back and the spine (surfaces.jsx says which).
 *
 * A box stays exactly where the author put it. Typing wraps text inside
 * its box and grows it downward (on the spine, both ways from its centre
 * line); nothing else moves, and nothing is re-fitted. Every change is one
 * undo step (a slider drag or a burst of typing counts as one), and saves
 * on its own, debounced, through the panel's save(); the canvas is
 * immediate, the server's render follows. A box dropped on the barcode
 * area is moved clear of it.
 */

const SOURCE_NAMES = {
    title: { tKey: 'cover.layer_source_title' },
    subtitle: { tKey: 'cover.layer_source_subtitle' },
    author: { tKey: 'cover.layer_source_author' },
    series: { tKey: 'cover.layer_source_series' },
    number: { tKey: 'cover.layer_source_number' },
    edition: { tKey: 'cover.layer_source_edition' },
    tagline: { tKey: 'cover.layer_source_tagline' },
    description: { tKey: 'cover.layer_source_description' },
    publisher: { tKey: 'cover.layer_source_publisher' },
    price: { tKey: 'cover.layer_source_price' },
    category: { tKey: 'cover.layer_source_category' },
    isbn: { tKey: 'cover.layer_source_isbn' },
    custom: { tKey: 'cover.layer_source_custom' },
    image: { tKey: 'cover.layer_source_image' },
};

/** A layer source's name in the interface language. */
const sourceName = (source) => (SOURCE_NAMES[source] ? t(SOURCE_NAMES[source].tKey) : source);

const WEIGHT_NAMES = {
    regular: { tKey: 'cover.layer_weight_regular' },
    semibold: { tKey: 'cover.layer_weight_semibold' },
    bold: { tKey: 'cover.layer_weight_bold' },
};

/** Book fields a bound layer's text field edits (the rest live elsewhere). */
const EDITABLE = ['title', 'subtitle', 'author', 'tagline', 'description'];

const COALESCE_MS = 800;

export default function LayerEditor({
    data,
    surface: given,
    doc: initial,
    texts: givenTexts,
    images = [],
    onLayers,
    onText,
    onReset,
    resetLabel = t('cover.layer_reset_layout'),
    onUpload,
    onNotify,
}) {
    useI18n();
    const { cover, editor, palettes } = data;
    const surface = useMemo(() => given ?? { ...frontSurface(data), shadows: editor.shadows }, [given, data, editor.shadows]);
    const texts = givenTexts ?? cover.texts;
    const startDoc = initial ?? cover.layers;
    const [history, setHistory] = useState(() => ({ past: [], present: startDoc, future: [] }));
    const [selected, setSelected] = useState(() => startDoc.items.find((l) => l.visible)?.id ?? null);
    const [safe, setSafe] = useState(true);
    const [guides, setGuides] = useState([]);
    const [notice, setNotice] = useState(null);
    const lastEdit = useRef({ key: null, at: 0 });
    const drag = useRef(null);
    const svg = useRef(null);
    const canvasBox = useRef(null);

    const doc = history.present;
    const items = doc.items;
    const layer = items.find((l) => l.id === selected) ?? null;
    const metrics = useFaceMetrics(editor, facesOf(items.filter((l) => l.source !== 'image')));

    // --- History -----------------------------------------------------------

    // The latest history, for handlers that must not wait for a render.
    const current = useRef(history);
    current.current = history;

    /**
     * Apply a change. Changes tagged with the same key within a moment are
     * one undo step: a slider's hundred events, or a word typed.
     */
    const commit = useCallback(
        (next, key = null) => {
            const now = Date.now();
            const merge = key !== null && lastEdit.current.key === key && now - lastEdit.current.at < COALESCE_MS;
            const h = current.current;
            const updated = { past: merge ? h.past : [...h.past.slice(-99), h.present], present: next, future: [] };

            current.current = updated;
            setHistory(updated);
            onLayers(next);
            lastEdit.current = { key, at: now };
        },
        [onLayers],
    );

    const replace = useCallback(
        (next) => {
            current.current = next;
            setHistory(next);
            onLayers(next.present);
            lastEdit.current = { key: null, at: 0 };
        },
        [onLayers],
    );

    const undo = useCallback(() => {
        const h = current.current;

        if (h.past.length === 0) return;

        replace({ past: h.past.slice(0, -1), present: h.past[h.past.length - 1], future: [h.present, ...h.future] });
    }, [replace]);

    const redo = useCallback(() => {
        const h = current.current;

        if (h.future.length === 0) return;

        replace({ past: [...h.past, h.present], present: h.future[0], future: h.future.slice(1) });
    }, [replace]);

    const setItems = (nextItems, key) => commit({ ...current.current.present, items: nextItems }, key);

    // From the latest items, not this render's: two changes in one event
    // (a burst of key repeats) must not lose the first.
    const update = (id, changes, key = null) =>
        setItems(
            current.current.present.items.map((l) => (l.id === id ? { ...l, ...changes } : l)),
            key ? `${id}:${key}` : null,
        );

    // --- Geometry ----------------------------------------------------------

    const W = surface.width;
    const H = surface.height;

    const boxOf = (l) => {
        if (l.source === 'image') return imageBox(l, surface);

        return ready(metrics, l)
            ? placeLayer(l, textOf(l, texts), surface, metrics).box
            : { x: l.x * W, y: surface.spine ? H / 2 + l.y * W : l.y * H, width: l.width * W, height: 0.05 * H };
    };

    const clampMove = (l, x, y) => {
        const [low, high] = surface.yRange;
        const top = l.source === 'image' && !surface.spine ? Math.min(high, 1 - l.height) : high;

        return { x: Math.min(Math.max(0, x), 1 - l.width), y: Math.min(Math.max(low, y), top) };
    };

    /**
     * Snap a moved box's edges and centre to the centre lines and the safe
     * area. Worked in the surface's pixels: dx, dy are how far the box
     * moved; the layer moves by the same, in its own units.
     */
    const snap = (l, dxPx, dyPx, threshold) => {
        const box = boxOf(l);
        const moved = { ...box, x: box.x + dxPx, y: box.y + dyPx };
        const s = surface.safe;
        const found = [];
        const tryAxis = (value, size, candidates, axis) => {
            for (const [edge, target] of candidates) {
                const at = value + edge * size;

                if (Math.abs(at - target) < threshold) {
                    found.push({ axis, at: target });

                    return target - at;
                }
            }

            return 0;
        };

        const ax = tryAxis(moved.x, moved.width, [[0.5, W / 2], [0, s.x], [1, s.x + s.width]], 'x');
        const ay = tryAxis(moved.y, moved.height, [[0.5, H / 2], [0, s.y], [1, s.y + s.height]], 'y');

        return { ...clampMove(l, l.x + (dxPx + ax) / W, l.y + (dyPx + ay) / surface.unitY), guides: found };
    };

    /** A box over the barcode is pushed the shorter way out: up or left. */
    const clearOf = (l) => {
        const box = boxOf(l);

        for (const zone of surface.noGo) {
            if (!overlaps(box, zone)) continue;

            const up = box.y + box.height - zone.y + 6;
            const left = box.x + box.width - zone.x + 6;
            const moved = up <= left ? clampMove(l, l.x, l.y - up / surface.unitY) : clampMove(l, l.x - left / W, l.y);

            setNotice(t('cover.layer_moved_clear', { zone: zone.label.toLowerCase() }));

            return moved;
        }

        return null;
    };

    // --- Pointer -----------------------------------------------------------

    const fraction = (event) => {
        const rect = svg.current.getBoundingClientRect();

        return { fx: event.clientX / rect.width, fy: event.clientY / rect.height, rect };
    };

    const startDrag = (event, l, mode) => {
        if (event.button !== undefined && event.button !== 0) return;

        event.preventDefault();
        event.stopPropagation();
        setSelected(l.id);
        canvasBox.current?.focus({ preventScroll: true });

        const { fx, fy, rect } = fraction(event);
        drag.current = { id: l.id, mode, fx, fy, start: l, before: doc, rect, moved: false };

        try {
            svg.current.setPointerCapture(event.pointerId);
        } catch {
            // A synthetic event has no live pointer to capture.
        }
    };

    const onPointerMove = (event) => {
        const d = drag.current;

        if (!d) return;

        const { fx, fy } = fraction(event);
        const dxPx = (fx - d.fx) * W;
        const dyPx = (fy - d.fy) * H;
        const l = d.start;
        let changes;
        let shown = [];

        if (d.mode === 'move') {
            if (event.altKey) {
                changes = clampMove(l, l.x + dxPx / W, l.y + dyPx / surface.unitY);
            } else {
                const snapped = snap(l, dxPx, dyPx, (8 / d.rect.width) * W);
                shown = snapped.guides;
                changes = { x: snapped.x, y: snapped.y };
            }
        } else if (d.mode === 'left') {
            const right = l.x + l.width;
            const x = Math.min(Math.max(0, l.x + dxPx / W), right - 0.02);
            changes = { x, width: right - x };
        } else {
            changes = { width: Math.min(Math.max(0.02, l.width + dxPx / W), 1 - l.x) };
        }

        if (l.source === 'image' && l.lock && changes.width !== undefined) {
            changes.height = Math.min(aspectHeight(l, changes.width), surface.spine ? 0.25 : 1);
        }

        d.moved = true;
        setGuides(shown);
        // Live, without an undo step per pixel: the step is taken on release.
        const h = current.current;
        const next = { ...h, present: { ...h.present, items: h.present.items.map((x) => (x.id === d.id ? { ...x, ...round(changes) } : x)) } };
        current.current = next;
        setHistory(next);
    };

    const endDrag = () => {
        const d = drag.current;
        drag.current = null;
        setGuides([]);

        if (!d?.moved) return;

        const h = current.current;
        const live = h.present.items.find((x) => x.id === d.id);
        const cleared = live ? clearOf(live) : null;
        const present = cleared
            ? { ...h.present, items: h.present.items.map((x) => (x.id === d.id ? { ...x, ...round(cleared) } : x)) }
            : h.present;

        replace({ past: [...h.past.slice(-99), d.before], present, future: [] });
    };

    /** A picture's height for a width, keeping its (cropped) proportions. */
    const aspectHeight = (l, width) => {
        const image = images.find((i) => i.id === l.image);

        if (!image) return l.height;

        const crop = l.crop ?? { width: 1, height: 1 };

        return (width * W * ((crop.height * image.height) / (crop.width * image.width))) / surface.unitY;
    };

    // --- Keyboard ----------------------------------------------------------

    const nudge = (dx, dy) => {
        if (!layer) return;

        const live = current.current.present.items.find((l) => l.id === layer.id) ?? layer;
        let moved = clampMove(live, live.x + dx / W, live.y + dy / surface.unitY);
        moved = clearOf({ ...live, ...moved }) ?? moved;

        update(layer.id, round(moved), 'nudge');
    };

    const removeOrHide = (l) => {
        if (l.source === 'custom' || l.source === 'image') {
            setItems(items.filter((x) => x.id !== l.id));
            setSelected(null);
        } else {
            update(l.id, { visible: false });
        }
    };

    const onCanvasKey = (event) => {
        if (!layer) return;

        const step = event.shiftKey ? 10 : 1;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };

        if (moves[event.key]) {
            event.preventDefault();
            nudge(...moves[event.key]);
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            removeOrHide(layer);
        } else if (event.key === 'Escape') {
            setSelected(null);
        }
    };

    // Undo and redo anywhere in the editor, except inside a text field,
    // where the field's own undo is what the author means.
    useEffect(() => {
        const onKey = (event) => {
            const mod = event.ctrlKey || event.metaKey;

            if (!mod || event.key.toLowerCase() !== 'z' && event.key.toLowerCase() !== 'y') return;

            const target = event.target;

            if (target?.closest?.('input, textarea, select, [contenteditable="true"]')) return;
            if (!target?.closest?.('[data-cover-editor]')) return;

            event.preventDefault();
            event.shiftKey || event.key.toLowerCase() === 'y' ? redo() : undo();
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [undo, redo]);

    useEffect(() => {
        if (!notice) return undefined;

        const timer = setTimeout(() => setNotice(null), 5000);

        return () => clearTimeout(timer);
    }, [notice]);

    // --- Layer actions -------------------------------------------------------

    const titleLayer = items.find((l) => l.source === 'title');

    const add = (source) => {
        const base = titleLayer ?? { font: 'eb-garamond' };
        const fresh = {
            id: `${source}-${Math.random().toString(36).slice(2, 8)}`,
            source,
            text: source === 'custom' ? t('cover.layer_new_text') : null,
            visible: true,
            x: surface.fresh.x,
            y: surface.fresh.y,
            width: surface.fresh.width,
            height: null,
            align: surface.spine ? 'left' : 'center',
            font: base.font,
            weight: 'regular',
            italic: false,
            size: source === 'custom' ? surface.fresh.customSize : surface.fresh.size,
            color: surface.ink,
            opacity: 1,
            uppercase: false,
            tracking: 0,
            leading: surface.panel === 'back' ? 1.4 : 1.2,
            shadow: surface.shadowDefault,
            background: null,
        };

        setItems([...items, fresh]);
        setSelected(fresh.id);
    };

    /** A picture, a third as wide as the panel, in its own proportions. */
    const addPicture = (image) => {
        const width = surface.spine ? Math.min(0.08, (0.8 * H * image.width) / image.height / W) : 0.3;
        let height = (width * W * (image.height / image.width)) / surface.unitY;
        let w = width;

        if (!surface.spine && height > 0.5) {
            w = (width * 0.5) / height;
            height = 0.5;
        }

        const fresh = {
            id: `image-${Math.random().toString(36).slice(2, 8)}`,
            source: 'image',
            image: image.id,
            visible: true,
            x: Math.round(((1 - w) / 2) * 10000) / 10000,
            y: surface.spine ? 0 : Math.round(Math.min(0.4, 1 - height) * 10000) / 10000,
            width: Math.round(w * 10000) / 10000,
            height: Math.round(height * 1000000) / 1000000,
            opacity: 1,
            crop: null,
            lock: true,
        };

        setItems([...items, fresh]);
        setSelected(fresh.id);
    };

    const duplicate = (l) => {
        const copy =
            l.source === 'image'
                ? { ...l, id: `image-${Math.random().toString(36).slice(2, 8)}`, y: clampMove(l, l.x, l.y + 0.03).y }
                : {
                      ...l,
                      id: `custom-${Math.random().toString(36).slice(2, 8)}`,
                      source: 'custom',
                      text: textOf(l, texts),
                      y: clampMove(l, l.x, l.y + 0.03).y,
                  };
        const at = items.findIndex((x) => x.id === l.id);

        setItems([...items.slice(0, at + 1), copy, ...items.slice(at + 1)]);
        setSelected(copy.id);
    };

    const move = (l, by) => {
        const at = items.findIndex((x) => x.id === l.id);
        const to = at + by;

        if (to < 0 || to >= items.length) return;

        const next = [...items];
        [next[at], next[to]] = [next[to], next[at]];
        setItems(next);
    };

    const reorder = (fromId, toId) => {
        if (fromId === toId) return;

        const next = items.filter((x) => x.id !== fromId);
        const at = next.findIndex((x) => x.id === toId);
        next.splice(at, 0, items.find((x) => x.id === fromId));
        setItems(next);
    };

    const missing = surface.sources.filter((s) => s !== 'custom' && s !== 'image' && !items.some((l) => l.source === s));

    // --- Render ------------------------------------------------------------

    const selectedBox = layer && layer.visible ? boxOf(layer) : null;
    const stroke = Math.max(2, W / 320);
    const handle = { w: Math.max(14, W / 36), h: Math.max(30, Math.min(H * 0.6, W / 13)) };
    const front = surface.panel === 'front';

    return (
        // One grid: on a phone a single column whose canvas stays pinned at
        // the top while the list and the inspector scroll up under it like
        // a sheet; on a desktop the canvas column beside the inspector.
        // The spine is long and thin: its canvas spans the width, with the
        // list and the inspector side by side under it.
        <div
            data-cover-editor
            data-panel={surface.panel}
            className={`grid min-h-0 grid-cols-1 gap-3 ${surface.scroll ? '' : 'lg:grid-cols-[380px_minmax(0,1fr)] lg:items-start'}`}
        >
            <div className="flex justify-center lg:col-start-1">
                <Toolbar
                    canUndo={history.past.length > 0}
                    canRedo={history.future.length > 0}
                    onUndo={undo}
                    onRedo={redo}
                    safe={safe}
                    onSafe={() => setSafe((s) => !s)}
                    onAdd={add}
                    missing={missing}
                    onReset={onReset}
                    resetLabel={resetLabel}
                    images={images}
                    onPicture={addPicture}
                    onUpload={onUpload ? async (file) => {
                        const image = await onUpload(file);
                        if (image) addPicture(image);
                    } : null}
                />
            </div>

            <div
                className={`sticky top-0 z-20 flex items-center gap-2 bg-bg-surface py-1.5 lg:static lg:col-start-1 lg:flex-col lg:justify-start lg:py-0 ${
                    surface.scroll ? 'flex-col items-stretch' : 'justify-center'
                }`}
            >
                <div className={surface.scroll ? 'w-full overflow-x-auto pb-1' : 'contents'} data-a={surface.scroll ? 'cover:scroller' : undefined}>
                    <div
                        ref={canvasBox}
                        tabIndex={0}
                        role="application"
                        aria-roledescription={t('cover.layer_canvas_role')}
                        aria-label={
                            layer
                                ? t('cover.layer_canvas_selected', { surface: surface.label, name: nameOf(layer, texts, images) })
                                : t('cover.layer_canvas_none', { surface: surface.label })
                        }
                        data-a="cover:canvas"
                        onKeyDown={onCanvasKey}
                        style={surface.style}
                        className={`relative shrink-0 touch-none select-none rounded-[3px] shadow-[0_2px_14px_rgba(0,0,0,0.22)] ${surface.className}`}
                    >
                        <CoverCanvas
                            svgRef={svg}
                            surface={surface}
                            items={items}
                            texts={texts}
                            metrics={metrics}
                            images={images}
                            label={t('cover.layer_preview', { surface: surface.label })}
                            className="block h-auto w-full rounded-[3px] bg-bg-subtle"
                            onPointerMove={onPointerMove}
                            onPointerUp={endDrag}
                            onPointerCancel={endDrag}
                            onPointerDown={(event) => {
                                if (event.target === svg.current || event.target.tagName === 'image') setSelected(null);
                            }}
                        >
                            {surface.trim && (
                                <rect
                                    x={surface.trim.x}
                                    y={surface.trim.y}
                                    width={surface.trim.width}
                                    height={surface.trim.height}
                                    fill="none"
                                    stroke="#f97316"
                                    strokeWidth={stroke}
                                    pointerEvents="none"
                                />
                            )}

                            {safe && (
                                <rect
                                    data-a="cover:safe-area"
                                    x={surface.safe.x}
                                    y={surface.safe.y}
                                    width={surface.safe.width}
                                    height={surface.safe.height}
                                    fill="none"
                                    stroke="#22d3ee"
                                    strokeWidth={stroke}
                                    strokeDasharray={`${stroke * 4.5} ${stroke * 3.5}`}
                                    pointerEvents="none"
                                />
                            )}

                            {surface.noGo.map((zone) => (
                                <g key={zone.label} data-a={`cover:nogo:${zone.label.toLowerCase()}`} pointerEvents="none">
                                    <rect x={zone.x} y={zone.y} width={zone.width} height={zone.height} fill="#ffffff" stroke="#ef4444" strokeWidth={stroke} />
                                    <line x1={zone.x} y1={zone.y} x2={zone.x + zone.width} y2={zone.y + zone.height} stroke="#ef4444" strokeWidth={stroke / 2} />
                                    <line x1={zone.x + zone.width} y1={zone.y} x2={zone.x} y2={zone.y + zone.height} stroke="#ef4444" strokeWidth={stroke / 2} />
                                    <text x={zone.x + zone.width / 2} y={zone.y + zone.height / 2} fontSize={zone.height / 5} textAnchor="middle" dominantBaseline="middle" fill="#b91c1c">
                                        {zone.label}
                                    </text>
                                </g>
                            ))}

                            {guides.map((g, i) =>
                                g.axis === 'x' ? (
                                    <line key={i} x1={g.at} x2={g.at} y1={0} y2={H} stroke="#f472b6" strokeWidth={stroke} pointerEvents="none" />
                                ) : (
                                    <line key={i} x1={0} x2={W} y1={g.at} y2={g.at} stroke="#f472b6" strokeWidth={stroke} pointerEvents="none" />
                                ),
                            )}

                            {items.map((l) => {
                                if (!l.visible) return null;

                                const box = boxOf(l);

                                return (
                                    <rect
                                        key={l.id}
                                        data-a={`cover:box:${l.id}`}
                                        x={box.x}
                                        y={box.y}
                                        width={box.width}
                                        height={Math.max(box.height, H * 0.01)}
                                        fill="transparent"
                                        style={{ cursor: 'move' }}
                                        onPointerDown={(event) => startDrag(event, l, 'move')}
                                    />
                                );
                            })}

                            {selectedBox && (
                                <g data-a="cover:selection">
                                    <rect
                                        x={selectedBox.x}
                                        y={selectedBox.y}
                                        width={selectedBox.width}
                                        height={Math.max(selectedBox.height, H * 0.01)}
                                        fill="none"
                                        stroke="#3b82f6"
                                        strokeWidth={stroke * 1.2}
                                        pointerEvents="none"
                                    />
                                    {['left', 'right'].map((side) => (
                                        <rect
                                            key={side}
                                            data-a={`cover:handle:${side}`}
                                            x={(side === 'left' ? selectedBox.x : selectedBox.x + selectedBox.width) - handle.w / 2}
                                            y={selectedBox.y + Math.max(selectedBox.height, H * 0.01) / 2 - handle.h / 2}
                                            width={handle.w}
                                            height={handle.h}
                                            rx={handle.w / 4}
                                            fill="#ffffff"
                                            stroke="#3b82f6"
                                            strokeWidth={stroke * 1.4}
                                            style={{ cursor: 'ew-resize' }}
                                            onPointerDown={(event) => startDrag(event, layer, side)}
                                        />
                                    ))}
                                </g>
                            )}
                        </CoverCanvas>
                    </div>
                </div>

                {layer && <Nudge onNudge={nudge} />}
            </div>

            {notice && (
                <p role="status" data-a="cover:notice" className="rounded-control bg-status-warning-bg px-2.5 py-1.5 text-[11.5px] text-status-warning lg:col-start-1">
                    {notice}
                </p>
            )}

            {front && (
                <div className="flex justify-center lg:col-start-1">
                    <div className="flex w-full max-w-[360px] items-end justify-between gap-3">
                        {/* Store lists show covers this small: check the title reads. */}
                        <figure className="flex flex-col items-center gap-1">
                            <CoverCanvas
                                surface={surface}
                                items={items}
                                texts={texts}
                                metrics={metrics}
                                images={images}
                                label={t('cover.layer_thumbnail_label')}
                                data-a="cover:thumbnail"
                                className="block h-[160px] w-[100px] rounded-[2px] shadow-[0_1px_6px_rgba(0,0,0,0.25)]"
                            />
                            <figcaption className="text-[10.5px] text-text-secondary">{t('cover.layer_thumbnail')}</figcaption>
                        </figure>

                        <figure className="flex flex-col items-center gap-1">
                            <img
                                src={cover.image}
                                alt={t('cover.layer_saved_alt')}
                                data-a="cover:preview"
                                width={100}
                                height={160}
                                className="h-[160px] w-[100px] rounded-[2px] bg-bg-surface object-cover shadow-[0_1px_6px_rgba(0,0,0,0.25)]"
                            />
                            <figcaption className="text-[10.5px] text-text-secondary">{t('cover.layer_saved_file')}</figcaption>
                        </figure>

                        <a
                            href={cover.download}
                            data-a="cover:download"
                            className="inline-flex items-center gap-1.5 rounded-control border border-border-input bg-bg-surface px-2.5 py-1.5 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40"
                        >
                            <Icon name="exp" size={13} strokeWidth={2} />
                            {t('cover.layer_download')}
                        </a>
                    </div>
                </div>
            )}

            <div
                className={`min-w-0 ${
                    surface.scroll
                        ? 'grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)] lg:items-start'
                        : `space-y-3 lg:col-start-2 lg:row-start-1 ${front ? 'lg:row-span-3' : 'lg:row-span-4'}`
                }`}
            >
                <LayerList
                    items={items}
                    texts={texts}
                    images={images}
                    selected={selected}
                    onSelect={setSelected}
                    onToggle={(l) => update(l.id, { visible: !l.visible })}
                    onMove={move}
                    onReorder={reorder}
                />

                {layer ? (
                    layer.source === 'image' ? (
                        <PictureInspector
                            key={layer.id}
                            layer={layer}
                            surface={surface}
                            images={images}
                            onChange={(changes, key) => update(layer.id, changes, key)}
                            aspectHeight={aspectHeight}
                            onDuplicate={() => duplicate(layer)}
                            onDelete={() => removeOrHide(layer)}
                            onForward={() => move(layer, 1)}
                            onBack={() => move(layer, -1)}
                            onClose={() => setSelected(null)}
                        />
                    ) : (
                        <Inspector
                            key={layer.id}
                            layer={layer}
                            data={data}
                            surface={surface}
                            texts={texts}
                            onChange={(changes, key) => update(layer.id, changes, key)}
                            onText={onText}
                            onDuplicate={() => duplicate(layer)}
                            onDelete={() => removeOrHide(layer)}
                            onForward={() => move(layer, 1)}
                            onBack={() => move(layer, -1)}
                            onClose={() => setSelected(null)}
                            palettes={palettes}
                        />
                    )
                ) : (
                    <p className="rounded-tile border border-dashed border-border-input p-3 text-[12px] text-text-secondary">
                        {t('cover.layer_choose', { surface: surface.label.toLowerCase() })}
                    </p>
                )}
            </div>
        </div>
    );
}

/** Positions to 1/10000 of the panel (sizes finer): plenty, and short in storage. */
function round(changes) {
    const out = {};

    for (const [k, v] of Object.entries(changes)) {
        out[k] = typeof v === 'number' ? Math.round(v * (k === 'height' ? 1000000 : 10000)) / (k === 'height' ? 1000000 : 10000) : v;
    }

    return out;
}

function nameOf(layer, texts, images = []) {
    if (layer.source === 'image') return images.find((i) => i.id === layer.image)?.name ?? t('cover.layer_source_image');
    if (layer.source !== 'custom') return sourceName(layer.source);

    const text = (layer.text ?? '').trim();

    if (text === '') return layer.background ? t('cover.layer_shape') : t('cover.layer_empty_text');

    return text.length > 24 ? `${text.slice(0, 24)}…` : text;
}

function Toolbar({ canUndo, canRedo, onUndo, onRedo, safe, onSafe, onAdd, missing, onReset, resetLabel, images, onPicture, onUpload }) {
    const button =
        'inline-flex min-h-[36px] items-center gap-1 rounded-control border border-border-input bg-bg-surface px-2.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-40';
    const file = useRef(null);
    const [busy, setBusy] = useState(false);

    return (
        <div role="toolbar" aria-label={t('cover.layer_toolbar')} className="flex w-full max-w-[380px] flex-wrap items-center gap-1.5">
            <button type="button" onClick={onUndo} disabled={!canUndo} data-a="cover:undo" aria-label={t('cover.layer_undo')} title={t('cover.layer_undo_title')} className={button}>
                <Icon name="reset" size={13} strokeWidth={2} /> {t('cover.layer_undo')}
            </button>
            <button type="button" onClick={onRedo} disabled={!canRedo} data-a="cover:redo" aria-label={t('cover.layer_redo')} title={t('cover.layer_redo_title')} className={button}>
                {t('cover.layer_redo')}
            </button>
            <button type="button" onClick={() => onAdd('custom')} data-a="cover:add-text" className={button}>
                <Icon name="plus" size={13} strokeWidth={2} /> {t('cover.layer_add_text')}
            </button>

            {missing.length > 0 && (
                <label className="inline-flex items-center">
                    <span className="sr-only">{t('cover.layer_add_field_label')}</span>
                    <select
                        value=""
                        data-a="cover:add-field"
                        onChange={(event) => event.target.value && onAdd(event.target.value)}
                        className="min-h-[36px] rounded-control border border-border-input bg-bg-surface px-2 text-[12px]"
                    >
                        <option value="">{t('cover.layer_add_field')}</option>
                        {missing.map((s) => (
                            <option key={s} value={s}>
                                {sourceName(s)}
                            </option>
                        ))}
                    </select>
                </label>
            )}

            {onUpload && (
                <>
                    <label className="inline-flex items-center">
                        <span className="sr-only">{t('cover.layer_add_picture_label')}</span>
                        <select
                            value=""
                            data-a="cover:add-picture"
                            disabled={busy}
                            onChange={(event) => {
                                const value = event.target.value;

                                if (value === '__upload') file.current?.click();
                                else if (value) onPicture(images.find((i) => i.id === value));
                            }}
                            className="min-h-[36px] rounded-control border border-border-input bg-bg-surface px-2 text-[12px]"
                        >
                            <option value="">{busy ? t('cover.layer_uploading') : t('cover.layer_add_picture')}</option>
                            {images.map((image) => (
                                <option key={image.id} value={image.id}>
                                    {image.name}
                                </option>
                            ))}
                            <option value="__upload">{t('cover.layer_upload')}</option>
                        </select>
                    </label>
                    <input
                        ref={file}
                        type="file"
                        accept="image/png,image/jpeg"
                        data-a="cover:picture-upload"
                        className="sr-only"
                        onChange={async (event) => {
                            const chosen = event.target.files?.[0];
                            event.target.value = '';

                            if (!chosen) return;

                            setBusy(true);

                            try {
                                await onUpload(chosen);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    />
                </>
            )}

            <button type="button" onClick={onSafe} aria-pressed={safe} data-a="cover:safe" className={button}>
                {t('cover.layer_safe_area')}
            </button>
            <button type="button" onClick={onReset} data-a="cover:reset-layout" className={`${button} ml-auto`}>
                {resetLabel}
            </button>
        </div>
    );
}

function Nudge({ onNudge }) {
    const button =
        'flex h-[40px] w-[40px] items-center justify-center rounded-control border border-border-input bg-bg-surface text-[15px] text-text-body transition hover:border-brand-primary/40';

    return (
        // A cross of four on a phone, beside the canvas; a row under it on a desktop.
        <div role="group" aria-label={t('cover.layer_move_group')} className="grid grid-cols-3 gap-1 self-center lg:flex lg:gap-1.5">
            <button type="button" aria-label={t('cover.layer_move_up')} data-a="cover:nudge:up" onClick={() => onNudge(0, -10)} className={`${button} col-start-2`}>↑</button>
            <button type="button" aria-label={t('cover.layer_move_left')} data-a="cover:nudge:left" onClick={() => onNudge(-10, 0)} className={`${button} col-start-1 row-start-2`}>←</button>
            <button type="button" aria-label={t('cover.layer_move_right')} data-a="cover:nudge:right" onClick={() => onNudge(10, 0)} className={`${button} col-start-3 row-start-2`}>→</button>
            <button type="button" aria-label={t('cover.layer_move_down')} data-a="cover:nudge:down" onClick={() => onNudge(0, 10)} className={`${button} col-start-2 row-start-3`}>↓</button>
        </div>
    );
}

function LayerList({ items, texts, images, selected, onSelect, onToggle, onMove, onReorder }) {
    const dragging = useRef(null);

    // Listed front first, as layer lists are: the last drawn is on top.
    const listed = [...items].reverse();

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-2.5">
            <h3 className="mb-1.5 px-1 text-[12.5px] font-semibold text-text-primary">{t('cover.layer_layers')}</h3>
            <ul className="space-y-1" data-a="cover:layers">
                {listed.map((l, i) => (
                    <li
                        key={l.id}
                        draggable
                        onDragStart={() => (dragging.current = l.id)}
                        onDragOver={(event) => event.preventDefault()}
                        onDrop={(event) => {
                            event.preventDefault();
                            if (dragging.current) onReorder(dragging.current, l.id);
                            dragging.current = null;
                        }}
                        className={`flex items-center gap-1 rounded-control border px-1 ${
                            l.id === selected ? 'border-brand-primary bg-brand-primary-subtle' : 'border-transparent'
                        }`}
                    >
                        <span aria-hidden="true" className="cursor-grab text-text-secondary">
                            <Icon name={l.source === 'image' ? 'image' : 'grip'} size={14} strokeWidth={2} />
                        </span>
                        <button
                            type="button"
                            aria-pressed={l.visible}
                            aria-label={l.visible ? t('cover.layer_hide_named', { name: nameOf(l, texts, images) }) : t('cover.layer_show_named', { name: nameOf(l, texts, images) })}
                            data-a={`cover:layer:eye:${l.id}`}
                            onClick={() => onToggle(l)}
                            className={`flex h-[34px] w-[34px] items-center justify-center rounded-control ${l.visible ? 'text-text-body' : 'text-text-secondary opacity-50'}`}
                        >
                            <Icon name="eye" size={15} strokeWidth={2} />
                        </button>
                        <button
                            type="button"
                            aria-current={l.id === selected ? 'true' : undefined}
                            data-a={`cover:layer:${l.id}`}
                            onClick={() => onSelect(l.id)}
                            className={`min-h-[34px] flex-1 truncate text-left text-[12.5px] ${l.visible ? 'text-text-primary' : 'text-text-secondary line-through'}`}
                        >
                            {nameOf(l, texts, images)}
                        </button>
                        <button
                            type="button"
                            aria-label={t('cover.layer_forward_named', { name: nameOf(l, texts, images) })}
                            disabled={i === 0}
                            onClick={() => onMove(l, 1)}
                            className="h-[30px] w-[28px] rounded-control text-[13px] text-text-secondary disabled:opacity-30"
                        >
                            ▲
                        </button>
                        <button
                            type="button"
                            aria-label={t('cover.layer_back_named', { name: nameOf(l, texts, images) })}
                            disabled={i === listed.length - 1}
                            onClick={() => onMove(l, -1)}
                            className="h-[30px] w-[28px] rounded-control text-[13px] text-text-secondary disabled:opacity-30"
                        >
                            ▼
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Inspector({ layer, data, surface, texts, palettes, onChange, onText, onDuplicate, onDelete, onForward, onBack, onClose }) {
    const { cover, editor } = data;
    const family = editor.fonts.find((f) => f.key === layer.font);
    const styles = family?.styles ?? {};
    const weights = ['regular', 'semibold', 'bold'].filter((w) => styles[w] || styles[`${w}-italic`]);
    const hasItalic = Boolean(styles[`${layer.weight}-italic`]);
    const hasUpright = Boolean(styles[layer.weight]);

    // The faces of every family, so the picker shows each in its own type.
    useFontFiles(editor, editor.fonts.map((f) => `${f.key}.${Object.keys(f.styles)[0]}`));

    const pickFamily = (key) => {
        const next = editor.fonts.find((f) => f.key === key);
        const has = (w, i) => Boolean(next.styles[`${w}${i ? '-italic' : ''}`]);
        let weight = layer.weight;
        let italic = layer.italic;

        if (!has(weight, italic)) {
            if (has(weight, false)) italic = false;
            else if (has('regular', italic)) weight = 'regular';
            else [weight, italic] = ['regular', false];
        }

        onChange({ font: key, weight, italic });
    };

    const swatches = useMemo(() => {
        const palette = palettes[cover.palette] ?? palettes[0];

        return [...new Set(['#ffffff', '#000000', '#1a1a1a', surface.ink, palette.ink, palette.from, palette.to, '#f5efe0', '#c9a227', '#b91c1c'].map((c) => c.toLowerCase()))];
    }, [palettes, cover.palette, surface.ink]);

    const name = sourceName(layer.source);
    const size = surface.size;

    return (
        <section
            aria-label={t('cover.layer_selected_text')}
            data-a="cover:inspector"
            className="space-y-3 rounded-tile border border-border-default bg-bg-surface p-3 max-lg:rounded-t-[16px] max-lg:shadow-[0_-6px_20px_rgba(0,0,0,0.12)]"
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">{layer.source === 'custom' ? t('cover.layer_source_custom') : t('cover.layer_named_text', { name })}</h3>
                <div className="flex items-center gap-1">
                    <Toggle label={t('cover.layer_visible')} checked={layer.visible} onChange={(v) => onChange({ visible: v })} data-a="cover:visible" />
                    <button type="button" onClick={onClose} aria-label={t('cover.layer_close_inspector')} className="flex h-[32px] w-[32px] items-center justify-center rounded-control text-text-secondary lg:hidden">
                        <Icon name="x" size={14} strokeWidth={2} />
                    </button>
                </div>
            </div>

            {layer.source === 'custom' ? (
                <Field label={t('cover.layer_source_custom')}>
                    <textarea
                        rows={2}
                        value={layer.text ?? ''}
                        data-a="cover:text"
                        onChange={(event) => onChange({ text: event.target.value }, 'text')}
                        className="w-full resize-y rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                    />
                </Field>
            ) : layer.source === 'description' ? (
                <Field label={t('cover.layer_description_field')}>
                    <textarea
                        rows={6}
                        value={texts.description ?? ''}
                        data-a="cover:description"
                        onChange={(event) => onText('description', event.target.value)}
                        className="w-full resize-y rounded-control border border-border-input px-2 py-1.5 text-[12.5px] leading-relaxed focus:border-brand-primary focus:ring-0"
                    />
                    <span className="mt-1 block text-[11px] text-text-secondary">
                        {t('cover.layer_description_help')}
                    </span>
                </Field>
            ) : EDITABLE.includes(layer.source) ? (
                <Field label={t('cover.layer_on_cover', { name })}>
                    <input
                        value={texts[layer.source] ?? ''}
                        data-a={`cover:${layer.source}`}
                        onChange={(event) => onText(layer.source, event.target.value)}
                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                    />
                </Field>
            ) : (
                <p className="text-[11.5px] text-text-secondary">
                    {texts[layer.source]
                        ? t('cover.layer_shows', { value: texts[layer.source] })
                        : t('cover.layer_missing', { name: name.toLowerCase() })}
                </p>
            )}

            <fieldset>
                <legend className="mb-1 text-[11.5px] font-medium text-text-secondary">{t('cover.layer_typeface')}</legend>
                <div role="radiogroup" aria-label={t('cover.layer_typeface')} className="grid grid-cols-2 gap-1 sm:grid-cols-3">
                    {editor.fonts.map((f) => {
                        const first = Object.keys(f.styles)[0];
                        const face = `${f.key}.${first}`;

                        return (
                            <button
                                key={f.key}
                                type="button"
                                role="radio"
                                aria-checked={layer.font === f.key}
                                data-a={`cover:font:${f.key}`}
                                onClick={() => pickFamily(f.key)}
                                style={{ fontFamily: `${cssFamily(face)}, serif` }}
                                className={`min-h-[36px] truncate rounded-control border px-2 text-left text-[15px] ${
                                    layer.font === f.key ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body hover:border-brand-primary/40'
                                }`}
                            >
                                {f.name}
                            </button>
                        );
                    })}
                </div>
            </fieldset>

            <div className="grid grid-cols-2 gap-2">
                <Field label={t('cover.layer_weight')}>
                    <select
                        value={layer.weight}
                        data-a="cover:weight"
                        onChange={(event) => {
                            const weight = event.target.value;
                            const italic = layer.italic && styles[`${weight}-italic`] ? true : !styles[weight];
                            onChange({ weight, italic });
                        }}
                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px]"
                    >
                        {weights.map((w) => (
                            <option key={w} value={w}>
                                {WEIGHT_NAMES[w] ? t(WEIGHT_NAMES[w].tKey) : w}
                            </option>
                        ))}
                    </select>
                </Field>
                <div className="flex items-end gap-1.5 pb-0.5">
                    <Toggle label={t('cover.layer_italic')} checked={layer.italic} disabled={layer.italic ? !hasUpright : !hasItalic} onChange={(v) => onChange({ italic: v })} data-a="cover:italic" />
                    <Toggle label={t('cover.layer_capitals')} checked={layer.uppercase} onChange={(v) => onChange({ uppercase: v })} data-a="cover:uppercase" />
                </div>
            </div>

            <Slider
                label={t('cover.layer_size')}
                unit={size.unit}
                min={size.min}
                max={size.max}
                step={size.step}
                value={size.to(layer.size)}
                data-a="cover:size"
                onChange={(v) => onChange({ size: size.from(Math.min(size.max, Math.max(size.min, v))) }, 'size')}
            />

            <fieldset>
                <legend className="mb-1 text-[11.5px] font-medium text-text-secondary">{t('cover.layer_colour')}</legend>
                <div className="flex flex-wrap items-center gap-1.5">
                    {swatches.map((c) => (
                        <button
                            key={c}
                            type="button"
                            aria-label={t('cover.layer_colour_named', { color: c })}
                            aria-pressed={layer.color === c}
                            onClick={() => onChange({ color: c })}
                            className={`h-[30px] w-[30px] rounded-full border-2 ${layer.color === c ? 'border-brand-primary' : 'border-border-input'}`}
                            style={{ background: c }}
                        />
                    ))}
                    <label className="inline-flex items-center gap-1">
                        <span className="sr-only">{t('cover.layer_pick_colour')}</span>
                        <input type="color" value={layer.color} data-a="cover:color" onChange={(event) => onChange({ color: event.target.value.toLowerCase() }, 'color')} className="h-[30px] w-[36px] cursor-pointer rounded border border-border-input bg-transparent" />
                    </label>
                    <HexInput value={layer.color} onChange={(c) => onChange({ color: c })} label={t('cover.layer_colour_hex')} data-a="cover:color-hex" />
                </div>
            </fieldset>

            <Slider label={t('cover.layer_opacity')} unit="%" min={0} max={100} step={1} value={Math.round(layer.opacity * 100)} onChange={(v) => onChange({ opacity: v / 100 }, 'opacity')} data-a="cover:opacity" />

            <div role="group" aria-label={t('cover.layer_alignment')} className="flex gap-1">
                {['left', 'center', 'right'].map((a) => (
                    <button
                        key={a}
                        type="button"
                        aria-pressed={layer.align === a}
                        data-a={`cover:align:${a}`}
                        onClick={() => onChange({ align: a })}
                        className={`min-h-[34px] flex-1 rounded-control border text-[12px] capitalize ${layer.align === a ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body'}`}
                    >
                        {a === 'center' ? t('cover.layer_align_center') : a === 'left' ? t('cover.layer_align_left') : t('cover.layer_align_right')}
                    </button>
                ))}
            </div>

            <Slider label={t('cover.layer_letter_spacing')} unit="em" min={-0.05} max={0.5} step={0.01} value={layer.tracking} onChange={(v) => onChange({ tracking: v }, 'tracking')} data-a="cover:tracking" />
            <Slider label={t('cover.layer_line_height')} unit="×" min={0.7} max={2.5} step={0.01} value={layer.leading} onChange={(v) => onChange({ leading: v }, 'leading')} data-a="cover:leading" />

            <div role="group" aria-label={t('cover.layer_shadow')} className="flex items-center gap-1">
                <span className="mr-1 text-[11.5px] font-medium text-text-secondary">{t('cover.layer_shadow')}</span>
                {['none', 'soft', 'strong'].map((s) => (
                    <button
                        key={s}
                        type="button"
                        aria-pressed={layer.shadow === s}
                        data-a={`cover:shadow:${s}`}
                        onClick={() => onChange({ shadow: s })}
                        className={`min-h-[32px] flex-1 rounded-control border text-[12px] capitalize ${layer.shadow === s ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body'}`}
                    >
                        {s === 'none' ? t('cover.layer_shadow_none') : s === 'soft' ? t('cover.layer_shadow_soft') : t('cover.layer_shadow_strong')}
                    </button>
                ))}
            </div>

            <div className="rounded-control border border-border-default p-2">
                <Toggle
                    label={t('cover.layer_background_panel')}
                    checked={Boolean(layer.background)}
                    data-a="cover:panel"
                    onChange={(v) => onChange({ background: v ? { color: '#000000', opacity: 0.5, padding: 0.02 } : null })}
                />
                {layer.background && (
                    <div className="mt-2 space-y-2">
                        <div className="flex items-center gap-2">
                            <label className="inline-flex items-center gap-1 text-[11.5px] text-text-secondary">
                                {t('cover.layer_panel_colour')}
                                <input type="color" value={layer.background.color} onChange={(event) => onChange({ background: { ...layer.background, color: event.target.value.toLowerCase() } }, 'panel-color')} className="h-[28px] w-[34px] rounded border border-border-input" />
                            </label>
                        </div>
                        <Slider label={t('cover.layer_panel_opacity')} unit="%" min={0} max={100} step={1} value={Math.round(layer.background.opacity * 100)} onChange={(v) => onChange({ background: { ...layer.background, opacity: v / 100 } }, 'panel-opacity')} />
                        <Slider label={t('cover.layer_panel_padding')} unit="%" min={0} max={20} step={0.5} value={Math.round(layer.background.padding * 1000) / 10} onChange={(v) => onChange({ background: { ...layer.background, padding: v / 100 } }, 'panel-padding')} />
                    </div>
                )}
            </div>

            <Actions layer={layer} onDuplicate={onDuplicate} onDelete={onDelete} onForward={onForward} onBack={onBack} />
        </section>
    );
}

/** A picture: its size, opacity, crop, proportions, and how sharp it prints. */
function PictureInspector({ layer, surface, images, onChange, aspectHeight, onDuplicate, onDelete, onForward, onBack, onClose }) {
    const image = images.find((i) => i.id === layer.image);
    const crop = layer.crop ?? { x: 0, y: 0, width: 1, height: 1 };
    // Printed width in inches, where the surface knows it (the back and
    // spine are drawn at 300 dpi; the front's print size depends on the trim).
    const inches = surface.inchesPerPx ? layer.width * surface.width * surface.inchesPerPx : null;
    const dpi = image && inches ? Math.floor((image.width * crop.width) / inches) : null;

    const setCrop = (changes) => {
        const next = { ...crop, ...changes };
        next.width = Math.min(next.width, 1 - next.x);
        next.height = Math.min(next.height, 1 - next.y);
        const full = next.x === 0 && next.y === 0 && next.width === 1 && next.height === 1;
        const updated = { ...layer, crop: full ? null : next };

        onChange({ crop: updated.crop, ...(layer.lock ? { height: aspectHeight(updated, layer.width) } : {}) }, 'crop');
    };

    return (
        <section
            aria-label={t('cover.layer_selected_picture')}
            data-a="cover:picture-inspector"
            className="space-y-3 rounded-tile border border-border-default bg-bg-surface p-3 max-lg:rounded-t-[16px] max-lg:shadow-[0_-6px_20px_rgba(0,0,0,0.12)]"
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="truncate text-[13px] font-semibold text-text-primary">{image?.name ?? t('cover.layer_source_image')}</h3>
                <div className="flex items-center gap-1">
                    <Toggle label={t('cover.layer_visible')} checked={layer.visible} onChange={(v) => onChange({ visible: v })} data-a="cover:visible" />
                    <button type="button" onClick={onClose} aria-label={t('cover.layer_close_inspector')} className="flex h-[32px] w-[32px] items-center justify-center rounded-control text-text-secondary lg:hidden">
                        <Icon name="x" size={14} strokeWidth={2} />
                    </button>
                </div>
            </div>

            {image && (
                <p className="text-[11.5px] text-text-secondary">
                    {image.width} × {image.height} px
                    {dpi !== null && (
                        <>
                            {' '}· {t('cover.layer_prints_at')}{' '}
                            <span data-a="cover:picture-dpi" className={dpi < 300 ? 'font-semibold text-status-warning' : 'text-text-body'}>
                                {dpi} dpi
                            </span>
                            {dpi < 300 && ` ${t('cover.layer_low_dpi')}`}
                        </>
                    )}
                </p>
            )}

            <Slider
                label={t('cover.layer_width')}
                unit="%"
                min={1}
                max={100}
                step={0.5}
                value={Math.round(layer.width * 1000) / 10}
                data-a="cover:picture-width"
                onChange={(v) => {
                    const width = Math.min(v / 100, 1 - layer.x);
                    onChange({ width, ...(layer.lock ? { height: Math.min(aspectHeight(layer, width), surface.spine ? 0.25 : 1) } : {}) }, 'width');
                }}
            />
            <Slider
                label={t('cover.layer_height')}
                unit="%"
                min={0.5}
                max={surface.spine ? 25 : 100}
                step={0.5}
                value={Math.round(layer.height * 1000) / 10}
                data-a="cover:picture-height"
                onChange={(v) => onChange({ height: v / 100, lock: false }, 'height')}
            />
            <Toggle
                label={t('cover.layer_keep_proportions')}
                checked={layer.lock}
                data-a="cover:picture-lock"
                onChange={(v) => onChange(v ? { lock: true, height: aspectHeight(layer, layer.width) } : { lock: false })}
            />
            <Slider label={t('cover.layer_opacity')} unit="%" min={0} max={100} step={1} value={Math.round(layer.opacity * 100)} onChange={(v) => onChange({ opacity: v / 100 }, 'opacity')} data-a="cover:opacity" />

            <fieldset className="rounded-control border border-border-default p-2">
                <legend className="px-1 text-[11.5px] font-medium text-text-secondary">{t('cover.layer_crop')}</legend>
                <div className="grid grid-cols-2 gap-x-3">
                    <Slider label={t('cover.layer_crop_left')} unit="%" min={0} max={95} step={1} value={Math.round(crop.x * 100)} onChange={(v) => setCrop({ x: v / 100 })} data-a="cover:crop-x" />
                    <Slider label={t('cover.layer_crop_top')} unit="%" min={0} max={95} step={1} value={Math.round(crop.y * 100)} onChange={(v) => setCrop({ y: v / 100 })} data-a="cover:crop-y" />
                    <Slider label={t('cover.layer_width')} unit="%" min={5} max={100} step={1} value={Math.round(crop.width * 100)} onChange={(v) => setCrop({ width: v / 100 })} data-a="cover:crop-width" />
                    <Slider label={t('cover.layer_height')} unit="%" min={5} max={100} step={1} value={Math.round(crop.height * 100)} onChange={(v) => setCrop({ height: v / 100 })} data-a="cover:crop-height" />
                </div>
            </fieldset>

            <Actions layer={layer} onDuplicate={onDuplicate} onDelete={onDelete} onForward={onForward} onBack={onBack} />
        </section>
    );
}

function Actions({ layer, onDuplicate, onDelete, onForward, onBack }) {
    return (
        <div className="flex flex-wrap gap-1.5 border-t border-border-default pt-2.5">
            <SmallButton onClick={onDuplicate} data-a="cover:duplicate">{t('cover.layer_duplicate')}</SmallButton>
            <SmallButton onClick={onForward} data-a="cover:forward">{t('cover.layer_bring_forward')}</SmallButton>
            <SmallButton onClick={onBack} data-a="cover:back">{t('cover.layer_send_back')}</SmallButton>
            <SmallButton onClick={onDelete} data-a="cover:delete" danger>
                {layer.source === 'custom' || layer.source === 'image' ? t('cover.layer_delete') : t('cover.layer_hide')}
            </SmallButton>
        </div>
    );
}

function SmallButton({ children, danger, ...rest }) {
    return (
        <button
            type="button"
            {...rest}
            className={`min-h-[32px] rounded-control border px-2.5 text-[12px] font-medium transition ${
                danger ? 'border-border-input text-status-danger hover:border-status-danger/40' : 'border-border-input text-text-body hover:border-brand-primary/40'
            }`}
        >
            {children}
        </button>
    );
}

function Toggle({ label, checked, onChange, disabled, ...rest }) {
    return (
        <label className={`inline-flex min-h-[32px] cursor-pointer items-center gap-1.5 text-[12px] text-text-body ${disabled ? 'opacity-40' : ''}`}>
            <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="h-4 w-4 rounded border-border-input accent-brand-primary" {...rest} />
            {label}
        </label>
    );
}

function Slider({ label, unit, min, max, step, value, onChange, ...rest }) {
    const shown = Number.isInteger(step) ? value : Number(value).toFixed(step >= 0.1 ? 1 : 2);

    return (
        <div>
            <label className="mb-0.5 flex items-center justify-between gap-2 text-[11.5px] font-medium text-text-secondary">
                <span>{label}</span>
                <span className="inline-flex items-center gap-1">
                    <input
                        type="number"
                        min={min}
                        max={max}
                        step={step}
                        value={shown}
                        aria-label={`${label} (${unit})`}
                        onChange={(event) => event.target.value !== '' && onChange(Math.min(max, Math.max(min, Number(event.target.value))))}
                        className="w-[68px] rounded-control border border-border-input px-1.5 py-0.5 text-right text-[12px]"
                    />
                    <span aria-hidden="true">{unit}</span>
                </span>
            </label>
            <input
                type="range"
                min={min}
                max={max}
                step={step}
                value={value}
                aria-label={label}
                onChange={(event) => onChange(Number(event.target.value))}
                className="w-full accent-brand-primary"
                {...rest}
            />
        </div>
    );
}

function HexInput({ value, onChange, label, ...rest }) {
    const [draft, setDraft] = useState(value);

    useEffect(() => setDraft(value), [value]);

    return (
        <input
            value={draft}
            aria-label={label}
            maxLength={7}
            onChange={(event) => {
                const v = event.target.value.trim();
                setDraft(v);
                if (/^#[0-9a-fA-F]{6}$/.test(v)) onChange(v.toLowerCase());
            }}
            className="w-[82px] rounded-control border border-border-input px-1.5 py-1 font-mono text-[12px]"
            {...rest}
        />
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">{label}</span>
            {children}
        </label>
    );
}
