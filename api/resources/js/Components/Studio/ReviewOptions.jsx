import { useEffect, useMemo, useRef, useState } from 'react';
import { currentLocale, formatNumber as formatAmount, t as tr, useI18n } from '../../i18n';

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
    { key: 'light', tKey: 'review.sensitivity_light', hintKey: 'review.sensitivity_light_hint' },
    { key: 'standard', tKey: 'review.sensitivity_standard', hintKey: 'review.sensitivity_standard_hint' },
    { key: 'thorough', tKey: 'review.sensitivity_thorough', hintKey: 'review.sensitivity_thorough_hint' },
];

const MAX_FOCUS = 500;

/** Dollars as before in English ("$0.12"); the language's own currency layout otherwise. */
function dollars(usd, digits) {
    if (currentLocale() === 'en') return `$${usd.toFixed(digits)}`;

    return formatAmount(usd, undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function money(usd) {
    if (usd === null || usd === undefined) return tr('review.cost_unknown');
    if (usd === 0) return dollars(0, 0);

    return usd < 0.01 ? `< ${dollars(0.01, 2)}` : dollars(usd, 2);
}

export default function ReviewOptions({ bookId, agents, sections, currentSectionId = null, selection = null, refresh = 0, onDone, onNotify }) {
    const { t, formatNumber } = useI18n();
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
                    setEstimateError(errors ? Object.values(errors).flat().join(' ') : t('review.no_estimate'));
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
                    onNotify?.(`${t('review.run_status', { status: data.run.status })} ${t('review.run_done', { done: data.run.done, total: data.run.total, count: data.run.findings })}${why}`);
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
            onNotify?.(t('review.cancelled'));
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('review.cancel_failed'));
        }
    };

    const run = async () => {
        setRunning(true);

        try {
            const { data, status } = await window.axios.post(`/books/${bookId}/reviews`, { ...body, approved });

            if (status === 202) {
                seen.current = -1;
                setQueued(data.run);
                onNotify?.(t('review.queued', { total: data.run.total }));

                return;
            }

            const failed = data.failed ? ` ${t('review.could_not_finish', { count: data.failed })}` : '';
            onNotify?.(`${t('review.reviewed', { count: data.reviews.length, findings: data.findings })}${failed}`);
            onDone?.(data);
        } catch (error) {
            if (error.response?.status === 409 && error.response.data?.run) {
                setQueued(error.response.data.run);
                onNotify?.(error.response.data.message);
            } else if (error.response?.status === 409 && error.response.data?.needs_approval) {
                setEstimate(error.response.data.estimate);
                onNotify?.(t('review.approve_first'));
            } else {
                const errors = error.response?.data?.errors;
                onNotify?.(errors ? Object.values(errors).flat().join(' ') : (error.response?.data?.message ?? t('review.run_failed')));
            }
        } finally {
            setRunning(false);
        }
    };

    const scopes = [
        hasSelection && ['selection', t('review.scope_selection')],
        ['chapter', t('review.scope_chapter')],
        ['range', t('review.scope_range')],
        ['book', t('review.scope_book')],
    ].filter(Boolean);

    const blocked = !estimate || running || queuedActive || !estimate.affordable || !estimate.within_cap || (estimate.needs_approval && !approved);

    return (
        <section className="mb-5 rounded-panel border border-border-default bg-bg-surface p-4" data-a="review-options">
            <h3 className="mb-3 text-[13.5px] font-semibold text-text-primary">{t('review.run_a_review')}</h3>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label className="block text-[11.5px] text-text-secondary">
                    {t('review.agent')}
                    <select data-a="review-options:agent" value={agent} onChange={(e) => setAgent(e.target.value)} className={`${control} mt-1`}>
                        {runnable.map((a) => (
                            <option key={a.value} value={a.value}>
                                {a.label}
                            </option>
                        ))}
                    </select>
                </label>

                <fieldset className="min-w-0">
                    <legend className="text-[11.5px] text-text-secondary">{t('review.scope')}</legend>
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
                        {t('review.chapter')}
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
                            {t('review.from')}
                            <select data-a="review-options:from" value={fromId ?? ''} onChange={(e) => setFromId(Number(e.target.value))} className={`${control} mt-1`}>
                                {sections.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-[11.5px] text-text-secondary">
                            {t('review.to')}
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
                    <legend className="text-[11.5px] text-text-secondary">{t('review.sensitivity')}</legend>
                    <div className="mt-1 grid grid-cols-3 gap-1.5" role="radiogroup">
                        {SENSITIVITY.map(({ key, tKey, hintKey }) => (
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
                                <span className="block text-[12.5px] font-semibold text-text-primary">{t(tKey)}</span>
                                <span className="block text-[11px] text-text-secondary">{t(hintKey)}</span>
                            </button>
                        ))}
                    </div>
                </fieldset>

                <label className="block text-[11.5px] text-text-secondary sm:col-span-2">
                    {t('review.focus_label', { length: focus.length, max: MAX_FOCUS })}
                    <textarea
                        data-a="review-options:focus"
                        rows={2}
                        maxLength={MAX_FOCUS}
                        value={focus}
                        placeholder={t('review.focus_placeholder')}
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
                          ? t('review.estimating')
                          : estimate.simulated
                            ? t('review.estimate_sample', { reviewable: estimate.reviewable, count: estimate.sections })
                            : t('review.estimate', {
                                  reviewable: estimate.reviewable,
                                  count: estimate.sections,
                                  tokens: formatNumber(estimate.input_tokens + estimate.output_tokens),
                                  cost: money(estimate.usd),
                                  credits: estimate.credits,
                                  balance: estimate.balance,
                              })}
                    {estimate?.style_guide && <span className="ml-1">· {t('review.with_style_guide')}</span>}
                    {estimate && !estimate.affordable && <span className="ml-1 text-status-danger">· {t('review.not_enough_credits')}</span>}
                    {estimate && !estimate.within_cap && <span className="ml-1 text-status-danger">· {t('review.over_cap')}</span>}
                </p>
                <button
                    type="button"
                    data-a="review-options:run"
                    disabled={blocked}
                    onClick={run}
                    className="rounded-control bg-brand-fill px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {running ? t('review.reviewing') : t('review.run')}
                </button>
            </div>

            {queued && (
                <div className="mt-3 rounded-tile border border-border-default bg-bg-subtle px-3 py-2 text-[12px] text-text-body" data-a="review-options:progress" aria-live="polite">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="min-w-0 flex-1" data-a="review-options:progress:text">
                            {queuedActive ? (queued.status === 'queued' ? t('review.waiting') : t('review.reviewing')) : t('review.run_status', { status: queued.status })}{' '}
                            {t('review.progress', { done: queued.done, total: queued.total, count: queued.findings })}
                            {queued.credits_spent > 0 && ` · ${t('review.credits_spent', { count: queued.credits_spent })}`}
                            {queued.cost_usd > 0 && ` · ${money(queued.cost_usd)}`}
                        </span>
                        {queuedActive ? (
                            <button type="button" data-a="review-options:cancel" onClick={cancel} className="font-semibold text-status-danger hover:underline">
                                {t('common.cancel')}
                            </button>
                        ) : (
                            <button type="button" aria-label={t('common.close')} onClick={() => setQueued(null)} className="text-text-secondary hover:text-text-primary">
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
                    {t('review.approve', { cost: money(estimate.usd), credits: estimate.credits })}
                </label>
            )}
        </section>
    );
}
