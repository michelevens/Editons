import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Dialog, { CloseButton } from './Dialog';
import {
    SCOPES,
    SHORTCUT_LIST,
    bindingFromEvent,
    bindingsOf,
    conflictFor,
    describeShortcut,
    formatBinding,
    isTyping,
    matches,
    resetPrefs,
    savePrefs,
    scopeLabel,
    usePrefs,
} from '../shortcuts';
import { useI18n } from '../i18n';

/**
 * The keyboard shortcut sheet (ADR — Configurable shortcuts and mobile
 * tabs): every shortcut in the app by where it works, with the keys the
 * author has now, searchable, and remappable where the app owns the keys.
 *
 * `?` opens it when focus is not in something that takes typing; so do
 * "Keyboard shortcuts" in the account menu and in search. Mounted once, in
 * PlatformFrame, for a signed-in author.
 */
export function ShortcutHost() {
    const signedIn = Boolean(usePage().props.auth?.user);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!signedIn) return undefined;

        const onKey = (event) => {
            if (event.defaultPrevented || isTyping(event)) return;
            // Another dialog has the keyboard.
            if (!open && document.querySelector('[aria-modal="true"]')) return;

            if (matches('app.shortcuts', event)) {
                event.preventDefault();
                setOpen(true);
            }
        };
        const onOpen = () => setOpen(true);

        window.addEventListener('keydown', onKey);
        window.addEventListener('shortcuts:open', onOpen);

        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('shortcuts:open', onOpen);
        };
    }, [signedIn, open]);

    return open ? <ShortcutSheet onClose={() => setOpen(false)} /> : null;
}

