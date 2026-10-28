import { useRef, useState } from 'react';

/**
 * A handle between two studio panels: drag it, or focus it and use the
 * arrow keys, to resize the panel it belongs to.
 *
 * The WAI-ARIA window splitter: role="separator" with its value, focusable,
 * Arrow keys step 16px (Shift 64), Home/End go to the bounds, Enter
 * collapses the panel to its icon rail, and a double-click restores the
 * default size.
 *
 * `edge` is which side of the panel the handle is on, so dragging toward
 * the panel always shrinks it: 'end' for a panel on the left, 'start' for
 * one on the right, 'top' for the bottom row. The size moves live while
 * dragging (`onLive`, no save) and is committed once, on release.
 */
export default function Splitter({
    label,
    controls,
    value,
    min,
    max,
    defaultValue,
    edge = 'end',
    onLive,
    onCommit,
    onCollapse = null,
    // The largest size the room allows right now, under `max`.
    limit = null,
    className = '',
    // Its place in a flex row laid out with CSS order (the studio swaps
    // its side panels that way).
    order = undefined,
    'data-a': dataA,
}) {
    const [dragging, setDragging] = useState(false);
    const start = useRef(null);
    const vertical = edge !== 'top';
    const ceiling = Math.max(min, Math.min(max, limit ?? max));
    const clamp = (n) => Math.round(Math.min(ceiling, Math.max(min, n)));

    const onPointerDown = (event) => {
        if (event.button !== 0) return;

        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);
        start.current = { at: vertical ? event.clientX : event.clientY, value, last: value };
        setDragging(true);
    };

    const onPointerMove = (event) => {
        if (!start.current) return;

        const now = vertical ? event.clientX : event.clientY;
        let delta = now - start.current.at;

        // Toward the panel shrinks it.
        if (edge === 'start' || edge === 'top') delta = -delta;

        const next = clamp(start.current.value + delta);

        if (next !== start.current.last) {
            start.current.last = next;
            onLive?.(next);
        }
    };

    const onPointerUp = (event) => {
        if (!start.current) return;

        event.currentTarget.releasePointerCapture?.(event.pointerId);
        const { last, value: was } = start.current;
        start.current = null;
        setDragging(false);

        if (last !== was) onCommit(last);
    };

    const onKeyDown = (event) => {
        const step = event.shiftKey ? 64 : 16;
        const grow = vertical ? (edge === 'end' ? 'ArrowRight' : 'ArrowLeft') : 'ArrowUp';
        const shrink = vertical ? (edge === 'end' ? 'ArrowLeft' : 'ArrowRight') : 'ArrowDown';
        let next = null;

        if (event.key === grow) next = value + step;
        else if (event.key === shrink) next = value - step;
        else if (event.key === 'Home') next = min;
        else if (event.key === 'End') next = ceiling;
        else if (event.key === 'Enter' && onCollapse) {
            event.preventDefault();
            onCollapse();

            return;
        }

        if (next === null) return;

        event.preventDefault();
        next = clamp(next);

        if (next !== value) onCommit(next);
    };

    return (
        <div
            role="separator"
            tabIndex={0}
            aria-label={label}
            aria-controls={controls}
            aria-orientation={vertical ? 'vertical' : 'horizontal'}
            aria-valuenow={value}
            aria-valuemin={min}
            aria-valuemax={ceiling}
            aria-keyshortcuts={onCollapse ? 'Enter' : undefined}
            title={`${label} — drag, or use the arrow keys; double-click to reset${onCollapse ? '; Enter collapses' : ''}`}
            data-a={dataA}
            data-dragging={dragging ? 'true' : undefined}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
            onDoubleClick={() => onCommit(clamp(defaultValue))}
            onKeyDown={onKeyDown}
            style={order === undefined ? undefined : { order }}
            className={`group relative z-10 flex shrink-0 touch-none select-none items-center justify-center ${
                vertical ? 'w-[10px] cursor-col-resize self-stretch' : 'h-[11px] cursor-row-resize'
            } ${className}`}
        >
            <span
                aria-hidden="true"
                className={`rounded-full transition-colors ${
                    vertical ? 'h-10 w-[3px]' : 'h-[3px] w-10'
                } ${
                    dragging
                        ? 'bg-brand-fill'
                        : 'bg-transparent group-hover:bg-border-default group-focus-visible:bg-brand-fill'
                }`}
            />
        </div>
    );
}
