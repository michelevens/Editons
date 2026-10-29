import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Icon from './Icon';
import ReviewOptions from './ReviewOptions';
import StyleGuideCard from './StyleGuideCard';

/**
 * Every finding in the book.
 *
 * The studio's panel answers "what is wrong with this chapter". This answers
 * "what is left before the book is done" — an author clearing findings works
 * across chapters, not down one.
 *
 * Above the list: the book's style guide and "Run a review" with its
 * options (ADR — Style guides and review options). The list itself is for
 * triage: filter by status, agent, severity, chapter and rule; sort;
 * select and accept or dismiss many after confirming; and "never flag this
 * again", undoable from the notice it leaves.
 */

const SEVERITY = {
    critical: { dot: 'bg-status-danger', label: 'Critical' },
    warning: { dot: 'bg-status-warning', label: 'Warning' },
    suggestion: { dot: 'bg-status-info', label: 'Suggestion' },
};

const SORTS = [
    ['severity', 'Severity'],
    ['chapter', 'Chapter order'],
    ['agent', 'Agent'],
    ['newest', 'Newest'],
];

const chip = (on) =>
    `shrink-0 whitespace-nowrap rounded-badge border px-2.5 py-1 text-[11.5px] transition max-sm:px-3 max-sm:py-2.5 max-sm:text-[12.5px] ${
        on ? 'border-brand-primary bg-brand-primary-subtle text-outline-active-text' : 'border-border-default text-text-secondary hover:text-text-primary'
    }`;

const control =
    'min-w-0 rounded-control border border-border-input bg-bg-surface px-2 py-1.5 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0';

const row = 'mb-3 no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0';

