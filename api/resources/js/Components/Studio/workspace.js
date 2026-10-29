import { useCallback, useEffect, useRef, useState } from 'react';
import { ariaKeys, matches, shortcutLabel as registryLabel } from '../../shortcuts';

/**
 * The writing workspace: which studio panels show, their sizes and sides,
 * and how the canvas reads (ADR — customisable writing workspace).
 *
 * The server owns the layout (App\Workspace\WorkspaceSchema validates every
 * key) and hands it to the studio on first paint. This keeps a copy in the
 * browser only for one case: a change made while the save had not yet been
 * confirmed (offline, a closed tab), which is sent again on the next visit
 * instead of being lost. Saving never blocks anything — it is debounced and
 * fire-and-forget, so dragging a splitter or toggling a panel while the
 * author types costs the editor nothing.
 */

/** Mirrors WorkspaceSchema::defaults(); the server's copy wins when given. */
export const DEFAULT_WORKSPACE = {
    version: 1,
    panels: {
        topNav: true,
        sidebar: true,
        credits: true,
        outline: true,
        right: true,
        bottom: true,
        toolbar: true,
        statusBar: true,
    },
    subpanels: {
        outlineTabs: true,
        outlineTools: true,
        outlineGoals: true,
        rightTabs: { editor: true, comments: true, research: true, checks: true },
        bottomCards: { research: true, design: true, cover: true, export: true },
    },
    sizes: { outline: 268, right: 392, bottom: 236 },
    rails: { outline: false, right: false },
    swap: false,
    canvas: {
        width: 'full',
        customWidth: 760,
        font: 'serif',
        fontSize: 17,
        lineHeight: 1.41,
        background: 'plain',
        toolbarStyle: 'docked',
        typewriter: false,
        pageView: false,
    },
    preset: null,
};

/** Splitter bounds, as the schema has them. */
export const SIZE_LIMITS = {
    outline: { min: 200, max: 480 },
    right: { min: 300, max: 640 },
    bottom: { min: 120, max: 480 },
};

/** The measure of the writing column, in CSS pixels; null for full. */
export const CANVAS_WIDTHS = [
    { key: 'narrow', label: 'Narrow', px: 560 },
    { key: 'medium', label: 'Medium', px: 720 },
    { key: 'wide', label: 'Wide', px: 900 },
    { key: 'full', label: 'Full', px: null },
    { key: 'custom', label: 'Custom', px: null },
];

