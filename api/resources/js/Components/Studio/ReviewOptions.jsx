import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Run a review with options (ADR — Style guides and review options): one
 * agent over a selection, the current chapter, a range of chapters or the
 * whole book, with focus notes and a sensitivity. The estimate (tokens,
 * dollars from the gateway's price table, credits) is fetched for exactly
 * what will run and shown before the button; a large whole-book run asks
 * for approval of that estimate.
 *
 * A range or the whole book runs on the worker: the response is a run,
 * polled here for sections done, findings and cost so far, with new
 * findings loaded as they arrive and a Cancel button.
 */

const control =
    'w-full min-w-0 rounded-control border border-border-input bg-bg-surface px-2 py-1.5 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0';

const SENSITIVITY = [
    ['light', 'Light', 'Clear errors only'],
    ['standard', 'Standard', 'As before'],
    ['thorough', 'Thorough', 'Suggestions too'],
];

const MAX_FOCUS = 500;

function money(usd) {
    if (usd === null || usd === undefined) return 'cost unknown';
    if (usd === 0) return '$0';

    return usd < 0.01 ? '< $0.01' : `$${usd.toFixed(2)}`;
}

export default function ReviewOptions({ bookId, agents, sections, currentSectionId = null, selection = null, refresh = 0, onDone, onNotify }) {
    const runnable = agents ?? [];
    const firstId = sections[0]?.id ?? null;
    const [agent, setAgent] = useState(runnable.find((a) => a.value === 'copy')?.value ?? runnable[0]?.value ?? '');
    const [scope, setScope] = useState(currentSectionId ? 'chapter' : 'book');
    const [sectionId, setSectionId] = useState(currentSectionId ?? firstId);
    const [fromId, setFromId] = useState(firstId);
    const [toId, setToId] = useState(sections[sections.length - 1]?.id ?? null);
    const [focus, setFocus] = useState('');
    const [sensitivity, setSensitivity] = useState('standard');
    const [estimate, setEstimate] = useState(null);
    const [estimateError, setEstimateError] = useState(null);
    const [approved, setApproved] = useState(false);
    const [running, setRunning] = useState(false);
    const ticket = useRef(0);
    // A queued run being followed.
    const [queued, setQueued] = useState(null);
    const seen = useRef(-1);

    const hasSelection = Boolean(selection?.text && selection.sectionId);

    const body = useMemo(() => {
        const base = { agent, scope, sensitivity, focus: focus.trim() || null };

        if (scope === 'chapter') return { ...base, section_id: sectionId };
        if (scope === 'selection') return { ...base, section_id: selection?.sectionId, selection: selection?.text };
        if (scope === 'range') return { ...base, from_id: fromId, to_id: toId };

        return base;
    }, [agent, scope, sectionId, fromId, toId, focus, sensitivity, selection]);

    // The estimate follows the form, a moment after the last change.
    useEffect(() => {
        if (!agent || (scope === 'chapter' && !sectionId) || (scope === 'range' && (!fromId || !toId))) return;

        const mine = ++ticket.current;
        setApproved(false);
        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.post(`/books/${bookId}/reviews/estimate`, body);
                if (mine === ticket.current) {
                    setEstimate(data.estimate);
                    setEstimateError(null);
                }
            } catch (error) {
                if (mine === ticket.current) {
                    setEstimate(null);
                    const errors = error.response?.data?.errors;
                    setEstimateError(errors ? Object.values(errors).flat().join(' ') : 'No estimate for this review.');
                }
            }
        }, 350);

        return () => clearTimeout(timer);
    }, [bookId, body, refresh]);

    // A run already going when the screen opens is followed too.
    useEffect(() => {
        window.axios
            .get(`/books/${bookId}/reviews/active`)
            .then(({ data }) => data.run && setQueued(data.run))
            .catch(() => {});
    }, [bookId]);

    const queuedId = queued?.id;
    const queuedActive = queued && ['queued', 'running'].includes(queued.status);

    useEffect(() => {
        if (!queuedId || !queuedActive) return;

        const timer = setInterval(async () => {
            try {
                const { data } = await window.axios.get(`/editorial-runs/${queuedId}`);
                setQueued(data.run);

                // Findings as they arrive: reload when a section finishes.
                if (data.run.done !== seen.current) {
                    seen.current = data.run.done;
                    onDone?.(data);
                }

                if (!['queued', 'running'].includes(data.run.status)) {
                    const why = data.run.stop_reason ? ` ${data.run.stop_reason}` : '';
                    onNotify?.(`Review ${data.run.status}: ${data.run.done} of ${data.run.total} sections, ${data.run.findings} finding${data.run.findings === 1 ? '' : 's'}.${why}`);
                }
            } catch {
                // Try again on the next tick.
            }
        }, 1500);

        return () => clearInterval(timer);
    }, [queuedId, queuedActive]);

    const cancel = async () => {
        try {
            const { data } = await window.axios.post(`/editorial-runs/${queuedId}/cancel`);
            setQueued(data.run);
            onDone?.(data);
            onNotify?.('Review cancelled. Sections already reviewed keep their findings.');
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That run could not be cancelled.');
        }
    };

    const run = async () => {
        setRunning(true);

        try {
            const { data, status } = await window.axios.post(`/books/${bookId}/reviews`, { ...body, approved });

            if (status === 202) {
                seen.current = -1;
                setQueued(data.run);
                onNotify?.(`Review queued: ${data.run.total} sections.`);

                return;
            }

            const failed = data.failed ? ` ${data.failed} could not finish.` : '';
            onNotify?.(`${data.reviews.length} chapter${data.reviews.length === 1 ? '' : 's'} reviewed: ${data.findings} finding${data.findings === 1 ? '' : 's'}.${failed}`);
            onDone?.(data);
        } catch (error) {
            if (error.response?.status === 409 && error.response.data?.run) {
                setQueued(error.response.data.run);
                onNotify?.(error.response.data.message);
            } else if (error.response?.status === 409 && error.response.data?.needs_approval) {
                setEstimate(error.response.data.estimate);
                onNotify?.('Approve the estimate to run a review of the whole book.');
            } else {
                const errors = error.response?.data?.errors;
                onNotify?.(errors ? Object.values(errors).flat().join(' ') : (error.response?.data?.message ?? 'That review could not be run.'));
            }
        } finally {
            setRunning(false);
        }
    };

    const scopes = [
        hasSelection && ['selection', 'Selection'],
        ['chapter', 'Chapter'],
        ['range', 'Chapters'],
        ['book', 'Whole book'],
    ].filter(Boolean);

    const blocked = !estimate || running || queuedActive || !estimate.affordable || !estimate.within_cap || (estimate.needs_approval && !approved);

    return (
        <section className="mb-5 rounded-panel border border-border-default bg-bg-surface p-4" data-a="review-options">
            <h3 className="mb-3 text-[13.5px] font-semibold text-text-primary">Run a review</h3>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label className="block text-[11.5px] text-text-secondary">
                    Agent
                    <select data-a="review-options:agent" value={agent} onChange={(e) => setAgent(e.target.value)} className={`${control} mt-1`}>
                        {runnable.map((a) => (
                            <option key={a.value} value={a.value}>
                                {a.label}
                            </option>
                        ))}
                    </select>
                </label>

                <fieldset className="min-w-0">
                    <legend className="text-[11.5px] text-text-secondary">Scope</legend>
                    <div className="mt-1 flex flex-wrap gap-1.5" role="radiogroup">
                        {scopes.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                role="radio"
                                aria-checked={scope === key}
                                data-a={`review-options:scope:${key}`}
                                onClick={() => setScope(key)}
                                className={`rounded-badge border px-2.5 py-1 text-[12px] transition max-sm:py-2 ${
                                    scope === key ? 'border-brand-primary bg-brand-primary-subtle text-outline-active-text' : 'border-border-default text-text-secondary hover:text-text-primary'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </fieldset>

                {scope === 'chapter' && (
                    <label className="block text-[11.5px] text-text-secondary sm:col-span-2">
                        Chapter
                        <select data-a="review-options:chapter" value={sectionId ?? ''} onChange={(e) => setSectionId(Number(e.target.value))} className={`${control} mt-1`}>
                            {sections.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.title}
                                </option>
                            ))}
                        </select>
                    </label>
                )}

                {scope === 'range' && (
                    <>
                        <label className="block text-[11.5px] text-text-secondary">
                            From
                            <select data-a="review-options:from" value={fromId ?? ''} onChange={(e) => setFromId(Number(e.target.value))} className={`${control} mt-1`}>
                                {sections.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-[11.5px] text-text-secondary">
                            To
                            <select data-a="review-options:to" value={toId ?? ''} onChange={(e) => setToId(Number(e.target.value))} className={`${control} mt-1`}>
                                {sections.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </>
                )}

                {scope === 'selection' && (
                    <p className="rounded-tile bg-bg-subtle px-3 py-2 text-[12px] italic text-text-secondary sm:col-span-2" data-a="review-options:selection">
                        “{selection.text.length > 160 ? `${selection.text.slice(0, 159)}…` : selection.text}”
                    </p>
                )}

                <fieldset className="min-w-0 sm:col-span-2">
                    <legend className="text-[11.5px] text-text-secondary">Sensitivity</legend>
                    <div className="mt-1 grid grid-cols-3 gap-1.5" role="radiogroup">
                        {SENSITIVITY.map(([key, label, hint]) => (
                            <button
                                key={key}
                                type="button"
                                role="radio"
                                aria-checked={sensitivity === key}
                                data-a={`review-options:sensitivity:${key}`}
                                onClick={() => setSensitivity(key)}
                                className={`rounded-control border px-2 py-1.5 text-left transition ${
                                    sensitivity === key ? 'border-brand-primary bg-brand-primary-subtle' : 'border-border-default hover:border-border-input'
                                }`}
                            >
                                <span className="block text-[12.5px] font-semibold text-text-primary">{label}</span>
                                <span className="block text-[11px] text-text-secondary">{hint}</span>
                            </button>
                        ))}
                    </div>
                </fieldset>

                <label className="block text-[11.5px] text-text-secondary sm:col-span-2">
                    Focus notes for the editor (optional, {focus.length}/{MAX_FOCUS})
                    <textarea
                        data-a="review-options:focus"
                        rows={2}
                        maxLength={MAX_FOCUS}
                        value={focus}
                        placeholder="e.g. Check dialogue punctuation and the spelling of place names."
                        onChange={(e) => setFocus(e.target.value)}
                        className={`${control} mt-1`}
                    />
                </label>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-3">
                <p className="min-w-0 flex-1 text-[12px] text-text-secondary" data-a="review-options:estimate" aria-live="polite">
                    {estimateError
                        ? estimateError
                        : !estimate
                          ? 'Estimating…'
                          : estimate.simulated
                            ? `${estimate.reviewable} of ${estimate.sections} chapter${estimate.sections === 1 ? '' : 's'} to review · sample output, no credits`
                            : `${estimate.reviewable} of ${estimate.sections} chapter${estimate.sections === 1 ? '' : 's'} · about ${(estimate.input_tokens + estimate.output_tokens).toLocaleString()} tokens · ${money(estimate.usd)} · ${estimate.credits} credits (you have ${estimate.balance})`}
                    {estimate?.style_guide && <span className="ml-1">· with the style guide</span>}
                    {estimate && !estimate.affordable && <span className="ml-1 text-status-danger">· not enough credits</span>}
                    {estimate && !estimate.within_cap && <span className="ml-1 text-status-danger">· over this month’s AI limit</span>}
                </p>
                <button
                    type="button"
                    data-a="review-options:run"
                    disabled={blocked}
                    onClick={run}
                    className="rounded-control bg-brand-fill px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {running ? 'Reviewing…' : 'Run review'}
                </button>
            </div>

            {queued && (
                <div className="mt-3 rounded-tile border border-border-default bg-bg-subtle px-3 py-2 text-[12px] text-text-body" data-a="review-options:progress" aria-live="polite">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="min-w-0 flex-1" data-a="review-options:progress:text">
                            {queuedActive ? (queued.status === 'queued' ? 'Waiting for the worker… ' : 'Reviewing… ') : `Review ${queued.status}: `}
                            {queued.done} of {queued.total} sections · {queued.findings} finding{queued.findings === 1 ? '' : 's'}
                            {queued.credits_spent > 0 && ` · ${queued.credits_spent} credits`}
                            {queued.cost_usd > 0 && ` · ${money(queued.cost_usd)}`}
                        </span>
                        {queuedActive ? (
                            <button type="button" data-a="review-options:cancel" onClick={cancel} className="font-semibold text-status-danger hover:underline">
                                Cancel
                            </button>
                        ) : (
                            <button type="button" aria-label="Close" onClick={() => setQueued(null)} className="text-text-secondary hover:text-text-primary">
                                ×
                            </button>
                        )}
                    </div>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-border-default" role="progressbar" aria-valuemin={0} aria-valuemax={queued.total} aria-valuenow={queued.done}>
                        <div className="h-full bg-brand-fill transition-all" style={{ width: `${queued.total ? (100 * queued.done) / queued.total : 0}%` }} />
                    </div>
                    {queued.stop_reason && <p className="mt-1 text-text-secondary">{queued.stop_reason}</p>}
                </div>
            )}

            {estimate?.needs_approval && (
                <label className="mt-2 flex items-start gap-2 rounded-tile border border-status-warning/40 bg-status-warning-tile px-3 py-2 text-[12px] text-text-body" data-a="review-options:approval">
                    <input type="checkbox" data-a="review-options:approve" checked={approved} onChange={(e) => setApproved(e.target.checked)} className="mt-0.5" />
                    I approve this whole-book review: {money(estimate.usd)} and {estimate.credits} credits.
                </label>
            )}
        </section>
    );
}
