import { useEffect, useRef, useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { useI18n } from '@/i18n';
import { clearSearch, findState, replaceAll, replaceCurrent, setSearch, step } from './FindReplace';

/**
 * The find and replace bar over the chapter (ADR — Author walkthrough
 * fixes). Ctrl/Cmd+F opens it to find, Ctrl/Cmd+Shift+H to replace.
 *
 * "This chapter" highlights matches in the editor and replaces them as
 * ordinary edits. "Whole manuscript" lists matches by chapter, opens a
 * chapter at its matches, and replaces in every chapter on the server,
 * which keeps a version of each changed chapter first.
 */
export default function FindBar({ editor, bookId, sectionId, mode = 'find', focusAt = 0, onClose, onOpenSection, onFlush, onReplacedAll }) {
    const { t, formatNumber } = useI18n();
    const [term, setTerm] = useState('');
    const [replacement, setReplacement] = useState('');
    const [matchCase, setMatchCase] = useState(false);
    const [wholeWord, setWholeWord] = useState(false);
    const [scope, setScope] = useState('chapter');
    const [showReplace, setShowReplace] = useState(mode === 'replace');
    const [results, setResults] = useState(null);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const input = useRef(null);
    // Re-rendered on every editor transaction, so the count stays current.
    const [, setTick] = useState(0);

    useEffect(() => {
        if (mode === 'replace') setShowReplace(true);
        input.current?.focus();
        input.current?.select();
    }, [mode, focusAt]);

    useEffect(() => {
        if (!editor) return undefined;

        const tick = () => setTick((n) => n + 1);
        editor.on('transaction', tick);

        return () => editor.off('transaction', tick);
    }, [editor]);

    // The chapter's highlights follow the term, the options and the chapter.
    useEffect(() => {
        if (!editor || editor.isDestroyed) return;

        setSearch(editor, { term: scope === 'chapter' ? term : '', matchCase, wholeWord });
    }, [editor, term, matchCase, wholeWord, scope]);

    useEffect(() => () => clearSearch(editor), [editor]);

    // The manuscript's matches, a moment after typing stops.
    useEffect(() => {
        if (scope !== 'book' || !term.trim()) {
            setResults(null);

            return undefined;
        }

        let live = true;
        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.get(`/books/${bookId}/find`, { params: { q: term, case: matchCase ? 1 : 0, word: wholeWord ? 1 : 0 } });
                if (live) setResults(data);
            } catch {
                if (live) setResults({ sections: [], total: 0 });
            }
        }, 250);

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [scope, term, matchCase, wholeWord, bookId]);

    const state = findState(editor);
    const count = state.matches.length;

    const onKey = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
        } else if (event.key === 'Enter' && event.currentTarget === input.current && scope === 'chapter') {
            event.preventDefault();
            step(editor, event.shiftKey ? -1 : 1);
        }
    };

    const replaceEverywhere = async () => {
        setConfirming(false);
        setBusy(true);

        try {
            await onFlush?.();
            const { data } = await window.axios.post(`/books/${bookId}/replace`, {
                q: term, replacement, case: matchCase, word: wholeWord, confirmed: true, section: sectionId,
            });
            onReplacedAll?.(data);
            setMessage(t('fric.find_replaced_book', { count: data.replaced, chapters: data.sections.length }));
            setResults({ sections: [], total: 0 });
        } catch {
            setMessage(t('fric.find_replace_failed'));
        } finally {
            setBusy(false);
        }
    };

    const toggle = (on, set, label, key) => (
        <button
            type="button"
            data-a={`find:${key}`}
            aria-pressed={on}
            onClick={() => set(!on)}
            className={`h-7 rounded-control px-2 text-[11.5px] font-semibold transition ${on ? 'bg-brand-primary-subtle text-brand-primary' : 'text-text-secondary hover:bg-bg-subtle'}`}
        >
            {label}
        </button>
    );

    const button = 'h-7 shrink-0 rounded-control border border-border-default px-2 text-[12px] font-medium text-text-primary transition hover:bg-bg-subtle disabled:opacity-50';

    return (
        <div data-a="find:bar" role="search" aria-label={t('fric.find_title')} onKeyDown={onKey} className="shrink-0 border-b border-border-divider bg-bg-surface px-3 py-2 sm:px-[21px]">
            <div className="flex flex-wrap items-center gap-1.5">
                <div className="flex rounded-control border border-border-default p-0.5" role="group" aria-label={t('fric.find_scope')}>
                    {['chapter', 'book'].map((key) => (
                        <button
                            key={key}
                            type="button"
                            data-a={`find:scope:${key}`}
                            aria-pressed={scope === key}
                            onClick={() => setScope(key)}
                            className={`h-6 rounded-[5px] px-2 text-[11.5px] font-semibold ${scope === key ? 'bg-bg-subtle text-text-primary' : 'text-text-secondary'}`}
                        >
                            {t(key === 'chapter' ? 'fric.find_chapter' : 'fric.find_book')}
                        </button>
                    ))}
                </div>
                <input
                    ref={input}
                    type="search"
                    value={term}
                    data-a="find:term"
                    aria-label={t('fric.find_term')}
                    placeholder={t('fric.find_term')}
                    onChange={(event) => setTerm(event.target.value)}
                    className="h-7 min-w-0 flex-1 basis-[140px] rounded-control border border-border-input px-2 text-[13px] focus:border-brand-primary focus:ring-0"
                />
                <span data-a="find:count" aria-live="polite" className="min-w-[64px] text-[11.5px] tabular-nums text-text-secondary">
                    {term && scope === 'chapter' && (count ? t('fric.find_count', { current: formatNumber(state.current + 1), total: formatNumber(count) }) : t('fric.find_none'))}
                    {term && scope === 'book' && results && t('fric.find_book_count', { count: results.total, chapters: results.sections.length })}
                </span>
                {scope === 'chapter' && (
                    <>
                        <button type="button" data-a="find:prev" onClick={() => step(editor, -1)} disabled={!count} className={button} aria-label={t('fric.find_prev')} title={t('fric.find_prev')}>↑</button>
                        <button type="button" data-a="find:next" onClick={() => step(editor, 1)} disabled={!count} className={button} aria-label={t('fric.find_next')} title={t('fric.find_next')}>↓</button>
                    </>
                )}
                {toggle(matchCase, setMatchCase, t('fric.find_case'), 'case')}
                {toggle(wholeWord, setWholeWord, t('fric.find_word'), 'word')}
                <button type="button" data-a="find:toggle-replace" aria-expanded={showReplace} onClick={() => setShowReplace((on) => !on)} className="h-7 rounded-control px-2 text-[11.5px] font-semibold text-text-secondary hover:bg-bg-subtle">
                    {t('fric.find_replace')}
                </button>
                <button type="button" data-a="find:close" onClick={onClose} aria-label={t('common.close')} className="grid h-7 w-7 place-items-center rounded-control text-text-secondary hover:bg-bg-subtle">×</button>
            </div>

            {showReplace && (
                <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <input
                        type="text"
                        value={replacement}
                        data-a="find:replacement"
                        aria-label={t('fric.find_replacement')}
                        placeholder={t('fric.find_replacement')}
                        onChange={(event) => setReplacement(event.target.value)}
                        className="h-7 min-w-0 flex-1 basis-[140px] rounded-control border border-border-input px-2 text-[13px] focus:border-brand-primary focus:ring-0"
                    />
                    {scope === 'chapter' ? (
                        <>
                            <button type="button" data-a="find:replace-one" disabled={!count} onClick={() => replaceCurrent(editor, replacement)} className={button}>
                                {t('fric.find_replace_one')}
                            </button>
                            <button type="button" data-a="find:replace-all" disabled={!count} onClick={() => setMessage(t('fric.find_replaced', { count: replaceAll(editor, replacement) }))} className={button}>
                                {t('fric.find_replace_all')}
                            </button>
                        </>
                    ) : (
                        <button type="button" data-a="find:replace-book" disabled={busy || !results?.total} onClick={() => setConfirming(true)} className={button}>
                            {t('fric.find_replace_book')}
                        </button>
                    )}
                </div>
            )}

            {message && <p data-a="find:message" role="status" className="mt-1.5 text-[11.5px] text-text-secondary">{message}</p>}

            {scope === 'book' && results?.sections?.length > 0 && (
                <ul data-a="find:results" className="mt-2 max-h-[40vh] space-y-1 overflow-y-auto">
                    {results.sections.map((s) => (
                        <li key={s.id}>
                            <button
                                type="button"
                                data-a={`find:result:${s.id}`}
                                onClick={() => {
                                    setScope('chapter');
                                    onOpenSection?.(s.id);
                                }}
                                className="w-full rounded-control px-2 py-1.5 text-left hover:bg-bg-subtle"
                            >
                                <span className="flex items-baseline justify-between gap-2">
                                    <span className="truncate text-[12.5px] font-semibold text-text-primary">{s.title}</span>
                                    <span className="shrink-0 text-[11px] tabular-nums text-text-secondary">{formatNumber(s.count)}</span>
                                </span>
                                {s.snippets.map((snippet, i) => (
                                    <span key={i} className="block truncate text-[11.5px] text-text-secondary">{snippet}</span>
                                ))}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {confirming && (
                <ConfirmDialog
                    title={t('fric.find_replace_book')}
                    message={t('fric.find_confirm', { count: results?.total ?? 0, chapters: results?.sections?.length ?? 0 })}
                    confirmLabel={t('fric.find_replace_book')}
                    onConfirm={replaceEverywhere}
                    onCancel={() => setConfirming(false)}
                    busy={busy}
                />
            )}
        </div>
    );
}