export default function ShortcutSheet({ onClose }) {
    const prefs = usePrefs();
    const [query, setQuery] = useState('');
    const [recording, setRecording] = useState(null); // shortcut id
    const [problem, setProblem] = useState(null); // {id, message}
    const [saved, setSaved] = useState(null);
    const { t, locale } = useI18n();

    const groups = useMemo(() => {
        const q = query.trim().toLowerCase();

        return Object.entries(SCOPES)
            .map(([scope]) => ({
                scope,
                label: scopeLabel(scope),
                items: SHORTCUT_LIST.filter(
                    (s) =>
                        s.scope === scope &&
                        (!q ||
                            describeShortcut(s.id).toLowerCase().includes(q) ||
                            scopeLabel(scope).toLowerCase().includes(q) ||
                            bindingsOf(s.id, prefs).some((b) => formatBinding(b).toLowerCase().includes(q))),
                ),
            }))
            .filter((group) => group.items.length > 0);
    }, [query, prefs, locale]);

    const changed = Object.keys(prefs.shortcuts).length > 0;

    // Recording: the next key press (with its modifiers) is the new binding.
    // On the window, in the capture phase, so neither the dialog (Escape)
    // nor any shortcut underneath sees it first.
    useEffect(() => {
        if (!recording) return undefined;

        const onKey = async (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (event.key === 'Escape' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey) {
                setRecording(null);

                return;
            }

            const binding = bindingFromEvent(event);

            if (!binding) return;

            const conflict = conflictFor(recording, binding, prefs);

            if (conflict) {
                setProblem({ id: recording, message: conflict });

                return;
            }

            const id = recording;
            setRecording(null);
            setProblem(null);

            try {
                await savePrefs({ shortcuts: { [id]: binding } });
                setSaved(`${describe(id)}: ${formatBinding(binding)}`);
            } catch (errors) {
                setProblem({ id, message: Object.values(errors).flat()[0] ?? t('common.not_saved') });
            }
        };

        window.addEventListener('keydown', onKey, true);

        return () => window.removeEventListener('keydown', onKey, true);
    }, [recording, prefs]);

    const resetOne = async (id) => {
        setProblem(null);

        try {
            await savePrefs({ shortcuts: { [id]: null } });
            setSaved(t('shortcuts.back_to', { what: describe(id), keys: formatBinding(SHORTCUT_LIST.find((s) => s.id === id).keys[0]) }));
        } catch (errors) {
            setProblem({ id, message: Object.values(errors).flat()[0] ?? t('common.not_saved') });
        }
    };

    const resetAll = async () => {
        setProblem(null);
        await resetPrefs('shortcuts');
        setSaved(t('shortcuts.all_reset'));
    };

    return (
        <Dialog
            label={t('menu.shortcuts')}
            onClose={onClose}
            data-a="shortcuts:sheet"
            align="top"
            className="flex max-h-[80dvh] w-[620px] max-w-full flex-col rounded-panel bg-bg-surface shadow-popover"
        >
            <div className="flex shrink-0 items-center gap-3 border-b border-border-divider px-4 py-3">
                <h2 className="flex-1 text-[15px] font-semibold text-text-primary">{t('menu.shortcuts')}</h2>
                {changed && (
                    <button
                        type="button"
                        data-a="shortcuts:reset-all"
                        onClick={resetAll}
                        className="rounded-control px-2 py-1 text-[12.5px] font-medium text-brand-primary hover:bg-bg-subtle"
                    >
                        {t('shortcuts.reset_all')}
                    </button>
                )}
                <CloseButton onClick={onClose} label={t('shortcuts.close')} />
            </div>

            <div className="shrink-0 px-4 pt-3">
                <input
                    type="search"
                    data-a="shortcuts:search"
                    data-autofocus
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={t('shortcuts.search')}
                    aria-label={t('shortcuts.search')}
                    className="w-full rounded-control border border-border-default bg-bg-app px-3 py-2 text-[14px] text-text-primary"
                />
                <p className="sr-only" role="status" aria-live="polite">
                    {saved ?? ''}
                </p>
            </div>

            <div className="min-h-0 overflow-y-auto px-4 pb-4 pt-2">
                {groups.length === 0 && (
                    <p className="py-6 text-center text-[13px] text-text-secondary">{t('shortcuts.nothing', { query })}</p>
                )}

                {groups.map((group) => (
                    <section key={group.scope} className="mt-3" data-a={`shortcuts:group:${group.scope}`}>
                        <h3 className="mb-1 text-[11.5px] font-semibold uppercase tracking-[0.08em] text-text-secondary">
                            {group.label}
                        </h3>
                        <ul className="divide-y divide-border-divider rounded-panel border border-border-divider">
                            {group.items.map((item) => {
                                const own = prefs.shortcuts[item.id];
                                const now = recording === item.id;

                                return (
                                    <li key={item.id} data-a={`shortcuts:row:${item.id}`} className="px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="min-w-0 flex-1 text-[13.5px] text-text-body">{describeShortcut(item.id)}</span>
                                            <span className="flex flex-wrap gap-1" data-a={`shortcuts:keys:${item.id}`}>
                                                {now ? (
                                                    <span className="text-[12.5px] font-medium text-brand-primary">
                                                        {t('shortcuts.press')}
                                                    </span>
                                                ) : (
                                                    bindingsOf(item.id, prefs).map((binding) => (
                                                        <kbd
                                                            key={binding}
                                                            className={`rounded border px-1.5 py-0.5 font-sans text-[12px] ${
                                                                own ? 'border-brand-primary text-brand-primary' : 'border-border-default text-text-secondary'
                                                            }`}
                                                        >
                                                            {formatBinding(binding)}
                                                        </kbd>
                                                    ))
                                                )}
                                            </span>
                                            {item.remap ? (
                                                <span className="flex gap-1">
                                                    <button
                                                        type="button"
                                                        data-a={`shortcuts:change:${item.id}`}
                                                        onClick={() => {
                                                            setProblem(null);
                                                            setRecording(now ? null : item.id);
                                                        }}
                                                        aria-label={t('shortcuts.change_for', { what: describeShortcut(item.id) })}
                                                        className="rounded-control px-2 py-1 text-[12px] font-medium text-brand-primary hover:bg-bg-subtle"
                                                    >
                                                        {now ? t('common.cancel') : t('shortcuts.change')}
                                                    </button>
                                                    {own && !now && (
                                                        <button
                                                            type="button"
                                                            data-a={`shortcuts:reset:${item.id}`}
                                                            onClick={() => resetOne(item.id)}
                                                            aria-label={t('shortcuts.reset_for', { what: describeShortcut(item.id) })}
                                                            className="rounded-control px-2 py-1 text-[12px] text-text-secondary hover:bg-bg-subtle"
                                                        >
                                                            {t('shortcuts.reset')}
                                                        </button>
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="w-[62px] text-right text-[11px] text-text-secondary" title={t('shortcuts.built_in_title')}>
                                                    {t('shortcuts.built_in')}
                                                </span>
                                            )}
                                        </div>
                                        {problem?.id === item.id && (
                                            <p role="alert" data-a="shortcuts:conflict" className="mt-1 text-[12.5px] text-status-danger">
                                                {problem.message}
                                            </p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}
            </div>
        </Dialog>
    );
}

function describe(id) {
    return describeShortcut(id);
}
