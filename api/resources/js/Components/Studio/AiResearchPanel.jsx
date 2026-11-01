import { useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * "Research this topic" and "Find sources for this claim" (ADR — AI
 * research, source-backed fact-checking, cover templates).
 *
 * Results are only sources the AI actually retrieved, each with a quoted
 * excerpt and what it supports. The author ticks the ones to keep; saving
 * adds them to the library unverified, with their provenance. Sample
 * results (no AI configured) say so on every row.
 *
 * claim: when given, the panel asks about that claim only (from a text
 * selection) and can cite a saved source in one click through onCite.
 */
export default function AiResearchPanel({ bookId, chapters = [], sectionId = null, claim = null, onSaved, onCite = null, compact = false }) {
    const { t } = useI18n();
    const [mode, setMode] = useState(claim ? 'claim' : 'topic');
    const [question, setQuestion] = useState('');
    const [chapterId, setChapterId] = useState(sectionId ?? chapters[0]?.id ?? '');
    const [busy, setBusy] = useState(false);
    const [run, setRun] = useState(null);
    const [picked, setPicked] = useState(new Set());
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const ask = async () => {
        setBusy(true);
        setError(null);
        setRun(null);
        setPicked(new Set());

        try {
            const payload = { mode };
            if (mode === 'claim') Object.assign(payload, { question: claim, section_id: sectionId });
            if (mode === 'topic') payload.question = question.trim();
            if (mode === 'chapter') Object.assign(payload, { section_id: Number(chapterId), question: question.trim() || null });
            if (mode === 'plan') payload.question = question.trim() || null;

            const res = await window.axios.post(`/books/${bookId}/research/runs`, payload);
            setRun(res.data.run);
        } catch (err) {
            const failed = err.response?.data?.run;
            if (failed) setRun(failed);
            setError(
                failed?.error
                    ?? err.response?.data?.errors?.credits?.[0]
                    ?? err.response?.data?.errors?.question?.[0]
                    ?? err.response?.data?.message
                    ?? t('airesearch.failed'),
            );
        } finally {
            setBusy(false);
        }
    };

    const toggle = (index) => setPicked((current) => {
        const next = new Set(current);
        next.has(index) ? next.delete(index) : next.add(index);

        return next;
    });

    const save = async (indexes = [...picked]) => {
        if (!indexes.length) return null;
        setSaving(true);

        try {
            const res = await window.axios.post(`/research-runs/${run.id}/save`, { indexes });
            setRun((current) => ({
                ...current,
                results: current.results.map((r) => (indexes.includes(r.index) ? { ...r, saved: true } : r)),
            }));
            setPicked(new Set());
            onSaved?.(res.data);

            return res.data;
        } catch (err) {
            setError(err.response?.data?.message ?? t('airesearch.failed'));

            return null;
        } finally {
            setSaving(false);
        }
    };

    /** Save this source (if needed) and cite it for the selected claim. */
    const cite = async (result) => {
        const data = await save([result.index]);
        const source = data?.saved?.find((s) => s.url === result.url);
        if (source) onCite?.(source);
    };

    const canAsk = !busy && (mode === 'claim' ? Boolean(claim) : mode === 'topic' ? question.trim() !== '' : mode === 'chapter' ? Boolean(chapterId) : true);
    const results = run?.results ?? [];

    return (
        <section data-a="airesearch:panel" className={`rounded-lg border border-border-default bg-bg-surface ${compact ? 'p-2.5' : 'mb-5 p-4'}`}>
            {!compact && (
                <>
                    <h3 className="text-sm font-bold text-text-primary">{t('airesearch.title')}</h3>
                    <p className="mt-0.5 text-xs text-text-secondary">{t('airesearch.intro')}</p>
                </>
            )}

            {claim ? (
                <p className="text-[11px] text-text-secondary">
                    {t('airesearch.claim_label')} <q className="font-semibold text-text-primary">{claim}</q>
                </p>
            ) : (
                <div className="mt-3 flex flex-wrap gap-2">
                    <select
                        value={mode}
                        data-a="airesearch:mode"
                        aria-label={t('airesearch.mode')}
                        onChange={(e) => setMode(e.target.value)}
                        className="rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                    >
                        <option value="topic">{t('airesearch.mode_topic')}</option>
                        {chapters.length > 0 && <option value="chapter">{t('airesearch.mode_chapter')}</option>}
                        <option value="plan">{t('airesearch.mode_plan')}</option>
                    </select>
                    {mode === 'chapter' && (
                        <select
                            value={chapterId}
                            data-a="airesearch:chapter"
                            aria-label={t('airesearch.mode_chapter')}
                            onChange={(e) => setChapterId(e.target.value)}
                            className="min-w-0 max-w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                        >
                            {chapters.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
                        </select>
                    )}
                    <input
                        value={question}
                        data-a="airesearch:question"
                        onChange={(e) => setQuestion(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && canAsk && ask()}
                        placeholder={mode === 'topic' ? t('airesearch.question_placeholder') : t('airesearch.question_optional')}
                        aria-label={t('airesearch.question_placeholder')}
                        className="min-w-0 flex-1 basis-60 rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                    />
                </div>
            )}

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <button
                    data-a="airesearch:ask"
                    disabled={!canAsk}
                    onClick={ask}
                    className="rounded-md bg-brand-fill px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy ? t('airesearch.searching') : claim ? t('airesearch.find_for_claim') : t('airesearch.ask')}
                </button>
                <span className="text-[11px] text-text-secondary">{t('airesearch.cost_note')}</span>
            </div>

            {error && (
                <p role="alert" data-a="airesearch:error" className="mt-2 rounded-md bg-status-danger-bg px-2 py-1.5 text-xs text-status-danger">{error}</p>
            )}

            {run && run.status === 'completed' && (
                <div className="mt-3">
                    {run.simulated && (
                        <p data-a="airesearch:sample" className="mb-2 rounded bg-status-warning-bg px-2 py-1 text-[11px] font-semibold text-status-warning">
                            {t('airesearch.sample_notice')}
                        </p>
                    )}
                    {results.length === 0 ? (
                        <p className="text-xs text-text-secondary">{t('airesearch.none')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {results.map((r) => (
                                <li key={r.index} data-a="airesearch:result" className="rounded-md border border-border-default p-2.5">
                                    <label className="flex items-start gap-2">
                                        <input
                                            type="checkbox"
                                            checked={picked.has(r.index) || r.saved}
                                            disabled={r.saved}
                                            onChange={() => toggle(r.index)}
                                            className="mt-0.5 rounded border-border-input"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <a href={r.url} target="_blank" rel="noopener noreferrer nofollow" className="break-words text-sm font-semibold text-brand-primary hover:underline">{r.title}</a>
                                            <span className="block break-words text-[11px] text-text-secondary">
                                                {r.publisher}{r.published ? ` · ${r.published}` : ''}
                                            </span>
                                            {r.excerpt && <q className="mt-1 block text-xs italic text-text-primary">{r.excerpt}</q>}
                                            {r.summary && <span className="mt-1 block text-xs text-text-secondary">{r.summary}</span>}
                                        </span>
                                    </label>
                                    <div className="mt-1.5 flex flex-wrap gap-3 pl-6 text-[11px] font-semibold">
                                        {r.saved && <span className="text-status-success">{t('airesearch.saved')}</span>}
                                        {onCite && (
                                            <button data-a="airesearch:cite" disabled={saving} onClick={() => cite(r)} className="text-brand-primary hover:underline">
                                                {t('airesearch.save_and_cite')}
                                            </button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {results.some((r) => !r.saved) && (
                        <button
                            data-a="airesearch:save"
                            disabled={saving || picked.size === 0}
                            onClick={() => save()}
                            className="mt-2 rounded-md border border-border-input px-3 py-1.5 text-sm font-semibold text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                        >
                            {t('airesearch.save_picked', { count: picked.size })}
                        </button>
                    )}
                    <p className="mt-2 text-[11px] text-text-secondary">
                        {t('airesearch.provenance_note', { credits: run.credits ?? 0 })}
                    </p>
                </div>
            )}
        </section>
    );
}
