import { useCallback, useEffect, useMemo, useState } from 'react';
import { useI18n } from '@/i18n';
import Icon from './Icon';

/**
 * Write my book (ADR — AI writes the book).
 *
 * Three steps on one screen: describe the book and get a plan; review and
 * accept the plan (it builds the binder); approve the estimate once, then
 * watch every chapter being written, with pause, resume, cancel and a
 * budget. Below, every section with "Write this chapter", "Write a new
 * version" (with a note) and "Accept" for a draft not yet reviewed.
 *
 * Everything comes from GET /books/{id}/draft, polled while a plan or a
 * run is in progress.
 */
const STATUS_TONE = {
    queued: 'bg-bg-subtle text-text-secondary',
    writing: 'bg-brand-primary-subtle text-brand-primary',
    done: 'bg-status-success-bg text-status-success',
    skipped: 'bg-bg-subtle text-text-secondary',
    failed: 'bg-status-danger-bg text-status-danger',
    cancelled: 'bg-bg-subtle text-text-secondary',
};

const LANGUAGES = ['en', 'fr', 'ht'];

export default function DraftPanel({ bookId, onOpenSection, onNavigate, onNotify, onChanged }) {
    const { t, formatNumber } = useI18n();
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);
    const [brief, setBrief] = useState(null);
    const [plan, setPlan] = useState(null);
    const [quality, setQuality] = useState('standard');
    const [budget, setBudget] = useState('');
    const [notes, setNotes] = useState({});
    const [noteFor, setNoteFor] = useState(null);

    const load = useCallback(async () => {
        const { data } = await window.axios.get(`/books/${bookId}/draft`);
        setState(data);

        return data;
    }, [bookId]);

    useEffect(() => {
        load().then((data) => {
            setBrief({
                idea: data.plan?.brief?.idea ?? data.idea.idea ?? '',
                reader: data.plan?.brief?.reader ?? data.idea.reader ?? '',
                words: data.plan?.brief?.words ?? data.idea.words ?? 30000,
                chapters: data.plan?.brief?.chapters ?? '',
                language: data.plan?.brief?.language ?? data.idea.language ?? 'en',
                fiction: !!data.plan?.brief?.fiction,
            });
        });
    }, [load]);

    // The plan under review is the author's to edit until it is accepted.
    useEffect(() => {
        if (state?.plan?.status === 'proposed' && state.plan.plan) {
            setPlan((current) => current ?? structuredClone(state.plan.plan));
        }
    }, [state?.plan?.status, state?.plan?.plan]);

    const planning = state?.plan?.status === 'proposing';
    const running = ['queued', 'running'].includes(state?.run?.status);

    // When a run stops or finishes, the binder's word counts have moved.
    const runStatus = state?.run?.status;
    useEffect(() => {
        if (runStatus && !['queued', 'running'].includes(runStatus)) onChanged?.();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [runStatus]);

    useEffect(() => {
        if (!planning && !running) return undefined;
        const timer = setInterval(() => load().catch(() => {}), 2500);

        return () => clearInterval(timer);
    }, [planning, running, load]);

    const send = async (key, method, url, body) => {
        setBusy(key);
        setError(null);

        try {
            const { data } = await window.axios[method](url, body);
            if (data && 'plan' in data) setState(data);

            return data;
        } catch (e) {
            const errors = e.response?.data?.errors;
            setError((errors && Object.values(errors)[0]?.[0]) ?? e.response?.data?.message ?? t('draft.error'));

            return null;
        } finally {
            setBusy(null);
        }
    };

    const propose = () => {
        setPlan(null);
        send('propose', 'post', `/books/${bookId}/draft/plan`, {
            ...brief,
            chapters: brief.chapters === '' ? null : Number(brief.chapters),
            words: Number(brief.words) || null,
        });
    };

    const accept = async () => {
        const data = await send('accept', 'post', `/books/${bookId}/draft/plan/accept`, { plan });
        if (data) {
            setPlan(null);
            onChanged?.();
            onNotify?.(t('draft.plan_accepted', { count: data.created ?? 0 }));
        }
    };

    const write = () =>
        send('write', 'post', `/books/${bookId}/draft/runs`, {
            approved: true,
            quality,
            budget: budget === '' ? null : Number(budget),
        });

    const writeSection = (section, mode) =>
        send(`section:${section.id}`, 'post', `/sections/${section.id}/draft`, {
            mode,
            note: notes[section.id] || null,
            approved: true,
        }).then(() => setNoteFor(null));

    const acceptSection = async (section) => {
        await send(`accept:${section.id}`, 'post', `/sections/${section.id}/draft/accept`, {});
        load();
    };

    const control = (action) => send(action, 'post', `/draft-runs/${state.run.id}/${action}`, {});

    if (!state || !brief) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const accepted = state.plan?.status === 'accepted';
    const estimate = state.estimate;
    const run = state.run;
    const writable = state.sections.filter((s) => ['chapter', 'front_matter', 'back_matter'].includes(s.type));
    const locked = state.free_chapters_left !== null;

    return (
        <div className="flex h-full min-h-0 flex-col" data-a="draft:panel">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">{t('draft.title')}</h2>
                <p className="text-[11.5px] leading-relaxed text-text-secondary">{t('draft.intro')}</p>
                {state.simulated && (
                    <p data-a="draft:sample" className="mt-1.5 rounded bg-status-warning-bg px-2 py-1 text-[11px] text-status-warning">
                        {t('draft.sample_writer')}
                    </p>
                )}
            </header>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
                {error && (
                    <p role="alert" data-a="draft:error" className="rounded bg-status-danger-bg px-3 py-2 text-[12px] text-status-danger">
                        {error}
                    </p>
                )}

                {/* 1. The brief */}
                {!accepted && !plan && !planning && (
                    <section data-a="draft:brief" className="space-y-3">
                        <Step n={1} title={t('draft.step_brief')} />
                        {state.plan?.status === 'failed' && (
                            <p className="text-[12px] text-status-danger">{state.plan.error}</p>
                        )}
                        <Field label={t('draft.idea')}>
                            <textarea
                                data-a="draft:idea"
                                rows={3}
                                value={brief.idea}
                                placeholder={t('draft.idea_placeholder')}
                                onChange={(e) => setBrief({ ...brief, idea: e.target.value })}
                                className="w-full rounded-control border border-border-input px-3 py-2 text-[13px] focus:border-brand-primary focus:ring-0"
                            />
                        </Field>
                        <Field label={t('draft.reader')}>
                            <input
                                value={brief.reader}
                                onChange={(e) => setBrief({ ...brief, reader: e.target.value })}
                                className="h-9 w-full rounded-control border border-border-input px-3 text-[13px] focus:border-brand-primary focus:ring-0"
                            />
                        </Field>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Field label={t('draft.language')}>
                                <select
                                    data-a="draft:language"
                                    value={brief.language}
                                    onChange={(e) => setBrief({ ...brief, language: e.target.value })}
                                    className="h-9 w-full rounded-control border border-border-input px-2 text-[13px]"
                                >
                                    {LANGUAGES.map((code) => (
                                        <option key={code} value={code}>{t(`draft.lang_${code}`)}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={t('draft.length')}>
                                <input
                                    type="number"
                                    min={1000}
                                    step={1000}
                                    value={brief.words}
                                    onChange={(e) => setBrief({ ...brief, words: e.target.value })}
                                    className="h-9 w-full rounded-control border border-border-input px-3 text-[13px]"
                                />
                            </Field>
                            <Field label={t('draft.chapters')}>
                                <input
                                    type="number"
                                    min={1}
                                    max={40}
                                    value={brief.chapters}
                                    placeholder={t('draft.chapters_auto')}
                                    onChange={(e) => setBrief({ ...brief, chapters: e.target.value })}
                                    className="h-9 w-full rounded-control border border-border-input px-3 text-[13px]"
                                />
                            </Field>
                        </div>
                        <label className="flex items-center gap-2 text-[12.5px] text-text-body">
                            <input type="checkbox" checked={brief.fiction} onChange={(e) => setBrief({ ...brief, fiction: e.target.checked })} />
                            {t('draft.fiction')}
                        </label>
                        <Primary data-a="draft:propose" disabled={!brief.idea.trim() || busy === 'propose'} onClick={propose}>
                            {busy === 'propose' ? t('draft.planning') : t('draft.propose')}
                        </Primary>
                    </section>
                )}

                {planning && (
                    <p data-a="draft:planning" className="flex items-center gap-2 text-[13px] text-text-secondary">
                        <Spinner /> {t('draft.planning_wait')}
                    </p>
                )}

                {/* 2. The plan */}
                {plan && !accepted && (
                    <PlanReview plan={plan} setPlan={setPlan} meta={state.plan} t={t} formatNumber={formatNumber}>
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Primary data-a="draft:accept" disabled={busy === 'accept'} onClick={accept}>
                                {t('draft.accept_plan')}
                            </Primary>
                            <button
                                data-a="draft:repropose"
                                onClick={() => setPlan(null) || setState({ ...state, plan: { ...state.plan, status: 'failed', error: null } })}
                                className="h-9 rounded-control border border-border-default px-3 text-[12.5px] font-semibold text-text-primary hover:bg-bg-subtle"
                            >
                                {t('draft.change_brief')}
                            </button>
                        </div>
                    </PlanReview>
                )}

                {/* 3. Approve and write */}
                {(accepted || (!state.plan && writable.length > 0)) && !running && estimate && run?.status !== 'paused' && (
                    <section data-a="draft:approve" className="space-y-3 rounded-tile border border-border-default p-3">
                        <Step n={accepted ? 3 : 1} title={t('draft.step_write')} />
                        <p data-a="draft:estimate" className="text-[13px] text-text-body">
                            {t('draft.estimate', {
                                sections: estimate.chapters.length,
                                words: formatNumber(estimate.words),
                            })}{' '}
                            {estimate.charged
                                ? t('draft.estimate_credits', { credits: formatNumber(estimate.credits), balance: formatNumber(state.balance) })
                                : t('draft.estimate_free')}
                        </p>
                        {locked && (
                            <p data-a="draft:locked" className="flex items-start gap-1.5 text-[12px] text-text-secondary">
                                <Icon name="lock" size={13} />
                                <span>
                                    {state.free_chapters_left > 0
                                        ? t('draft.free_taste', { plan: state.unlocks })
                                        : t('draft.free_used', { plan: state.unlocks })}{' '}
                                    <a href={route('billing.show')} className="font-semibold text-brand-primary hover:underline">
                                        {t('jfix.plan_short', { plan: state.unlocks })}
                                    </a>
                                </span>
                            </p>
                        )}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label={t('draft.quality')}>
                                <select value={quality} onChange={(e) => setQuality(e.target.value)} className="h-9 w-full rounded-control border border-border-input px-2 text-[13px]">
                                    <option value="standard">{t('draft.quality_standard')}</option>
                                    <option value="best">{t('draft.quality_best')}</option>
                                </select>
                            </Field>
                            <Field label={t('draft.budget')}>
                                <input
                                    data-a="draft:budget"
                                    type="number"
                                    min={1}
                                    value={budget}
                                    placeholder={t('draft.budget_none')}
                                    onChange={(e) => setBudget(e.target.value)}
                                    className="h-9 w-full rounded-control border border-border-input px-3 text-[13px]"
                                />
                            </Field>
                        </div>
                        <p className="text-[11.5px] leading-relaxed text-text-secondary">{t('draft.approve_note')}</p>
                        <Primary data-a="draft:write" disabled={busy === 'write' || estimate.chapters.length === 0} onClick={write}>
                            {t('draft.write_book')}
                        </Primary>
                    </section>
                )}

                {/* Progress */}
                {run && (
                    <section data-a="draft:run" className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-[13px] font-semibold text-text-primary">{t(`draft.run_${run.status}`)}</h3>
                            <span className="text-[11.5px] text-text-secondary">
                                {t('draft.run_progress', {
                                    done: run.chapters.filter((c) => c.status === 'done').length,
                                    total: run.chapters.length,
                                    credits: formatNumber(run.credits_spent),
                                })}
                            </span>
                            <span className="ml-auto flex gap-1.5">
                                {running && (
                                    <SmallButton data-a="draft:pause" onClick={() => control('pause')}>{t('draft.pause')}</SmallButton>
                                )}
                                {['paused', 'stopped', 'failed'].includes(run.status) && (
                                    <SmallButton data-a="draft:resume" onClick={() => control('resume')}>{t('draft.resume')}</SmallButton>
                                )}
                                {(running || ['paused', 'stopped', 'failed'].includes(run.status)) && (
                                    <SmallButton data-a="draft:cancel" onClick={() => control('cancel')}>{t('draft.cancel')}</SmallButton>
                                )}
                            </span>
                        </div>
                        {run.stop_reason && <p data-a="draft:stop" className="text-[12px] text-status-warning">{run.stop_reason}</p>}
                        <ol className="divide-y divide-border-divider rounded-tile border border-border-default">
                            {run.chapters.map((c) => (
                                <li key={c.section_id} className="flex items-center gap-2 px-3 py-2 text-[12.5px]">
                                    <button onClick={() => onOpenSection?.(c.section_id)} className="min-w-0 flex-1 truncate text-left text-text-primary hover:underline">
                                        {c.title}
                                    </button>
                                    {c.words > 0 && <span className="shrink-0 text-[11px] text-text-secondary">{t('quickstart.words', { words: formatNumber(c.words) })}</span>}
                                    <span data-a={`draft:status:${c.section_id}`} className={`shrink-0 rounded-badge px-1.5 py-0.5 text-[10.5px] font-semibold ${STATUS_TONE[c.status]}`}>
                                        {t(`draft.status_${c.status}`)}
                                    </span>
                                    {c.error && <span className="sr-only">{c.error}</span>}
                                </li>
                            ))}
                        </ol>
                        {run.status === 'completed' && (
                            <div data-a="draft:done" className="space-y-2 rounded-tile bg-status-success-bg p-3">
                                <p className="text-[13px] font-semibold text-status-success">{t('draft.done')}</p>
                                <p className="text-[12px] text-text-body">
                                    {run.findings > 0 ? t('draft.done_findings', { count: run.findings }) : t('draft.done_next')}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <SmallButton data-a="draft:review" onClick={() => onNavigate?.('ai')}>{t('draft.review_book')}</SmallButton>
                                    <SmallButton data-a="draft:design" onClick={() => onNavigate?.('layout')}>{t('draft.design_book')}</SmallButton>
                                    <SmallButton data-a="draft:export" onClick={() => onNavigate?.('export')}>{t('draft.export_book')}</SmallButton>
                                </div>
                            </div>
                        )}
                    </section>
                )}

                {/* Every section, with its own controls */}
                {(accepted || run || !state.plan) && writable.length > 0 && (
                    <section data-a="draft:sections" className="space-y-2">
                        <h3 className="text-[13px] font-semibold text-text-primary">{t('draft.sections')}</h3>
                        <ul className="divide-y divide-border-divider rounded-tile border border-border-default">
                            {writable.map((s) => (
                                <li key={s.id} className="px-3 py-2">
                                    <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
                                        <button onClick={() => onOpenSection?.(s.id)} className="min-w-0 flex-1 truncate text-left text-text-primary hover:underline">
                                            {s.title}
                                        </button>
                                        <span className="text-[11px] text-text-secondary">{t('quickstart.words', { words: formatNumber(s.words) })}</span>
                                        {s.ai_draft_state === 'unreviewed' && (
                                            <>
                                                <span className="rounded-badge bg-status-warning-bg px-1.5 py-0.5 text-[10.5px] font-semibold text-status-warning">
                                                    {t('draft.unreviewed')}
                                                </span>
                                                <SmallButton data-a={`draft:accept-section:${s.id}`} disabled={busy === `accept:${s.id}`} onClick={() => acceptSection(s)}>
                                                    {t('draft.accept')}
                                                </SmallButton>
                                            </>
                                        )}
                                        {!running && (s.words === 0 ? (
                                            <SmallButton data-a={`draft:write-section:${s.id}`} disabled={busy === `section:${s.id}`} onClick={() => writeSection(s, 'write')}>
                                                {t('draft.write_chapter')}
                                            </SmallButton>
                                        ) : (
                                            <SmallButton data-a={`draft:new-version:${s.id}`} onClick={() => setNoteFor(noteFor === s.id ? null : s.id)}>
                                                {t('draft.new_version')}
                                            </SmallButton>
                                        ))}
                                    </div>
                                    {noteFor === s.id && (
                                        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                                            <input
                                                data-a={`draft:note:${s.id}`}
                                                value={notes[s.id] ?? ''}
                                                placeholder={t('draft.note_placeholder')}
                                                onChange={(e) => setNotes({ ...notes, [s.id]: e.target.value })}
                                                className="h-8 min-w-0 flex-1 rounded-control border border-border-input px-2 text-[12.5px]"
                                            />
                                            <SmallButton data-a={`draft:rewrite:${s.id}`} disabled={busy === `section:${s.id}`} onClick={() => writeSection(s, 'rewrite')}>
                                                {t('draft.write_new_version')}
                                            </SmallButton>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p className="text-[11px] leading-relaxed text-text-secondary">{t('draft.sections_note')}</p>
                    </section>
                )}
            </div>
        </div>
    );
}

/** The plan, editable: title, reader, promise, voice, then every section. */
function PlanReview({ plan, setPlan, meta, t, formatNumber, children }) {
    const set = (key, value) => setPlan({ ...plan, [key]: value });
    const total = useMemo(
        () =>
            [...plan.front_matter, ...plan.parts.flatMap((p) => p.chapters), ...plan.back_matter].reduce(
                (sum, s) => sum + (Number(s.words) || 0),
                0,
            ),
        [plan],
    );

    const editChapter = (pi, ci, key, value) =>
        setPlan({
            ...plan,
            parts: plan.parts.map((p, i) =>
                i !== pi ? p : { ...p, chapters: p.chapters.map((c, j) => (j !== ci ? c : { ...c, [key]: value })) },
            ),
        });

    const removeChapter = (pi, ci) =>
        setPlan({
            ...plan,
            parts: plan.parts
                .map((p, i) => (i !== pi ? p : { ...p, chapters: p.chapters.filter((_, j) => j !== ci) }))
                .filter((p) => p.chapters.length > 0),
        });

    const addChapter = (pi) =>
        setPlan({
            ...plan,
            parts: plan.parts.map((p, i) =>
                i !== pi ? p : { ...p, chapters: [...p.chapters, { title: t('draft.new_chapter'), purpose: '', key_points: [], words: 2000 }] },
            ),
        });

    return (
        <section data-a="draft:plan" className="space-y-3">
            <Step n={2} title={t('draft.step_plan')} />
            <p className="text-[11.5px] text-text-secondary">
                {t('draft.plan_meta', { words: formatNumber(total) })}
                {meta?.simulated ? ` · ${t('draft.sample_short')}` : meta?.model ? ` · ${meta.model}` : ''}
            </p>

            <Field label={t('draft.book_title')}>
                <div className="space-y-1">
                    {plan.title_options.map((option) => (
                        <label key={option} className="flex items-center gap-2 text-[13px] text-text-body">
                            <input type="radio" name="draft-title" checked={plan.title === option} onChange={() => set('title', option)} />
                            {option}
                        </label>
                    ))}
                    <input
                        value={plan.title}
                        onChange={(e) => set('title', e.target.value)}
                        className="h-9 w-full rounded-control border border-border-input px-3 text-[13px]"
                    />
                </div>
            </Field>
            <Field label={t('draft.subtitle')}>
                <input value={plan.subtitle} onChange={(e) => set('subtitle', e.target.value)} className="h-9 w-full rounded-control border border-border-input px-3 text-[13px]" />
            </Field>
            {['reader', 'promise', 'voice'].map((key) => (
                <Field key={key} label={t(`draft.${key}`)}>
                    <textarea rows={2} value={plan[key]} onChange={(e) => set(key, e.target.value)} className="w-full rounded-control border border-border-input px-3 py-1.5 text-[12.5px]" />
                </Field>
            ))}

            {[['style_sheet', 'draft.style_sheet'], ['accuracy_rules', 'draft.accuracy'], ['recurring_elements', 'draft.recurring']].map(([key, label]) =>
                plan[key].length > 0 ? (
                    <Field key={key} label={t(label)}>
                        <textarea
                            rows={Math.min(5, plan[key].length + 1)}
                            value={plan[key].join('\n')}
                            onChange={(e) => set(key, e.target.value.split('\n'))}
                            className="w-full rounded-control border border-border-input px-3 py-1.5 text-[12px]"
                        />
                    </Field>
                ) : null,
            )}

            {plan.characters.length > 0 && (
                <p className="text-[12px] text-text-body">
                    <span className="font-semibold">{t('draft.characters')}</span>{' '}
                    {plan.characters.map((c) => `${c.name} (${c.role})`).join(', ')}
                </p>
            )}

            <div className="space-y-3">
                {[...plan.front_matter.map((s) => ['front', s])].map(([, s]) => (
                    <p key={s.key} className="text-[12px] text-text-secondary">{t('draft.front')}: {s.title}</p>
                ))}
                {plan.parts.map((part, pi) => (
                    <div key={pi} className="rounded-tile border border-border-default">
                        {part.title && <p className="border-b border-border-divider px-3 py-1.5 text-[12px] font-semibold text-text-primary">{part.title}</p>}
                        <ol className="divide-y divide-border-divider">
                            {part.chapters.map((c, ci) => (
                                <li key={c.key ?? ci} className="space-y-1 px-3 py-2">
                                    <div className="flex items-center gap-2">
                                        <input
                                            data-a={`draft:chapter-title:${pi}:${ci}`}
                                            value={c.title}
                                            onChange={(e) => editChapter(pi, ci, 'title', e.target.value)}
                                            className="h-8 min-w-0 flex-1 rounded-control border border-border-input px-2 text-[12.5px] font-medium"
                                        />
                                        <input
                                            type="number"
                                            min={0}
                                            step={250}
                                            value={c.words}
                                            aria-label={t('draft.words_label')}
                                            onChange={(e) => editChapter(pi, ci, 'words', Number(e.target.value))}
                                            className="h-8 w-[84px] shrink-0 rounded-control border border-border-input px-2 text-[12px]"
                                        />
                                        <button onClick={() => removeChapter(pi, ci)} aria-label={t('draft.remove')} className="shrink-0 rounded p-1 text-text-secondary hover:bg-bg-subtle">
                                            <Icon name="x" size={14} />
                                        </button>
                                    </div>
                                    <textarea
                                        rows={2}
                                        value={c.purpose}
                                        onChange={(e) => editChapter(pi, ci, 'purpose', e.target.value)}
                                        className="w-full rounded-control border border-border-input px-2 py-1 text-[11.5px] text-text-secondary"
                                    />
                                    {c.key_points.length > 0 && (
                                        <p className="text-[11px] text-text-secondary">{c.key_points.join(' · ')}</p>
                                    )}
                                </li>
                            ))}
                        </ol>
                        <button onClick={() => addChapter(pi)} className="w-full border-t border-border-divider px-3 py-1.5 text-left text-[12px] font-semibold text-brand-primary hover:bg-bg-subtle">
                            + {t('draft.add_chapter')}
                        </button>
                    </div>
                ))}
                {plan.back_matter.map((s) => (
                    <p key={s.key} className="text-[12px] text-text-secondary">{t('draft.back')}: {s.title}</p>
                ))}
            </div>

            {children}
        </section>
    );
}

function Step({ n, title }) {
    return (
        <h3 className="flex items-center gap-2 text-[13px] font-semibold text-text-primary">
            <span className="grid h-5 w-5 place-items-center rounded-full bg-brand-primary text-[11px] text-white">{n}</span>
            {title}
        </h3>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11.5px] font-semibold text-text-secondary">{label}</span>
            {children}
        </label>
    );
}

function Primary({ children, ...props }) {
    return (
        <button
            {...props}
            className="h-10 rounded-control bg-brand-fill px-4 text-[13.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
        >
            {children}
        </button>
    );
}

function SmallButton({ children, ...props }) {
    return (
        <button
            {...props}
            className="h-7 shrink-0 rounded-control border border-border-default bg-bg-surface px-2 text-[11.5px] font-semibold text-text-primary transition hover:bg-bg-subtle disabled:opacity-50"
        >
            {children}
        </button>
    );
}

function Spinner() {
    return <span className="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-brand-primary border-t-transparent" />;
}
