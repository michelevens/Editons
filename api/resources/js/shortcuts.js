import { useSyncExternalStore } from 'react';
import axios from 'axios';
import registry from './prefs.json';

/**
 * Every keyboard shortcut in the app, and the author's own bindings for
 * them (ADR — Configurable shortcuts and mobile tabs).
 *
 * The list is prefs.json, which the server reads too (App\Workspace\
 * Preferences), so what the sheet offers and what the server accepts are
 * the same thing. Handlers ask `matches(id, event)` rather than testing keys
 * themselves; a remapped shortcut then works everywhere at once, and its old
 * keys stop working.
 *
 * A binding is "Mod+Alt+Shift+<key>" (modifiers in that order, each
 * optional). Mod is Ctrl, or ⌘ on a Mac. How the key is matched is chosen
 * per kind of key, for keyboards that are not American:
 *
 * - Letters (KeyA–KeyZ) match the letter the key types (`event.key`), so
 *   on a French or Haitian AZERTY board "A" is the key marked A, where
 *   AZERTY has it, not the one in QWERTY's A position (which types Q). When
 *   the key types no Latin letter (Option on a Mac turns F into ƒ, a
 *   Cyrillic layout) the physical position (`event.code`) is used instead.
 * - Digits and punctuation match the physical key (`event.code`): on AZERTY
 *   the digits need Shift and AltGr+8 types "\" while reporting Ctrl+Alt
 *   (ADR-0039), so matching what was typed would fire shortcuts on text.
 * - Named keys (arrows, F1–F12, Escape...) match `event.key`.
 *
 * Modifiers must match exactly, so Ctrl+Shift+S (strikethrough) is never
 * also Ctrl+S, and AltGr (Ctrl+Alt) never passes for Ctrl.
 */

export const SCOPES = registry.scopes;
export const SHORTCUT_LIST = registry.shortcuts;
export const RESERVED = registry.reserved;
export const PREFS_REGISTRY = registry;

const BY_ID = Object.fromEntries(registry.shortcuts.map((s) => [s.id, s]));

export const isMac = () => typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '');

// --- The author's preferences: a small store shared by every handler -----

const EMPTY = { shortcuts: {}, mobileTabs: {}, reviewDeck: { swipe: 'normal' } };
let state = EMPTY;
let seeded = null;
const listeners = new Set();

function normalise(prefs) {
    return {
        shortcuts: { ...(prefs?.shortcuts ?? {}) },
        mobileTabs: { ...(prefs?.mobileTabs ?? {}) },
        reviewDeck: { swipe: prefs?.reviewDeck?.swipe ?? 'normal' },
    };
}

function emit() {
    listeners.forEach((listener) => listener());
}

/**
 * The server's copy, from the shared `prefs` prop. Called while the page
 * renders, so it only sets the store (no listeners told): everything below
 * is rendering with the new props anyway.
 */
export function seedPrefs(prefs) {
    if (prefs === seeded) return;

    seeded = prefs;
    state = normalise(prefs);
}

export function getPrefs() {
    return state;
}

export function usePrefs() {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => state,
    );
}

/**
 * Save a change: applied at once, then confirmed (or undone) by the server.
 * Rejects with the server's errors object on a 422.
 */
export async function savePrefs(changes) {
    const before = state;
    const next = normalise(state);

    for (const [section, value] of Object.entries(changes)) {
        if (section === 'reviewDeck') {
            next.reviewDeck = { ...next.reviewDeck, ...value };
            continue;
        }

        for (const [key, v] of Object.entries(value)) {
            if (v === null) delete next[section][key];
            else next[section][key] = v;
        }
    }

    state = next;
    emit();

    try {
        const { data } = await axios.put(route('preferences.update'), changes);
        state = normalise(data);
        emit();

        return state;
    } catch (error) {
        state = before;
        emit();
        throw error?.response?.data?.errors ?? { _: 'Not saved. Try again.' };
    }
}

export async function resetPrefs(section) {
    const { data } = await axios.delete(route('preferences.destroy', section));
    state = normalise(data);
    emit();

    return state;
}

// --- Bindings -------------------------------------------------------------

export function shortcut(id) {
    return BY_ID[id];
}

/** The keys a shortcut answers to now: the author's, or the defaults. */
export function bindingsOf(id, prefs = state) {
    const own = prefs.shortcuts?.[id];

    return own ? [own] : (BY_ID[id]?.keys ?? []);
}

function parse(binding) {
    const parts = binding.split('+');
    // "?" and "+" style literals are a single character key.
    const key = parts.pop() || '+';

    return { mod: parts.includes('Mod'), alt: parts.includes('Alt'), shift: parts.includes('Shift'), key };
}

function matchesBinding(binding, event) {
    const want = parse(binding);

    // A literal character ("?"): whatever modifiers type it.
    if (want.key.length === 1) {
        return event.key === want.key && !event.ctrlKey && !event.metaKey && !event.altKey;
    }

    if (want.mod !== (event.ctrlKey || event.metaKey) || want.alt !== event.altKey || want.shift !== event.shiftKey) {
        return false;
    }

    if (/^Key[A-Z]$/.test(want.key)) {
        const typed = event.key ?? '';

        return /^[a-z]$/i.test(typed) ? typed.toUpperCase() === want.key.slice(3) : event.code === want.key;
    }

    if (/^(Digit\d|Backslash|IntlBackslash|Slash|Period|Comma|Semicolon|Quote|BracketLeft|BracketRight|Minus|Equal|Backquote)$/.test(want.key)) {
        return event.code === want.key;
    }

    if (want.key === 'Space') return event.code === 'Space';

    return event.key === want.key;
}

