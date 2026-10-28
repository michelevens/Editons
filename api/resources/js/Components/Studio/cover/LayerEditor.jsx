import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Icon from '../Icon';
import CoverCanvas, { textOf } from './CoverCanvas';
import { faceKey, layoutLayer } from './coverText';
import { cssFamily, facesOf, ready, useFaceMetrics, useFontFiles } from './coverFonts';

/**
 * Cover Studio's text layers: a canvas to drag type around on, and an
 * inspector for the selected layer.
 *
 * A box stays exactly where the author put it. Typing wraps text inside
 * its box and grows it downward; nothing else moves, and nothing is
 * re-fitted. Every change is one undo step (a slider drag or a burst of
 * typing counts as one), and saves on its own, debounced, through the
 * panel's save(); the canvas is immediate, the server's JPEG follows.
 */

const SOURCE_NAMES = {
    title: 'Title',
    subtitle: 'Subtitle',
    author: 'Author',
    series: 'Series',
    edition: 'Edition',
    tagline: 'Tagline',
    custom: 'Text',
};

const WEIGHT_NAMES = { regular: 'Regular', semibold: 'Semibold', bold: 'Bold' };

/** Book fields a bound layer's text field edits (series and edition live elsewhere). */
const EDITABLE = ['title', 'subtitle', 'author', 'tagline'];

const COALESCE_MS = 800;

