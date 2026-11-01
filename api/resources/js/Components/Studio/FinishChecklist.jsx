import { useCallback, useEffect, useState } from 'react';
import { useI18n } from '@/i18n';
import StoreCopyCard from './StoreCopyCard';

/**
 * "Finish my book" (ADR — AI research, source-backed fact-checking, cover
 * templates): the remaining AI and publishing steps on a drafted book, in
 * order, with the cost estimate first. Each step runs through its own
 * existing flow and approval; nothing is applied silently. Self-contained:
 * the AI book writer's "Write my book" can link to the publish pane.
 */
export default function FinishChecklist({ bookId, onNavigate, onNotify }) {
    const { t } = useI18n();
    const [data, setData] = useState(null);
    const [open, setOpen] = useState(null);
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        const res = await window.axios.get(`/books/${bookId}/finish`);
        setData(res.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const run = async (key, fn) => {
        setBusy(key);

        try {
            await fn();
            await load();
        } catch (err) {
            const errors = err.response?.data?.errors ?? {};
            onNotify?.(Object.values(errors)[0]?.[0] ?? err.response?.data?.message ?? t('finish.failed'));
        } finally {
            setBusy(null);
        }
    };

    /** A whole-book review per agent, through the review planner's own approval. */
    const review = (step) => run(step.key, async () => {
        for (const agent of step.agents.filter((a) => !a.done)) {
            if (agent.needs_approval && !confirm(t('finish.approve_review', { credits: agent.credits }))) return;
            await window.axios.post(`/books/${bookId}/reviews`, { agent: agent.agent, scope: 'book', approved: agent.needs_approval || undefined });
        }
        onNotify?.(t('finish.review_started'));
    });

    const cover = () => run('cover', async () => {
        const { data: s } = await window.axios.post(`/books/${bookId}/cover/starter/suggest`);
        const { suggestion } = s;
        if (!confirm(t('finish.cover_confirm', { template: t(`starter.tpl_${suggestion.template}`) }))) return;
        await window.axios.post(`/books/${bookId}/cover/starter`, { template: suggestion.template, palette: suggestion.palette, pairing: suggestion.pairing, motif: suggestion.motif });
        onNotify?.(t('starter.applied'));
    });

    const exportFiles = (step) => run('export', async () => {
        for (const format of ['pdf', 'epub'].filter((f) => !step.formats[f])) {
            await window.axios.post(`/books/${bookId}/exports`, { format });
        }
        onNotify?.(t('finish.export_started'));
    });

    if (!data) return null;

    const cost = (step) => {
        if (step.status !== 'todo') return null;
        if (step.cost === 'free') return t('finish.cost_free');
        if (step.cost === 'sample') return t('finish.cost_sample');
        if (step.cost === 'max') return t('finish.cost_max', { credits: step.credits });

        return t('finish.cost_estimate', { credits: step.credits });
    };

    const action = (step) => {
        if (step.status === 'skipped') return null;
        const button = (label, onClick) => (
            <button
                type="button"
                data-a={`finish:run:${step.key}`}
                disabled={busy !== null}
                onClick={onClick}
                className="min-h-[30px] rounded-control border border-brand-primary px-2.5 text-[11.5px] font-semibold text-brand-primary disabled:opacity-50"
            >
                {busy === step.key ? t('finish.working') : label}
            </button>
        );

        return {
            review: step.status === 'todo' ? button(t('finish.run_review'), () => review(step)) : button(t('finish.see_findings'), () => onNavigate?.('manuscript')),
            fact_check: step.status === 'todo' ? button(t('finish.run_fact_check'), () => review(step)) : button(t('finish.see_findings'), () => onNavigate?.('manuscript')),
            matter: button(t('finish.open_parts'), () => onNavigate?.('settings')),
            cover: step.status === 'todo' ? button(t('finish.suggest_cover'), cover) : button(t('finish.open_cover'), () => onNavigate?.('cover')),
            store_copy: button(open === 'store_copy' ? t('finish.close') : t('finish.open_store_copy'), () => setOpen(open === 'store_copy' ? null : 'store_copy')),
            checks: button(t('finish.open_checks'), () => onNavigate?.('publish')),
            export: step.status === 'todo' ? button(t('finish.make_files'), () => exportFiles(step)) : button(t('finish.open_export'), () => onNavigate?.('export')),
        }[step.key];
    };

    return (
        <section data-a="finish" className="mb-4 rounded-tile border border-border-default bg-bg-surface p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="text-[14px] font-semibold text-text-primary">{t('finish.title')}</h3>
                <p data-a="finish:total" className="text-[11.5px] text-text-secondary">
                    {t('finish.progress', { done: data.done, total: data.steps.length })} · {t('finish.total', { credits: data.total_credits })}
                </p>
            </div>
            <p className="mt-0.5 text-[11.5px] text-text-secondary">{t('finish.intro')}</p>

            <ol className="mt-2 divide-y divide-border-divider">
                {data.steps.map((step, i) => (
                    <li key={step.key} data-a={`finish:step:${step.key}`} data-status={step.status} className="py-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                aria-hidden="true"
                                className={`grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] font-bold ${step.status === 'done' ? 'bg-status-success-bg text-status-success' : step.status === 'skipped' ? 'bg-bg-subtle text-text-secondary' : 'bg-brand-primary-subtle text-brand-primary'}`}
                            >
                                {step.status === 'done' ? '✓' : i + 1}
                            </span>
                            <span className="min-w-0 flex-1 text-[12.5px] font-medium text-text-primary">{t(`finish.step_${step.key}`)}</span>
                            <span className="text-[11px] text-text-secondary">
                                {step.status === 'done' ? t('finish.done') : step.status === 'skipped' ? t('finish.skipped_fiction') : cost(step)}
                            </span>
                            {action(step)}
                        </div>
                        {step.open_findings > 0 && (
                            <p className="ml-7 mt-0.5 text-[11px] text-text-secondary">{t('finish.open_findings', { count: step.open_findings })}</p>
                        )}
                        {step.key === 'store_copy' && open === 'store_copy' && (
                            <div className="ml-7 mt-2">
                                <StoreCopyCard bookId={bookId} onNotify={onNotify} onChanged={load} />
                            </div>
                        )}
                    </li>
                ))}
            </ol>
        </section>
    );
}