/** Faces for writing: the two the app bundles, and two every system has. */
export const CANVAS_FONTS = [
    { key: 'serif', label: 'Source Serif', css: "'Source Serif 4', Georgia, serif" },
    { key: 'sans', label: 'Inter', css: "Inter, ui-sans-serif, system-ui, sans-serif" },
    { key: 'georgia', label: 'Georgia', css: 'Georgia, "Times New Roman", serif' },
    { key: 'mono', label: 'Monospace', css: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' },
];

export const LINE_HEIGHTS = [1.2, 1.41, 1.6, 1.8, 2];

/**
 * The studio's own layouts. Each names only what it decides; sizes, the
 * font and everything else the author chose stay as they were.
 */
export const BUILTIN_PRESETS = [
    {
        id: 'write',
        name: 'Write',
        hint: 'Outline and editor',
        layout: {
            panels: { topNav: true, sidebar: true, credits: true, outline: true, right: false, bottom: false, toolbar: true, statusBar: true },
            rails: { outline: false },
            canvas: { width: 'full' },
        },
    },
    {
        id: 'edit',
        name: 'Edit',
        hint: 'Editor with findings and comments',
        tab: 'AI Editor',
        layout: {
            panels: { topNav: true, sidebar: true, credits: true, outline: false, right: true, bottom: false, toolbar: true, statusBar: true },
            subpanels: { rightTabs: { editor: true, comments: true } },
            rails: { right: false },
            canvas: { width: 'full' },
        },
    },
    {
        id: 'research',
        name: 'Research',
        hint: 'Editor with the research library',
        tab: 'Research',
        layout: {
            panels: { topNav: true, sidebar: true, credits: true, outline: false, right: true, bottom: true, toolbar: true, statusBar: true },
            subpanels: { rightTabs: { research: true }, bottomCards: { research: true } },
            rails: { right: false },
            canvas: { width: 'full' },
        },
    },
    {
        id: 'focus',
        name: 'Focus',
        hint: 'The editor alone, centred',
        layout: {
            panels: { topNav: false, sidebar: false, credits: false, outline: false, right: false, bottom: false, toolbar: true, statusBar: true },
            canvas: { width: 'medium' },
        },
    },
    {
        id: 'everything',
        name: 'Everything',
        hint: 'Every panel and every part of them',
        layout: {
            panels: { ...DEFAULT_WORKSPACE.panels },
            subpanels: structuredCloneSafe(DEFAULT_WORKSPACE.subpanels),
            rails: { outline: false, right: false },
        },
    },
];

/**
 * The panel shortcuts, now entries of the app's shortcut registry
 * (resources/js/shortcuts.js: view.outline, view.right, view.bottom,
 * view.focus), so an author can remap them and the View menu, search and
 * the handlers all follow. Kept under their old names for the studio.
 */
const VIEW_IDS = { outline: 'view.outline', right: 'view.right', bottom: 'view.bottom', focus: 'view.focus' };

export const SHORTCUTS = Object.fromEntries(
    Object.entries(VIEW_IDS).map(([name, id]) => [
        name,
        {
            id,
            get keys() {
                return ariaKeys(id);
            },
            match: (event) => matches(id, event),
        },
    ]),
);

/** The label with ⌘ for a Mac, Ctrl elsewhere, as the author has it. */
export function shortcutLabel(name) {
    return VIEW_IDS[name] ? registryLabel(VIEW_IDS[name]) : '';
}

function structuredCloneSafe(value) {
    return JSON.parse(JSON.stringify(value));
}

function isGroup(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Deep merge of a partial layout over a whole one; unknown keys dropped. */
export function mergeLayout(base, changes) {
    const out = Array.isArray(base) ? [...base] : { ...base };

    for (const [key, value] of Object.entries(changes ?? {})) {
        if (!(key in base)) continue;

        out[key] = isGroup(base[key]) && isGroup(value) ? mergeLayout(base[key], value) : value;
    }

    return out;
}

/** Whether a layout still is what a preset makes it. */
export function matchesPreset(settings, layout) {
    return Object.entries(layout).every(([key, value]) =>
        isGroup(value) ? isGroup(settings[key]) && matchesPreset(settings[key], value) : settings[key] === value,
    );
}

/** The writing column's measure for the canvas settings, or null (full). */
export function canvasMeasure(canvas) {
    if (canvas.width === 'custom') return canvas.customWidth;

    return CANVAS_WIDTHS.find((w) => w.key === canvas.width)?.px ?? null;
}

function xsrfHeaders() {
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    return xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : { 'X-CSRF-TOKEN': csrf };
}

function readCache(key) {
    try {
        return JSON.parse(window.localStorage.getItem(key) ?? 'null');
    } catch {
        return null;
    }
}

function writeCache(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Private mode or a full disk: the server still has the layout.
    }
}

const SAVE_DEBOUNCE_MS = 500;

/**
 * The workspace for one author and book.
 *
 * `initial` is the server's {settings, scope, presets, defaults}.
 */
export function useWorkspace({ initial, bookId, userId }) {
    const defaults = initial?.defaults ?? DEFAULT_WORKSPACE;
    const cacheKey = `ecrivain:workspace:${userId ?? 'me'}:${bookId ?? 'all'}`;

    const [state, setState] = useState(() => {
        const server = {
            settings: mergeLayout(defaults, initial?.settings ?? {}),
            scope: initial?.scope ?? 'user',
        };
        const cached = typeof window === 'undefined' ? null : readCache(cacheKey);

        // Only an unconfirmed change is preferred to the server: anything
        // else the server has is newer (another device may have saved it).
        return cached?.pending && cached.settings
            ? { settings: mergeLayout(defaults, cached.settings), scope: cached.scope ?? server.scope, pending: true }
            : { ...server, pending: false };
    });
    const [presets, setPresets] = useState(initial?.presets ?? []);
    const [error, setError] = useState(null);

    const latest = useRef(state);
    latest.current = state;
    const timer = useRef(null);

    const send = useCallback(async () => {
        clearTimeout(timer.current);
        timer.current = null;

        const { settings, scope } = latest.current;

        try {
            await window.axios.put('/workspace', {
                settings,
                scope,
                ...(bookId ? { book: bookId } : {}),
            });

            // Only clear the flag if nothing changed while the save was out.
            if (latest.current.settings === settings) {
                setState((value) => ({ ...value, pending: false }));
                writeCache(cacheKey, { settings, scope, pending: false });
            }

            setError(null);
        } catch (failure) {
            // Kept, flagged, and sent again on the next change or visit.
            setError(failure.response?.status === 422 ? 'That layout could not be saved.' : null);
        }
    }, [bookId, cacheKey]);

    const schedule = useCallback(() => {
        clearTimeout(timer.current);
        timer.current = setTimeout(send, SAVE_DEBOUNCE_MS);
    }, [send]);

    // An unconfirmed change from last time: send it now.
    useEffect(() => {
        if (latest.current.pending) schedule();
    }, [schedule]);

    // Back online, or leaving the page with a save still waiting.
    useEffect(() => {
        const online = () => latest.current.pending && schedule();
        const leave = () => {
            if (!timer.current) return;

            clearTimeout(timer.current);
            timer.current = null;

            const { settings, scope } = latest.current;

            try {
                fetch(`${window.axios?.defaults.baseURL ?? ''}/workspace`, {
                    method: 'PUT',
                    keepalive: true,
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...xsrfHeaders(),
                    },
                    body: JSON.stringify({ settings, scope, ...(bookId ? { book: bookId } : {}) }),
                });
            } catch {
                // The cached copy is still flagged and goes next visit.
            }
        };

        window.addEventListener('online', online);
        window.addEventListener('pagehide', leave);

        return () => {
            window.removeEventListener('online', online);
            window.removeEventListener('pagehide', leave);
            clearTimeout(timer.current);
        };
    }, [bookId, schedule]);

    /**
     * Change the layout. `changes` is a partial layout or a function of the
     * current settings returning one. Any change by hand leaves the preset.
     */
    const update = useCallback(
        (changes, { preset = null } = {}) => {
            setState((value) => {
                const partial = typeof changes === 'function' ? changes(value.settings) : changes;
                const settings = { ...mergeLayout(value.settings, partial), preset };
                const next = { ...value, settings, pending: true };

                writeCache(cacheKey, { settings, scope: value.scope, pending: true });

                return next;
            });
            schedule();
        },
        [cacheKey, schedule],
    );

    const adopt = useCallback(
        (data) => {
            const settings = mergeLayout(defaults, data.settings ?? {});

            setState({ settings, scope: data.scope ?? 'user', pending: false });
            if (data.presets) setPresets(data.presets);
            writeCache(cacheKey, { settings, scope: data.scope ?? 'user', pending: false });
        },
        [cacheKey, defaults],
    );

    /** "Use this layout for this book only", on or off. */
    const setScope = useCallback(
        async (scope) => {
            if (!bookId) return;

            clearTimeout(timer.current);
            timer.current = null;

            try {
                const { data } = scope === 'book'
                    ? await window.axios.put('/workspace', { settings: latest.current.settings, scope: 'book', book: bookId })
                    // The author's own layout applies to this book again.
                    : await window.axios.delete(`/workspace/books/${bookId}`);

                adopt(data);
            } catch {
                setError('That could not be changed. Try again.');
            }
        },
        [adopt, bookId],
    );

    const reset = useCallback(async () => {
        clearTimeout(timer.current);
        timer.current = null;

        try {
            const { data } = await window.axios.delete('/workspace', { params: bookId ? { book: bookId } : {} });
            adopt(data);
        } catch {
            // Offline: put the default up anyway; it saves when it can.
            update(defaults);
        }
    }, [adopt, bookId, defaults, update]);

    const savePreset = useCallback(async (name) => {
        const { preset, version, ...layout } = latest.current.settings;
        const { data } = await window.axios.post('/workspace/presets', { name, layout });

        setPresets(data.presets);
        update({}, { preset: name.trim() });

        return data.presets;
    }, [update]);

    const deletePreset = useCallback(async (id) => {
        const { data } = await window.axios.delete(`/workspace/presets/${id}`);
        setPresets(data.presets);
    }, []);

    return {
        settings: state.settings,
        scope: state.scope,
        pending: state.pending,
        presets,
        defaults,
        error,
        update,
        setScope,
        reset,
        savePreset,
        deletePreset,
    };
}