export default function LayerEditor({ data, onLayers, onText, onReset }) {
    const { cover, editor, palettes } = data;
    const [history, setHistory] = useState(() => ({ past: [], present: cover.layers, future: [] }));
    const [selected, setSelected] = useState(() => cover.layers.items.find((l) => l.visible)?.id ?? null);
    const [safe, setSafe] = useState(true);
    const [guides, setGuides] = useState([]);
    const lastEdit = useRef({ key: null, at: 0 });
    const drag = useRef(null);
    const svg = useRef(null);
    const canvasBox = useRef(null);

    const doc = history.present;
    const items = doc.items;
    const layer = items.find((l) => l.id === selected) ?? null;
    const metrics = useFaceMetrics(editor, facesOf(items));

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

    const W = editor.width;
    const H = editor.height;
    const safeX = editor.safe;
    const safeY = (editor.safe * W) / H;

    const boxOf = (l) =>
        ready(metrics, l) ? layoutLayer(l, textOf(l, cover.texts), W, H, metrics).box : { x: l.x * W, y: l.y * H, width: l.width * W, height: 0.05 * H };

    const clampMove = (l, x, y) => ({
        x: Math.min(Math.max(0, x), 1 - l.width),
        y: Math.min(Math.max(0, y), 1),
    });

    /** Snap a moved box's edges and centre to the centre lines and the safe margin. */
    const snap = (l, x, y, threshold) => {
        const box = boxOf(l);
        const h = box.height / H;
        const found = [];
        const tryAxis = (value, candidates) => {
            for (const [offset, target, guide] of candidates) {
                if (Math.abs(value + offset - target) < threshold) {
                    found.push(guide);

                    return target - offset;
                }
            }

            return value;
        };

        const nx = tryAxis(x, [
            [l.width / 2, 0.5, { axis: 'x', at: 0.5 }],
            [0, safeX, { axis: 'x', at: safeX }],
            [l.width, 1 - safeX, { axis: 'x', at: 1 - safeX }],
        ]);
        const ny = tryAxis(y, [
            [h / 2, 0.5, { axis: 'y', at: 0.5 }],
            [0, safeY, { axis: 'y', at: safeY }],
            [h, 1 - safeY, { axis: 'y', at: 1 - safeY }],
        ]);

        return { ...clampMove(l, nx, ny), guides: found };
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
        const dx = fx - d.fx;
        const dy = fy - d.fy;
        const l = d.start;
        let changes;
        let shown = [];

        if (d.mode === 'move') {
            if (event.altKey) {
                changes = clampMove(l, l.x + dx, l.y + dy);
            } else {
                const snapped = snap(l, l.x + dx, l.y + dy, 8 / d.rect.width);
                shown = snapped.guides;
                changes = { x: snapped.x, y: snapped.y };
            }
        } else if (d.mode === 'left') {
            const right = l.x + l.width;
            const x = Math.min(Math.max(0, l.x + dx), right - 0.05);
            changes = { x, width: right - x };
        } else {
            changes = { width: Math.min(Math.max(0.05, l.width + dx), 1 - l.x) };
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
        replace({ past: [...h.past.slice(-99), d.before], present: h.present, future: [] });
    };

    // --- Keyboard ----------------------------------------------------------

    const nudge = (dx, dy) => {
        if (!layer) return;

        const live = current.current.present.items.find((l) => l.id === layer.id) ?? layer;

        update(layer.id, round(clampMove(live, live.x + dx / W, live.y + dy / H)), 'nudge');
    };

    const removeOrHide = (l) => {
        if (l.source === 'custom') {
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

    // --- Layer actions -------------------------------------------------------

    const titleLayer = items.find((l) => l.source === 'title');

    const add = (source) => {
        const base = titleLayer ?? { font: 'eb-garamond', weight: 'regular', italic: false };
        const ink = palettes[cover.palette]?.ink ?? '#ffffff';
        const fresh = {
            id: `${source}-${Math.random().toString(36).slice(2, 8)}`,
            source,
            text: source === 'custom' ? 'New text' : null,
            visible: true,
            x: 0.15,
            y: 0.45,
            width: 0.7,
            height: null,
            align: 'center',
            font: base.font,
            weight: 'regular',
            italic: false,
            size: source === 'custom' ? 0.03 : 0.035,
            color: cover.artwork ? '#ffffff' : ink,
            opacity: 1,
            uppercase: false,
            tracking: 0,
            leading: 1.2,
            shadow: cover.artwork ? 'soft' : 'none',
            background: null,
        };

        setItems([...items, fresh]);
        setSelected(fresh.id);
    };

    const duplicate = (l) => {
        const copy = {
            ...l,
            id: `custom-${Math.random().toString(36).slice(2, 8)}`,
            source: 'custom',
            text: textOf(l, cover.texts),
            y: Math.min(1, l.y + 0.03),
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

    const missing = editor.sources.filter((s) => s !== 'custom' && !items.some((l) => l.source === s));

    // --- Render ------------------------------------------------------------

    const selectedBox = layer && layer.visible ? boxOf(layer) : null;

    return (
        // One grid: on a phone a single column whose canvas stays pinned at
        // the top while the list and the inspector scroll up under it like
        // a sheet; on a desktop the canvas column beside the inspector.
        <div data-cover-editor className="grid min-h-0 grid-cols-1 gap-3 lg:grid-cols-[380px_minmax(0,1fr)] lg:items-start">
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
                />
            </div>

            <div className="sticky top-0 z-20 flex items-center justify-center gap-2 bg-bg-surface py-1.5 lg:static lg:col-start-1 lg:flex-col lg:justify-start lg:py-0">
                <div
                    ref={canvasBox}
                    tabIndex={0}
                    role="application"
                    aria-roledescription="cover canvas"
                    aria-label={
                        layer
                            ? `Cover canvas. ${nameOf(layer, cover.texts)} selected. Arrow keys move it, Shift with an arrow moves it further, Delete hides it.`
                            : 'Cover canvas. Choose a text layer to move it.'
                    }
                    data-a="cover:canvas"
                    onKeyDown={onCanvasKey}
                    className="relative w-[min(70%,calc(40dvh*0.625))] shrink-0 touch-none select-none rounded-[3px] shadow-[0_2px_14px_rgba(0,0,0,0.22)] lg:w-full lg:max-w-[360px]"
                >
                    <CoverCanvas
                        svgRef={svg}
                        editor={editor}
                        items={items}
                        texts={cover.texts}
                        metrics={metrics}
                        background={cover.background}
                        label="Cover preview"
                        className="block h-auto w-full rounded-[3px] bg-bg-subtle"
                        onPointerMove={onPointerMove}
                        onPointerUp={endDrag}
                        onPointerCancel={endDrag}
                        onPointerDown={(event) => {
                            if (event.target === svg.current || event.target.tagName === 'image') setSelected(null);
                        }}
                    >
                        {safe && (
                            <rect
                                data-a="cover:safe-area"
                                x={safeX * W}
                                y={safeY * H}
                                width={W - 2 * safeX * W}
                                height={H - 2 * safeY * H}
                                fill="none"
                                stroke="#22d3ee"
                                strokeWidth={4}
                                strokeDasharray="18 14"
                                pointerEvents="none"
                            />
                        )}

                        {guides.map((g, i) =>
                            g.axis === 'x' ? (
                                <line key={i} x1={g.at * W} x2={g.at * W} y1={0} y2={H} stroke="#f472b6" strokeWidth={4} pointerEvents="none" />
                            ) : (
                                <line key={i} x1={0} x2={W} y1={g.at * H} y2={g.at * H} stroke="#f472b6" strokeWidth={4} pointerEvents="none" />
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
                                    height={Math.max(box.height, 24)}
                                    fill="transparent"
                                    stroke={l.id === selected ? 'none' : 'rgba(255,255,255,0.0)'}
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
                                    height={Math.max(selectedBox.height, 24)}
                                    fill="none"
                                    stroke="#3b82f6"
                                    strokeWidth={5}
                                    pointerEvents="none"
                                />
                                {['left', 'right'].map((side) => (
                                    <rect
                                        key={side}
                                        data-a={`cover:handle:${side}`}
                                        x={(side === 'left' ? selectedBox.x : selectedBox.x + selectedBox.width) - 22}
                                        y={selectedBox.y + Math.max(selectedBox.height, 24) / 2 - 60}
                                        width={44}
                                        height={120}
                                        rx={10}
                                        fill="#ffffff"
                                        stroke="#3b82f6"
                                        strokeWidth={6}
                                        style={{ cursor: 'ew-resize' }}
                                        onPointerDown={(event) => startDrag(event, layer, side)}
                                    />
                                ))}
                            </g>
                        )}
                    </CoverCanvas>
                </div>

                {layer && <Nudge onNudge={nudge} />}
            </div>

            <div className="flex justify-center lg:col-start-1">
                <div className="flex w-full max-w-[360px] items-end justify-between gap-3">
                    {/* Store lists show covers this small: check the title reads. */}
                    <figure className="flex flex-col items-center gap-1">
                        <CoverCanvas
                            editor={editor}
                            items={items}
                            texts={cover.texts}
                            metrics={metrics}
                            background={cover.background}
                            label="Cover at thumbnail size"
                            data-a="cover:thumbnail"
                            className="block h-[160px] w-[100px] rounded-[2px] shadow-[0_1px_6px_rgba(0,0,0,0.25)]"
                        />
                        <figcaption className="text-[10.5px] text-text-secondary">Thumbnail</figcaption>
                    </figure>

                    <figure className="flex flex-col items-center gap-1">
                        <img
                            src={cover.image}
                            alt="The saved cover, as it downloads"
                            data-a="cover:preview"
                            width={100}
                            height={160}
                            className="h-[160px] w-[100px] rounded-[2px] bg-bg-surface object-cover shadow-[0_1px_6px_rgba(0,0,0,0.25)]"
                        />
                        <figcaption className="text-[10.5px] text-text-secondary">Saved file</figcaption>
                    </figure>

                    <a
                        href={cover.download}
                        data-a="cover:download"
                        className="inline-flex items-center gap-1.5 rounded-control border border-border-input bg-bg-surface px-2.5 py-1.5 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40"
                    >
                        <Icon name="exp" size={13} strokeWidth={2} />
                        Download
                    </a>
                </div>
            </div>

            <div className="min-w-0 space-y-3 lg:col-start-2 lg:row-span-3 lg:row-start-1">
                <LayerList
                    items={items}
                    texts={cover.texts}
                    selected={selected}
                    onSelect={setSelected}
                    onToggle={(l) => update(l.id, { visible: !l.visible })}
                    onMove={move}
                    onReorder={reorder}
                />

                {layer ? (
                    <Inspector
                        key={layer.id}
                        layer={layer}
                        data={data}
                        metrics={metrics}
                        onChange={(changes, key) => update(layer.id, changes, key)}
                        onText={onText}
                        onDuplicate={() => duplicate(layer)}
                        onDelete={() => removeOrHide(layer)}
                        onForward={() => move(layer, 1)}
                        onBack={() => move(layer, -1)}
                        onClose={() => setSelected(null)}
                    />
                ) : (
                    <p className="rounded-tile border border-dashed border-border-input p-3 text-[12px] text-text-secondary">
                        Choose a text on the cover or in the list to change it. Drag it to move it; drag the
                        side handles to make its box wider or narrower.
                    </p>
                )}
            </div>
        </div>
    );
}

/** Positions to 1/10000 of the cover: plenty, and short in storage. */
function round(changes) {
    const out = {};

    for (const [k, v] of Object.entries(changes)) {
        out[k] = typeof v === 'number' ? Math.round(v * 10000) / 10000 : v;
    }

    return out;
}

function nameOf(layer, texts) {
    if (layer.source !== 'custom') return SOURCE_NAMES[layer.source];

    const text = (layer.text ?? '').trim();

    if (text === '') return layer.background ? 'Shape' : 'Empty text';

    return text.length > 24 ? `${text.slice(0, 24)}…` : text;
}

function Toolbar({ canUndo, canRedo, onUndo, onRedo, safe, onSafe, onAdd, missing, onReset }) {
    const button =
        'inline-flex min-h-[36px] items-center gap-1 rounded-control border border-border-input bg-bg-surface px-2.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-40';

    return (
        <div role="toolbar" aria-label="Cover text" className="flex w-full max-w-[380px] flex-wrap items-center gap-1.5">
            <button type="button" onClick={onUndo} disabled={!canUndo} data-a="cover:undo" aria-label="Undo" title="Undo (Ctrl+Z)" className={button}>
                <Icon name="reset" size={13} strokeWidth={2} /> Undo
            </button>
            <button type="button" onClick={onRedo} disabled={!canRedo} data-a="cover:redo" aria-label="Redo" title="Redo (Shift+Ctrl+Z)" className={button}>
                Redo
            </button>
            <button type="button" onClick={() => onAdd('custom')} data-a="cover:add-text" className={button}>
                <Icon name="plus" size={13} strokeWidth={2} /> Add text
            </button>

            {missing.length > 0 && (
                <label className="inline-flex items-center">
                    <span className="sr-only">Add a book field</span>
                    <select
                        value=""
                        data-a="cover:add-field"
                        onChange={(event) => event.target.value && onAdd(event.target.value)}
                        className="min-h-[36px] rounded-control border border-border-input bg-bg-surface px-2 text-[12px]"
                    >
                        <option value="">Add field…</option>
                        {missing.map((s) => (
                            <option key={s} value={s}>
                                {SOURCE_NAMES[s]}
                            </option>
                        ))}
                    </select>
                </label>
            )}

            <button type="button" onClick={onSafe} aria-pressed={safe} data-a="cover:safe" className={button}>
                Safe area
            </button>
            <button type="button" onClick={onReset} data-a="cover:reset-layout" className={`${button} ml-auto`}>
                Reset to layout
            </button>
        </div>
    );
}

function Nudge({ onNudge }) {
    const button =
        'flex h-[40px] w-[40px] items-center justify-center rounded-control border border-border-input bg-bg-surface text-[15px] text-text-body transition hover:border-brand-primary/40';

    return (
        // A cross of four on a phone, beside the canvas; a row under it on a desktop.
        <div role="group" aria-label="Move the selected text" className="grid grid-cols-3 gap-1 lg:flex lg:gap-1.5">
            <button type="button" aria-label="Move up" data-a="cover:nudge:up" onClick={() => onNudge(0, -10)} className={`${button} col-start-2`}>↑</button>
            <button type="button" aria-label="Move left" data-a="cover:nudge:left" onClick={() => onNudge(-10, 0)} className={`${button} col-start-1 row-start-2`}>←</button>
            <button type="button" aria-label="Move right" data-a="cover:nudge:right" onClick={() => onNudge(10, 0)} className={`${button} col-start-3 row-start-2`}>→</button>
            <button type="button" aria-label="Move down" data-a="cover:nudge:down" onClick={() => onNudge(0, 10)} className={`${button} col-start-2 row-start-3`}>↓</button>
        </div>
    );
}

function LayerList({ items, texts, selected, onSelect, onToggle, onMove, onReorder }) {
    const dragging = useRef(null);

    // Listed front first, as layer lists are: the last drawn is on top.
    const listed = [...items].reverse();

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface p-2.5">
            <h3 className="mb-1.5 px-1 text-[12.5px] font-semibold text-text-primary">Layers</h3>
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
                            <Icon name="grip" size={14} strokeWidth={2} />
                        </span>
                        <button
                            type="button"
                            aria-pressed={l.visible}
                            aria-label={`${l.visible ? 'Hide' : 'Show'} ${nameOf(l, texts)}`}
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
                            {nameOf(l, texts)}
                        </button>
                        <button
                            type="button"
                            aria-label={`Bring ${nameOf(l, texts)} forward`}
                            disabled={i === 0}
                            onClick={() => onMove(l, 1)}
                            className="h-[30px] w-[28px] rounded-control text-[13px] text-text-secondary disabled:opacity-30"
                        >
                            ▲
                        </button>
                        <button
                            type="button"
                            aria-label={`Send ${nameOf(l, texts)} back`}
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

function Inspector({ layer, data, metrics, onChange, onText, onDuplicate, onDelete, onForward, onBack, onClose }) {
    const { cover, editor, palettes } = data;
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

        return [...new Set(['#ffffff', '#000000', palette.ink, palette.from, palette.to, '#f5efe0', '#c9a227', '#b91c1c'].map((c) => c.toLowerCase()))];
    }, [palettes, cover.palette]);

    const name = SOURCE_NAMES[layer.source];
    const sizePx = Math.round(layer.size * editor.height);

    return (
        <section
            aria-label="Selected text"
            data-a="cover:inspector"
            className="space-y-3 rounded-tile border border-border-default bg-bg-surface p-3 max-lg:rounded-t-[16px] max-lg:shadow-[0_-6px_20px_rgba(0,0,0,0.12)]"
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">{name === 'Text' ? 'Text' : `${name} text`}</h3>
                <div className="flex items-center gap-1">
                    <Toggle label="Visible" checked={layer.visible} onChange={(v) => onChange({ visible: v })} data-a="cover:visible" />
                    <button type="button" onClick={onClose} aria-label="Close the inspector" className="flex h-[32px] w-[32px] items-center justify-center rounded-control text-text-secondary lg:hidden">
                        <Icon name="x" size={14} strokeWidth={2} />
                    </button>
                </div>
            </div>

            {layer.source === 'custom' ? (
                <Field label="Text">
                    <textarea
                        rows={2}
                        value={layer.text ?? ''}
                        data-a="cover:text"
                        onChange={(event) => onChange({ text: event.target.value }, 'text')}
                        className="w-full resize-y rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                    />
                </Field>
            ) : EDITABLE.includes(layer.source) ? (
                <Field label={`${name} (on the cover)`}>
                    <input
                        value={cover.texts[layer.source] ?? ''}
                        data-a={`cover:${layer.source}`}
                        onChange={(event) => onText(layer.source, event.target.value)}
                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                    />
                </Field>
            ) : (
                <p className="text-[11.5px] text-text-secondary">
                    {cover.texts[layer.source]
                        ? `Shows “${cover.texts[layer.source]}”, from the book's details.`
                        : `The book has no ${name.toLowerCase()} yet; set it in the book's details.`}
                </p>
            )}

            <fieldset>
                <legend className="mb-1 text-[11.5px] font-medium text-text-secondary">Typeface</legend>
                <div role="radiogroup" aria-label="Typeface" className="grid grid-cols-2 gap-1 sm:grid-cols-3">
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
                <Field label="Weight">
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
                                {WEIGHT_NAMES[w]}
                            </option>
                        ))}
                    </select>
                </Field>
                <div className="flex items-end gap-1.5 pb-0.5">
                    <Toggle label="Italic" checked={layer.italic} disabled={layer.italic ? !hasUpright : !hasItalic} onChange={(v) => onChange({ italic: v })} data-a="cover:italic" />
                    <Toggle label="Capitals" checked={layer.uppercase} onChange={(v) => onChange({ uppercase: v })} data-a="cover:uppercase" />
                </div>
            </div>

            <Slider
                label="Size"
                unit="px"
                min={15}
                max={600}
                step={1}
                value={sizePx}
                data-a="cover:size"
                onChange={(v) => onChange({ size: Math.round((Math.min(600, Math.max(15, v)) / editor.height) * 100000) / 100000 }, 'size')}
            />

            <fieldset>
                <legend className="mb-1 text-[11.5px] font-medium text-text-secondary">Colour</legend>
                <div className="flex flex-wrap items-center gap-1.5">
                    {swatches.map((c) => (
                        <button
                            key={c}
                            type="button"
                            aria-label={`Colour ${c}`}
                            aria-pressed={layer.color === c}
                            onClick={() => onChange({ color: c })}
                            className={`h-[30px] w-[30px] rounded-full border-2 ${layer.color === c ? 'border-brand-primary' : 'border-border-input'}`}
                            style={{ background: c }}
                        />
                    ))}
                    <label className="inline-flex items-center gap-1">
                        <span className="sr-only">Pick a colour</span>
                        <input type="color" value={layer.color} data-a="cover:color" onChange={(event) => onChange({ color: event.target.value.toLowerCase() }, 'color')} className="h-[30px] w-[36px] cursor-pointer rounded border border-border-input bg-transparent" />
                    </label>
                    <HexInput value={layer.color} onChange={(c) => onChange({ color: c })} label="Colour hex" data-a="cover:color-hex" />
                </div>
            </fieldset>

            <Slider label="Opacity" unit="%" min={0} max={100} step={1} value={Math.round(layer.opacity * 100)} onChange={(v) => onChange({ opacity: v / 100 }, 'opacity')} data-a="cover:opacity" />

            <div role="group" aria-label="Alignment" className="flex gap-1">
                {['left', 'center', 'right'].map((a) => (
                    <button
                        key={a}
                        type="button"
                        aria-pressed={layer.align === a}
                        data-a={`cover:align:${a}`}
                        onClick={() => onChange({ align: a })}
                        className={`min-h-[34px] flex-1 rounded-control border text-[12px] capitalize ${layer.align === a ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body'}`}
                    >
                        {a === 'center' ? 'Centre' : a}
                    </button>
                ))}
            </div>

            <Slider label="Letter spacing" unit="em" min={-0.05} max={0.5} step={0.01} value={layer.tracking} onChange={(v) => onChange({ tracking: v }, 'tracking')} data-a="cover:tracking" />
            <Slider label="Line height" unit="×" min={0.7} max={2.5} step={0.01} value={layer.leading} onChange={(v) => onChange({ leading: v }, 'leading')} data-a="cover:leading" />

            <div role="group" aria-label="Shadow" className="flex items-center gap-1">
                <span className="mr-1 text-[11.5px] font-medium text-text-secondary">Shadow</span>
                {['none', 'soft', 'strong'].map((s) => (
                    <button
                        key={s}
                        type="button"
                        aria-pressed={layer.shadow === s}
                        data-a={`cover:shadow:${s}`}
                        onClick={() => onChange({ shadow: s })}
                        className={`min-h-[32px] flex-1 rounded-control border text-[12px] capitalize ${layer.shadow === s ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body'}`}
                    >
                        {s}
                    </button>
                ))}
            </div>

            <div className="rounded-control border border-border-default p-2">
                <Toggle
                    label="Background panel"
                    checked={Boolean(layer.background)}
                    data-a="cover:panel"
                    onChange={(v) => onChange({ background: v ? { color: '#000000', opacity: 0.5, padding: 0.02 } : null })}
                />
                {layer.background && (
                    <div className="mt-2 space-y-2">
                        <div className="flex items-center gap-2">
                            <label className="inline-flex items-center gap-1 text-[11.5px] text-text-secondary">
                                Panel colour
                                <input type="color" value={layer.background.color} onChange={(event) => onChange({ background: { ...layer.background, color: event.target.value.toLowerCase() } }, 'panel-color')} className="h-[28px] w-[34px] rounded border border-border-input" />
                            </label>
                        </div>
                        <Slider label="Panel opacity" unit="%" min={0} max={100} step={1} value={Math.round(layer.background.opacity * 100)} onChange={(v) => onChange({ background: { ...layer.background, opacity: v / 100 } }, 'panel-opacity')} />
                        <Slider label="Panel padding" unit="%" min={0} max={20} step={0.5} value={Math.round(layer.background.padding * 1000) / 10} onChange={(v) => onChange({ background: { ...layer.background, padding: v / 100 } }, 'panel-padding')} />
                    </div>
                )}
            </div>

            <div className="flex flex-wrap gap-1.5 border-t border-border-default pt-2.5">
                <SmallButton onClick={onDuplicate} data-a="cover:duplicate">Duplicate</SmallButton>
                <SmallButton onClick={onForward} data-a="cover:forward">Bring forward</SmallButton>
                <SmallButton onClick={onBack} data-a="cover:back">Send back</SmallButton>
                <SmallButton onClick={onDelete} data-a="cover:delete" danger>
                    {layer.source === 'custom' ? 'Delete' : 'Hide'}
                </SmallButton>
            </div>
        </section>
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
    const shown = Number.isInteger(step) ? value : Number(value).toFixed(2);

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
