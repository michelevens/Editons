import { useEffect, useRef, useState } from 'react';

/**
 * The one accessible modal.
 *
 * Every dialog in the studio — picture, chart, equation, footnote,
 * cross-reference, import, the search palette — and the phone drawers go
 * through this, so they all behave the same way for a keyboard:
 *
 *  - focus moves in when it opens: to the element marked autoFocus or
 *    data-autofocus, else the first control, else the panel itself;
 *  - Tab and Shift+Tab stay inside it;
 *  - Escape closes it wherever focus is, not only while it is inside (the
 *    old handlers sat on the dialog's own div, so Escape did nothing once
 *    focus had wandered into the page behind);
 *  - focus goes back to whatever opened it, unless the dialog's own action
 *    already put it somewhere on purpose (inserting a picture focuses the
 *    manuscript, and that should win).
 *
 * Only the topmost open dialog answers Escape and traps Tab, so a dialog
 * opened from a drawer closes on its own.
 *
 * `variant="drawer"` slides in from a side instead of centring; `side` picks
 * which. The scrim is a plain click target (aria-hidden): the dialog's own
 * close control is the accessible way out.
 */

const stack = [];

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
    '[contenteditable="true"]',
].join(',');

export function focusables(root) {
    return [...root.querySelectorAll(FOCUSABLE)].filter(
        (el) => el.getClientRects().length > 0 && !el.closest('[inert]'),
    );
}

/**
 * Focus the element that opened a dialog. A control that was re-rendered
 * while the dialog was open (the editor toolbar redraws its buttons) is a new
 * node by then, so it is found again by its data-a or id.
 */
function restore(opener) {
    if (!opener || opener === document.body) return;

    let target = opener;

    if (!target.isConnected) {
        const key = opener.getAttribute?.('data-a');
        const id = opener.id;

        target = (key && document.querySelector(`[data-a="${CSS.escape(key)}"]`))
            || (id && document.getElementById(id))
            || null;
    }

    target?.focus?.({ preventScroll: true });
}

/**
 * The behaviour on its own, for a container that is laid out by its caller.
 * Returns the ref to put on the dialog element.
 */
export function useModalFocus(onClose, { active = true } = {}) {
    const panel = useRef(null);
    const close = useRef(onClose);
    close.current = onClose;

    // Read during the first render: autoFocus inside the dialog runs during
    // the commit, before any effect, so by the time an effect could ask,
    // focus has already left the button that opened it.
    const [opener] = useState(() =>
        typeof document === 'undefined' ? null : document.activeElement,
    );

    useEffect(() => {
        if (!active) return undefined;

        const node = panel.current;
        const entry = { node };
        stack.push(entry);

        if (node && !node.contains(document.activeElement)) {
            const marked = node.querySelector('[data-autofocus]');
            const first = marked ?? focusables(node)[0];

            (first ?? node).focus({ preventScroll: true });
        }

        const onKey = (event) => {
            if (stack[stack.length - 1] !== entry || !node) return;

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close.current?.();

                return;
            }

            if (event.key !== 'Tab') return;

            const items = focusables(node);

            if (items.length === 0) {
                event.preventDefault();
                node.focus();

                return;
            }

            const first = items[0];
            const last = items[items.length - 1];
            const inside = node.contains(document.activeElement);

            if (event.shiftKey && (document.activeElement === first || !inside)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
                event.preventDefault();
                first.focus();
            }
        };

        // Capture, so Escape reaches the dialog before the editor or a
        // menu underneath can act on it.
        document.addEventListener('keydown', onKey, true);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            stack.splice(stack.indexOf(entry), 1);

            // Only when focus would otherwise be lost: still inside the
            // dialog that is going away, or dropped on the body.
            const now = document.activeElement;
            const lost = !now || now === document.body || node?.contains(now);

            if (lost) restore(opener);
        };
    }, [active]);

    return panel;
}

export default function Dialog({
    label,
    labelledBy,
    onClose,
    children,
    className = '',
    variant = 'modal',
    side = 'left',
    align = 'center',
    overlayClassName = '',
    ...rest
}) {
    const panel = useModalFocus(onClose);

    const overlay =
        variant === 'drawer'
            ? `fixed inset-0 z-40 flex ${side === 'right' ? 'justify-end' : ''}`
            : `fixed inset-0 z-50 flex justify-center bg-black/30 p-4 ${
                  align === 'top' ? 'items-start pt-[12vh]' : 'items-center max-sm:items-end max-sm:p-0 max-sm:pt-8'
              }`;

    // On a phone a centred modal is a bottom sheet: full width, against the
    // edge the thumb is on, and scrollable when the keyboard takes half the
    // screen. Callers size their dialogs for a desktop (w-[480px] and the
    // like), so the sheet's width has to win over theirs.
    const sheet =
        variant !== 'drawer' && align !== 'top'
            ? 'max-sm:!w-full max-sm:!max-w-none max-sm:max-h-[92dvh] max-sm:overflow-y-auto max-sm:!rounded-b-none'
            : '';

    return (
        <div className={`${overlay} ${overlayClassName}`}>
            {variant === 'drawer' && (
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-black/25"
                    onMouseDown={onClose}
                />
            )}
            {variant !== 'drawer' && (
                <div
                    aria-hidden="true"
                    className="absolute inset-0"
                    onMouseDown={onClose}
                />
            )}

            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-label={labelledBy ? undefined : label}
                aria-labelledby={labelledBy}
                tabIndex={-1}
                className={`relative outline-none ${className} ${sheet}`}
                {...rest}
            >
                {children}
            </div>
        </div>
    );
}

/** The visible close control drawers and dialogs share. */
export function CloseButton({ onClick, label = 'Close', className = '' }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            data-a="dialog:close"
            className={`grid h-8 w-8 shrink-0 place-items-center rounded-control text-text-secondary transition hover:bg-bg-subtle hover:text-text-primary ${className}`}
        >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" />
            </svg>
        </button>
    );
}
