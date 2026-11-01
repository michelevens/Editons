import { useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * The fact-checker's verdict on a finding (ADR — AI research, source-backed
 * fact-checking, cover templates): supported, contradicted or unverified,
 * the saved source and its quoted passage, and web candidates when the
 * author asked for a web check.
 *
 * "Attach citation" records the citation through the existing flow, and the
 * editor puts its marker into the prose as the author's own edit. Nothing
 * is cited without the click.
 */
export default function FindingEvidence({ finding, sectionId, onCite = null }) {
    const { t } = useI18n();
    const e = finding.evidence;
    const [done, setDone] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const tone = {
        supported: 'bg-status-success-bg text-status-success',
        contradicted: 'bg-status-danger-bg text-status-danger',
        unverified: 'bg-status-warning-bg text-status-warning',
    }[e.verdict] ?? 'bg-bg-subtle text-text-secondary';

    const label = {
        supported: t('evidence.supported'),
        contradicted: t('evidence.contradicted'),
        unverified: t('evidence.unverified'),
    }[e.verdict] ?? e.verdict;

    const cite = async (sourceId, status) => {
        setBusy(true);
        setError(null);

        try {
            const res = await window.axios.post(`/sections/${sectionId}/citations`, {
                claim: finding.excerpt,
                research_source_id: sourceId,
                excerpt: finding.excerpt,
                status,
            });
            setDone(true);
            if (res.data.marker) onCite?.(res.data.marker);
        } catch (err) {
            setError(err.response?.data?.message ?? t('citations.save_failed'));
        } finally {
            setBusy(false);
        }
    };

    const saveAndCite = async (result) => {
        setBusy(true);

        try {
            const res = await window.axios.post(`/research-runs/${e.candidates.run_id}/save`, { indexes: [result.index] });
            const source = res.data.saved.find((s) => s.url === result.url);
            setBusy(false);
            if (source) await cite(source.id, 'supported');
        } catch (err) {
            setError(err.response?.data?.message ?? t('citations.save_failed'));
            setBusy(false);
        }
    };

    return (
        <div data-a="evidence" className="mt-1.5 rounded border border-border-default p-2">
            <span data-a={`evidence:${e.verdict}`} className={`inline-block rounded px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide ${tone}`}>
                {label}
            </span>

            {e.source_id ? (
                <div className="mt-1">
                    <p className="text-[11px] text-text-secondary">
                        {e.source_url ? (
                            <a href={e.source_url} target="_blank" rel="noopener noreferrer nofollow" className="font-semibold text-brand-primary hover:underline">{e.source_reference}</a>
                        ) : (
                            <span className="font-semibold">{e.source_reference}</span>
                        )}
                        {e.source_verification !== 'verified' && ` · ${t('research.standing_unverified')}`}
                    </p>
                    {e.source_excerpt && <q className="mt-0.5 block text-[11px] italic text-text-primary">{e.source_excerpt}</q>}
                    {done ? (
                        <p className="mt-1 text-[11px] font-semibold text-status-success">{t('evidence.cited')}</p>
                    ) : (
                        <button
                            data-a={`evidence:cite:${finding.id}`}
                            disabled={busy || !finding.excerpt}
                            onClick={() => cite(e.source_id, e.verdict === 'contradicted' ? 'contradicted' : 'supported')}
                            className="mt-1 text-[11px] font-semibold text-brand-primary hover:underline disabled:opacity-50"
                        >
                            {t('evidence.attach')}
                        </button>
                    )}
                </div>
            ) : (
                <p className="mt-1 text-[11px] text-text-secondary">{t('evidence.no_source')}</p>
            )}

            {e.candidates?.results?.length > 0 && !done && (
                <div className="mt-1.5">
                    <p className="text-[10.5px] font-semibold text-text-secondary">{t('evidence.candidates')}</p>
                    <ul className="mt-1 space-y-1">
                        {e.candidates.results.map((r) => (
                            <li key={r.index} className="text-[11px]">
                                <a href={r.url} target="_blank" rel="noopener noreferrer nofollow" className="break-words font-semibold text-brand-primary hover:underline">{r.title}</a>
                                {r.excerpt && <q className="block italic text-text-secondary">{r.excerpt}</q>}
                                <button disabled={busy} onClick={() => saveAndCite(r)} className="font-semibold text-brand-primary hover:underline disabled:opacity-50">
                                    {t('airesearch.save_and_cite')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {error && <p className="mt-1 text-[11px] font-semibold text-status-danger">{error}</p>}
        </div>
    );
}

/** Re-run the fact-checker with web search for what the sources leave unverified. */
export function FactCheckWeb({ running, onRun }) {
    const { t } = useI18n();

    return (
        <div className="mt-2 rounded-tile bg-bg-subtle px-2.5 py-2">
            <p className="text-[11px] leading-relaxed text-text-secondary">{t('evidence.web_intro')}</p>
            <button
                data-a="evidence:web"
                disabled={running}
                onClick={onRun}
                className="mt-1 text-[11.5px] font-semibold text-brand-primary hover:underline disabled:opacity-50"
            >
                {running ? t('airesearch.searching') : t('evidence.web_run')}
            </button>
        </div>
    );
}
