import { useEffect, useState } from 'react';

/**
 * How much of the screen the on-screen keyboard covers, in CSS pixels, or 0.
 *
 * iOS Safari does not resize the page for the keyboard: it shrinks the
 * visual viewport and slides it over the layout, so anything fixed to the
 * bottom of the page sits behind the keys. The gap between the layout
 * viewport and the visual one is the keyboard. Android Chrome, told
 * `interactive-widget=resizes-content` (app.blade.php), resizes the page
 * instead, and this reads 0 there — the page's own bottom is already the top
 * of the keyboard.
 *
 * Only on a coarse pointer: a desktop browser zoomed in shrinks the visual
 * viewport too, and that is not a keyboard.
 */
export function useKeyboardInset() {
    const [inset, setInset] = useState(0);

    useEffect(() => {
        const viewport = window.visualViewport;

        if (!viewport) return undefined;

        const coarse = window.matchMedia('(pointer: coarse)');

        const update = () => {
            const covered = window.innerHeight - viewport.height - viewport.offsetTop;

            // Under 120px is browser chrome moving, not a keyboard.
            setInset(coarse.matches && covered > 120 ? Math.round(covered) : 0);
        };

        viewport.addEventListener('resize', update);
        viewport.addEventListener('scroll', update);
        update();

        return () => {
            viewport.removeEventListener('resize', update);
            viewport.removeEventListener('scroll', update);
        };
    }, []);

    return inset;
}

/**
 * Whether something that takes typing has focus on a touch screen: the
 * keyboard is (or is about to be) up. The tab bar steps aside while it is,
 * so the keyboard, the formatting bar and the text are all that is left.
 */
export function useTyping() {
    const [typing, setTyping] = useState(false);

    useEffect(() => {
        const coarse = window.matchMedia('(pointer: coarse)');
        const takesTyping = (el) =>
            el?.isContentEditable ||
            el?.tagName === 'TEXTAREA' ||
            (el?.tagName === 'INPUT' && !['checkbox', 'radio', 'range', 'button', 'submit', 'file'].includes(el.type));

        let timer = null;
        const check = () => {
            clearTimeout(timer);
            // After focus has settled: moving from one field to the next
            // blurs one before focusing the other, which would flash the bar.
            timer = setTimeout(() => setTyping(coarse.matches && takesTyping(document.activeElement)), 60);
        };

        document.addEventListener('focusin', check);
        document.addEventListener('focusout', check);

        return () => {
            clearTimeout(timer);
            document.removeEventListener('focusin', check);
            document.removeEventListener('focusout', check);
        };
    }, []);

    return typing;
}

/** navigator.onLine, kept current. */
export function useOnline() {
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

    useEffect(() => {
        const on = () => setOnline(true);
        const off = () => setOnline(false);

        window.addEventListener('online', on);
        window.addEventListener('offline', off);

        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
    }, []);

    return online;
}