export default function AiEditorScreen({ bookId, onOpenSection, onNotify, agent = null, currentSectionId = null, selection = null }) {
    // Heuristics rather than a model: said so above the findings.
    const simulated = usePage().props.editorial?.simulated;

    const [data, setData] = useState(null);
    const [filters, setFilters] = useState({ agent, status: 'open', severity: null, section: null, rule: null, sort: 'severity' });
    const [busy, setBusy] = useState(null);
    const [picked, setPicked] = useState([]);
    const [confirming, setConfirming] = useState(null);
    // The last "never flag", with its undo.
    const [undo, setUndo] = useState(null);
    const [menu, setMenu] = useState(null);
    const [styleKey, setStyleKey] = useState(0);
    // Bumped when the style guide changes, so the estimate is fetched again.
    const [styleTick, setStyleTick] = useState(0);

    const load = useCallback(async () => {
        const params = new URLSearchParams();

        Object.entries(filters).forEach(([key, value]) => {
            if (value) params.set(key, value);
        });

        const response = await window.axios.get(`/books/${bookId}/findings?${params.toString()}`);

        setData(response.data);
        // A selection never includes what the filter now hides.
        setPicked((ids) => ids.filter((id) => response.data.findings.some((f) => f.id === id && f.status === 'open')));
    }, [bookId, filters]);

    useEffect(() => {
        load();
    }, [load]);

    const setFilter = (key, value) => setFilters((f) => ({ ...f, [key]: value }));

    const resolve = async (finding, status) => {
        setBusy(finding.id);

        try {
            await window.axios.patch(`/findings/${finding.id}`, { status });
            await load();
        } catch (error) {
            // A refusal (the text moved, so the fix no longer applies) was
            // silent: the button just stopped spinning.
            onNotify?.(error.response?.data?.message ?? 'That finding could not be updated.');
            await load();
        } finally {
            setBusy(null);
        }
    };

    const reopen = async (finding) => {
        setBusy(finding.id);

        try {
            await window.axios.patch(`/findings/${finding.id}/reopen`).catch((error) =>
                onNotify?.(error.response?.data?.message ?? 'That finding could not be reopened.'),
            );
            await load();
        } finally {
            setBusy(null);
        }
    };

    const bulk = async (action) => {
        setBusy('bulk');

        try {
            const { data: result } = await window.axios.post(`/books/${bookId}/findings/bulk`, { ids: picked, action, confirmed: true });
            const skipped = result.skipped.length
                ? ` ${result.skipped.length} could not be ${action === 'accept' ? 'applied' : 'dismissed'}: ${result.skipped[0].message}`
                : '';
            onNotify?.(`${result.done} finding${result.done === 1 ? '' : 's'} ${action === 'accept' ? 'accepted' : 'dismissed'}.${skipped}`);
            setPicked([]);
            setConfirming(null);
            await load();
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That did not work.');
        } finally {
            setBusy(null);
        }
    };

    const neverFlag = async (finding, mode) => {
        setMenu(null);
        setBusy(finding.id);

        try {
            const { data: result } = await window.axios.post(`/findings/${finding.id}/never-flag`, { mode });
            setUndo({
                ...result,
                label: mode === 'word' ? `“${result.value}” won’t be flagged again in this book` : `“${finding.title}” is turned off for this book`,
            });
            setStyleKey((k) => k + 1);
            await load();
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That did not work.');
        } finally {
            setBusy(null);
        }
    };

    const undoNeverFlag = async () => {
        if (!undo) return;

        try {
            await window.axios.post(`/books/${bookId}/findings/unsuppress`, { mode: undo.mode, value: undo.value });
            setUndo(null);
            setStyleKey((k) => k + 1);
            await load();
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That could not be undone.');
        }
    };

    const openShown = useMemo(() => (data?.findings ?? []).filter((f) => f.status === 'open'), [data]);

    if (!data) {
        return <p className="p-8 text-center text-[13px] text-text-secondary">Loading…</p>;
    }

    const statusTabs = [
        { key: 'open', label: 'Open', count: data.counts.open },
        { key: 'accepted', label: 'Applied', count: data.counts.accepted },
        { key: 'dismissed', label: 'Dismissed', count: data.counts.dismissed },
        { key: 'all', label: 'All', count: data.counts.all },
    ];

    const pickedFindings = openShown.filter((f) => picked.includes(f.id));
    const withFix = pickedFindings.filter((f) => f.suggestion).length;
    const allPicked = openShown.length > 0 && openShown.every((f) => picked.includes(f.id));

    return (
        <div className="mx-auto max-w-[1040px] p-4 sm:p-8">
            <header className="mb-6">
                <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">AI Editor</h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    {data.counts.open === 0
                        ? 'Nothing open across the book.'
                        : `${data.counts.open} open across the book${data.counts.critical ? `, ${data.counts.critical} critical` : ''}.`}
                </p>
                {simulated && (
                    <p
                        data-a="ai-screen:sample-notice"
                        className="mt-3 rounded-tile border border-dashed border-border-default px-3 py-2 text-[12px] leading-relaxed text-text-secondary"
                    >
                        <b className="text-text-primary">Sample output.</b> Reviews here run automated checks, not an AI model, and use no credits.
                    </p>
                )}
            </header>

            <StyleGuideCard key={styleKey} bookId={bookId} onNotify={onNotify} onChanged={() => setStyleTick((t) => t + 1)} />

            <ReviewOptions
                bookId={bookId}
                agents={data.agents}
                sections={data.sections ?? []}
                currentSectionId={currentSectionId}
                selection={selection}
                refresh={styleTick + styleKey}
                onNotify={onNotify}
                onDone={() => load()}
            />

            {undo && (
                <div
                    role="status"
                    data-a="ai-screen:undo"
                    className="mb-4 flex flex-wrap items-center gap-3 rounded-tile border border-border-default bg-bg-subtle px-3 py-2 text-[12.5px] text-text-body"
                >
                    <span className="min-w-0 flex-1">
                        {undo.label}
                        {undo.dismissed > 0 && ` · ${undo.dismissed} dismissed`}
                    </span>
                    <button type="button" data-a="ai-screen:undo:button" onClick={undoNeverFlag} className="font-semibold text-brand-primary hover:underline">
                        Undo
                    </button>
                    <button type="button" aria-label="Close" onClick={() => setUndo(null)} className="text-text-secondary hover:text-text-primary">
                        ×
                    </button>
                </div>
            )}

            <div className={row.replace('mb-3', 'mb-4')}>
                {statusTabs.map((tab) => (
                    <button
                        key={tab.key}
                        data-a={`ai-screen:status:${tab.key}`}
                        onClick={() => setFilter('status', tab.key)}
                        className={`shrink-0 whitespace-nowrap rounded-badge px-3 py-1.5 text-[12.5px] font-medium transition max-sm:py-2.5 ${
                            filters.status === tab.key ? 'bg-brand-fill text-white' : 'bg-bg-surface text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {tab.label}
                        <span className="ml-1.5">{tab.count}</span>
                    </button>
                ))}
            </div>

            <div className={row}>
                <button data-a="ai-screen:agent:all" onClick={() => setFilter('agent', null)} className={chip(filters.agent === null)}>
                    Every agent
                </button>

                {data.agents.map((a) => (
                    <button key={a.value} data-a={`ai-screen:agent:${a.value}`} onClick={() => setFilter('agent', a.value)} className={chip(filters.agent === a.value)}>
                        {a.label}
                        {a.open > 0 && <span className="ml-1.5 font-semibold">{a.open}</span>}
                    </button>
                ))}
            </div>

            <div className={row}>
                <button data-a="ai-screen:severity:all" onClick={() => setFilter('severity', null)} className={chip(filters.severity === null)}>
                    Every severity
                </button>
                {Object.entries(SEVERITY).map(([key, s]) => (
                    <button key={key} data-a={`ai-screen:severity:${key}`} onClick={() => setFilter('severity', key)} className={chip(filters.severity === key)}>
                        <span className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full align-middle ${s.dot}`} />
                        {s.label}
                        {data.counts.severity?.[key] > 0 && <span className="ml-1.5 font-semibold">{data.counts.severity[key]}</span>}
                    </button>
                ))}
            </div>

            <div className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label className="flex min-w-0 flex-col text-[11.5px] text-text-secondary">
                    Chapter
                    <select
                        data-a="ai-screen:section"
                        value={filters.section ?? ''}
                        onChange={(e) => setFilter('section', e.target.value ? Number(e.target.value) : null)}
                        className={`${control} mt-1`}
                    >
                        <option value="">Every chapter</option>
                        {(data.sections ?? []).map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.title}
                                {s.open ? ` (${s.open})` : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex min-w-0 flex-col text-[11.5px] text-text-secondary">
                    Rule
                    <select data-a="ai-screen:rule" value={filters.rule ?? ''} onChange={(e) => setFilter('rule', e.target.value || null)} className={`${control} mt-1`}>
                        <option value="">Every rule</option>
                        {(data.rules ?? []).map((r) => (
                            <option key={r.rule} value={r.rule}>
                                {r.rule} ({r.open})
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex min-w-0 flex-col text-[11.5px] text-text-secondary">
                    Sort
                    <select data-a="ai-screen:sort" value={filters.sort} onChange={(e) => setFilter('sort', e.target.value)} className={`${control} mt-1`}>
                        {SORTS.map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            {openShown.length > 0 && (
                <div
                    className="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-tile border border-border-default bg-bg-surface px-3 py-2 text-[12.5px]"
                    data-a="ai-screen:bulk"
                >
                    <label className="flex items-center gap-1.5 text-text-body">
                        <input
                            type="checkbox"
                            data-a="ai-screen:pick-all"
                            checked={allPicked}
                            onChange={(e) => setPicked(e.target.checked ? openShown.map((f) => f.id) : [])}
                        />
                        {picked.length ? `${picked.length} selected` : `Select all ${openShown.length} shown`}
                    </label>
                    {picked.length > 0 && !confirming && (
                        <>
                            {withFix > 0 && (
                                <button type="button" data-a="ai-screen:bulk:accept" onClick={() => setConfirming('accept')} className="font-semibold text-status-success hover:underline">
                                    Accept {picked.length}
                                </button>
                            )}
                            <button type="button" data-a="ai-screen:bulk:dismiss" onClick={() => setConfirming('dismiss')} className="font-semibold text-text-secondary hover:text-text-primary">
                                Dismiss {picked.length}
                            </button>
                        </>
                    )}
                    {confirming && (
                        <div
                            role="alertdialog"
                            aria-label="Confirm"
                            data-a="ai-screen:confirm"
                            className="flex w-full flex-wrap items-center gap-2 border-t border-border-default pt-2 sm:w-auto sm:border-0 sm:pt-0"
                        >
                            <span className="text-text-body">
                                {confirming === 'accept'
                                    ? `Accept ${picked.length}? ${withFix} fix${withFix === 1 ? '' : 'es'} will be applied to the text, each as its own version you can undo.`
                                    : `Dismiss ${picked.length} finding${picked.length === 1 ? '' : 's'}?`}
                            </span>
                            <button
                                type="button"
                                data-a="ai-screen:confirm:yes"
                                disabled={busy === 'bulk'}
                                onClick={() => bulk(confirming)}
                                className="rounded-control bg-brand-fill px-2.5 py-1 text-[12px] font-semibold text-white disabled:opacity-50"
                            >
                                {confirming === 'accept' ? 'Accept' : 'Dismiss'}
                            </button>
                            <button type="button" data-a="ai-screen:confirm:no" onClick={() => setConfirming(null)} className="rounded-control border border-border-input px-2.5 py-1 text-[12px] text-text-body">
                                Cancel
                            </button>
                        </div>
                    )}
                </div>
            )}

            {data.findings.length === 0 ? (
                <p className="rounded-panel border border-dashed border-border-default p-10 text-center text-[13.5px] text-text-secondary">
                    {filters.status === 'open' ? 'Nothing open here. Run a review above, or an agent from a chapter.' : 'Nothing to show under this filter.'}
                </p>
            ) : (
                <ul className="space-y-2" data-a="ai-screen:list">
                    {data.findings.map((finding) => (
                        <li key={finding.id} data-a={`ai-screen:finding:${finding.id}`} className="rounded-panel border border-border-default bg-bg-surface p-4">
                            <div className="flex items-start gap-3">
                                {finding.status === 'open' && (
                                    <input
                                        type="checkbox"
                                        aria-label={`Select “${finding.title}”`}
                                        data-a={`ai-screen:pick:${finding.id}`}
                                        checked={picked.includes(finding.id)}
                                        onChange={(e) => setPicked((ids) => (e.target.checked ? [...ids, finding.id] : ids.filter((id) => id !== finding.id)))}
                                        className="mt-[3px] shrink-0"
                                    />
                                )}
                                <span className={`mt-[7px] h-2 w-2 shrink-0 rounded-full ${SEVERITY[finding.severity]?.dot ?? 'bg-border-input'}`} />

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-baseline gap-x-2.5">
                                        <p className="min-w-0 break-words text-[13.5px] font-semibold text-text-primary">{finding.title}</p>
                                        <button
                                            data-a={`ai-screen:open:${finding.section_id}`}
                                            onClick={() => onOpenSection?.(finding.section_id)}
                                            className="text-[12px] text-brand-primary hover:underline"
                                        >
                                            {finding.section_title}
                                        </button>
                                        <span className="text-[11px] uppercase tracking-wide text-text-secondary">
                                            {finding.source === 'style_check' ? 'Style guide check' : finding.agent_label}
                                        </span>
                                    </div>

                                    {finding.detail && <p className="mt-1 text-[12.5px] leading-relaxed text-text-secondary">{finding.detail}</p>}

                                    {finding.excerpt && (
                                        <p className="mt-1.5 break-words border-l-2 border-border-default pl-2.5 text-[12.5px] italic leading-snug text-text-secondary">
                                            {finding.excerpt}
                                        </p>
                                    )}

                                    <div className="mt-2.5 flex flex-wrap items-center gap-3">
                                        {finding.status === 'open' ? (
                                            <>
                                                {finding.suggestion && (
                                                    <button
                                                        data-a={`ai-screen:accept:${finding.id}`}
                                                        disabled={busy === finding.id}
                                                        onClick={() => resolve(finding, 'accepted')}
                                                        className="text-[12.5px] font-semibold text-status-success hover:underline disabled:opacity-50"
                                                    >
                                                        Apply fix
                                                    </button>
                                                )}
                                                <button
                                                    data-a={`ai-screen:dismiss:${finding.id}`}
                                                    disabled={busy === finding.id}
                                                    onClick={() => resolve(finding, 'dismissed')}
                                                    className="text-[12.5px] font-medium text-text-secondary hover:text-text-primary disabled:opacity-50"
                                                >
                                                    Dismiss
                                                </button>
                                                {(finding.term || finding.rule) && (
                                                    <span className="relative">
                                                        <button
                                                            data-a={`ai-screen:never:${finding.id}`}
                                                            aria-haspopup="menu"
                                                            aria-expanded={menu === finding.id}
                                                            onClick={() => setMenu(menu === finding.id ? null : finding.id)}
                                                            className="text-[12.5px] font-medium text-text-secondary hover:text-text-primary"
                                                        >
                                                            Never flag this…
                                                        </button>
                                                        {menu === finding.id && (
                                                            <span
                                                                role="menu"
                                                                className="absolute left-0 top-full z-20 mt-1 flex w-max max-w-[75vw] flex-col rounded-tile border border-border-default bg-bg-surface py-1 shadow-lg"
                                                            >
                                                                {finding.term && (
                                                                    <button
                                                                        role="menuitem"
                                                                        data-a={`ai-screen:never:word:${finding.id}`}
                                                                        onClick={() => neverFlag(finding, 'word')}
                                                                        className="px-3 py-1.5 text-left text-[12.5px] text-text-body hover:bg-bg-subtle"
                                                                    >
                                                                        Never flag “{finding.term}” in this book
                                                                    </button>
                                                                )}
                                                                {finding.rule && (
                                                                    <button
                                                                        role="menuitem"
                                                                        data-a={`ai-screen:never:rule:${finding.id}`}
                                                                        onClick={() => neverFlag(finding, 'rule')}
                                                                        className="px-3 py-1.5 text-left text-[12.5px] text-text-body hover:bg-bg-subtle"
                                                                    >
                                                                        Turn off this rule for this book
                                                                    </button>
                                                                )}
                                                            </span>
                                                        )}
                                                    </span>
                                                )}
                                            </>
                                        ) : (
                                            <>
                                                <span className="text-[12px] text-text-secondary">{finding.status === 'accepted' ? 'Applied' : 'Dismissed'}</span>
                                                <button
                                                    data-a={`ai-screen:reopen:${finding.id}`}
                                                    disabled={busy === finding.id}
                                                    onClick={() => reopen(finding)}
                                                    className="text-[12.5px] font-semibold text-brand-primary hover:underline disabled:opacity-50"
                                                >
                                                    {/* An applied fix is taken back out of
                                                        the text before it reopens. */}
                                                    {finding.status === 'accepted' && finding.suggestion ? 'Undo fix' : 'Reopen'}
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>

                                <span className="shrink-0 text-text-secondary max-sm:hidden">
                                    <Icon name="right" size={15} />
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