/** Whether this key press is the shortcut `id`, as the author has it now. */
export function matches(id, event) {
    return bindingsOf(id).some((binding) => matchesBinding(binding, event));
}

/** Whether focus is somewhere that takes typing. */
export function isTyping(event) {
    const el = event?.target;

    return Boolean(el?.closest?.('input, textarea, select, [contenteditable="true"], [contenteditable=""]'));
}

const KEY_LABELS = {
    Backslash: '\\',
    IntlBackslash: '\\',
    Slash: '/',
    Period: '.',
    Comma: ',',
    Semicolon: ';',
    Quote: "'",
    BracketLeft: '[',
    BracketRight: ']',
    Minus: '-',
    Equal: '=',
    Backquote: '`',
    ArrowUp: '↑',
    ArrowDown: '↓',
    ArrowLeft: '←',
    ArrowRight: '→',
    Escape: 'Esc',
    Backspace: '⌫',
    Delete: 'Del',
    PageUp: 'Page Up',
    PageDown: 'Page Down',
};

function keyLabel(key) {
    if (/^Key[A-Z]$/.test(key)) return key.slice(3);
    if (/^Digit\d$/.test(key)) return key.slice(5);

    return KEY_LABELS[key] ?? key;
}

/** "Ctrl+Shift+F" on Windows and Linux, "⌘⇧F" on a Mac. */
export function formatBinding(binding, mac = isMac()) {
    const { mod, alt, shift, key } = parse(binding);

    if (key.length === 1) return key;

    if (mac) {
        return `${mod ? '⌘' : ''}${alt ? '⌥' : ''}${shift ? '⇧' : ''}${keyLabel(key)}`;
    }

    return [mod && 'Ctrl', alt && 'Alt', shift && 'Shift', keyLabel(key)].filter(Boolean).join('+');
}

/** The label for a shortcut's first binding, for buttons and menus. */
export function shortcutLabel(id, prefs = state) {
    const [first] = bindingsOf(id, prefs);

    return first ? formatBinding(first) : '';
}

/** For aria-keyshortcuts: "Control+K Meta+K". */
export function ariaKeys(id, prefs = state) {
    return bindingsOf(id, prefs)
        .flatMap((binding) => {
            const { mod, alt, shift, key } = parse(binding);
            const name = /^(Key[A-Z]|Digit\d)$/.test(key) ? keyLabel(key) : key;
            const tail = [alt && 'Alt', shift && 'Shift', name]
                .filter(Boolean)
                .join('+');

            return mod ? [`Control+${tail}`, `Meta+${tail}`] : [tail];
        })
        .join(' ');
}

/**
 * The binding a key press would record, or null while only modifiers are
 * held. Letters are recorded as the letter typed (see the top of the file),
 * everything else by its physical key.
 */
export function bindingFromEvent(event) {
    if (['Control', 'Meta', 'Alt', 'Shift', 'AltGraph', 'CapsLock', 'OS'].includes(event.key)) return null;

    let key;

    if (/^[a-z]$/i.test(event.key ?? '')) key = `Key${event.key.toUpperCase()}`;
    else if (/^(Key[A-Z]|Digit\d|Backslash|IntlBackslash|Slash|Period|Comma|Semicolon|Quote|BracketLeft|BracketRight|Minus|Equal|Backquote|Space)$/.test(event.code)) key = event.code;
    else if (/^(F([1-9]|1[0-2])|Arrow(Up|Down|Left|Right)|Enter|Escape|Tab|Backspace|Delete|Home|End|PageUp|PageDown|Insert)$/.test(event.key)) key = event.key;
    else return null;

    return [(event.ctrlKey || event.metaKey) && 'Mod', event.altKey && 'Alt', event.shiftKey && 'Shift', key].filter(Boolean).join('+');
}

function hasModifier(binding) {
    return binding.includes('Mod+') || binding.includes('Alt+') || /(^|\+)F([1-9]|1[0-2])$/.test(binding);
}

/**
 * Why `binding` cannot be `id`'s, or null when it can. The same rules as
 * the server's (App\Workspace\Preferences), which has the last word.
 */
export function conflictFor(id, binding, prefs = state) {
    const entry = BY_ID[id];

    if (!entry) return 'Unknown shortcut.';
    if (RESERVED[binding]) return `The browser keeps ${formatBinding(binding)}: it ${RESERVED[binding]}.`;
    if (SCOPES[entry.scope]?.typing && !hasModifier(binding)) {
        return 'Here a shortcut needs Ctrl (⌘) or Alt, or it would fire while you type.';
    }

    for (const other of registry.shortcuts) {
        if (other.id === id) continue;

        const meets = other.scope === entry.scope || other.scope === 'global' || entry.scope === 'global';

        if (meets && bindingsOf(other.id, prefs).includes(binding)) {
            return `${formatBinding(binding)} is already “${other.description}”.`;
        }
    }

    return null;
}

/** Open the shortcut sheet from a menu or the command palette. */
export function openShortcutSheet() {
    window.dispatchEvent(new CustomEvent('shortcuts:open'));
}
