import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * Every finding in the book.
 *
 * The studio's panel answers "what is wrong with this chapter". This answers
 * "what is left before the book is done" — an author clearing findings works
 * across chapters, not down one.
 */

const SEVERITY = {
    critical: { dot: 'bg-status-danger', label: 'Critical' },
    warning: { dot: 'bg-status-warning', label: 'Warning' },
    suggestion: { dot: 'bg-status-info', label: 'Suggestion' },
};

export default function AiEditorScreen({ bookId, onOpenSection, onNotify, agent = null }) {
    // Heuristics rather than a model: said so above the findings.
    const simulated = usePage().props.editorial?.simulated;

    const [data, setData] = useState(null);
    const [filters, setFilters] = useState({ agent, status: 'open' });
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        const params = new URLSearchParams();

        if (filters.agent) params.set('agent', filters.agent);
        if (filters.status) params.set('status', filters.status);

        const response = await window.axios.get(
            `/books/${bookId}/findings?${params.toString()}`,
        );

        setData(response.data);
    }, [bookId, filters.agent, filters.status]);

    useEffect(() => {
        load();
    }, [load]);

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

    if (!data) {
        return <p className="p-8 text-center text-[13px] text-text-secondary">Loading…</p>;
    }

    const statusTabs = [
        { key: 'open', label: 'Open', count: data.counts.open },
        { key: 'accepted', label: 'Applied', count: data.counts.accepted },
        { key: 'dismissed', label: 'Dismissed', count: data.counts.dismissed },
        { key: 'all', label: 'All', count: data.counts.all },
    ];

    return (
        <div className="mx-auto max-w-[1040px] p-8">
            <header className="mb-6">
                <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">
                    AI Editor
                </h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    {data.counts.open === 0
                        ? 'Nothing open across the book.'
                        : `${data.counts.open} open across the book${
                              data.counts.critical
                                  ? `, ${data.counts.critical} critical`
                                  : ''
                          }.`}
                </p>
                {simulated && (
                    <p
                        data-a="ai-screen:sample-notice"
                        className="mt-3 rounded-tile border border-dashed border-border-default px-3 py-2 text-[12px] leading-relaxed text-text-secondary"
                    >
                        <b className="text-text-primary">Sample output.</b> Reviews here
                        run automated checks, not an AI model, and use no credits.
                    </p>
                )}
            </header>

            <div className="mb-4 flex flex-wrap gap-1.5">
                {statusTabs.map((tab) => (
                    <button
                        key={tab.key}
                        data-a={`ai-screen:status:${tab.key}`}
                        onClick={() => setFilters((f) => ({ ...f, status: tab.key }))}
                        className={`rounded-badge px-3 py-1.5 text-[12.5px] font-medium transition ${
                            filters.status === tab.key
                                ? 'bg-brand-primary text-white'
                                : 'bg-bg-surface text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {tab.label}
                        <span className="ml-1.5 opacity-70">{tab.count}</span>
                    </button>
                ))}
            </div>

            <div className="mb-5 flex flex-wrap gap-1.5">
                <button
                    data-a="ai-screen:agent:all"
                    onClick={() => setFilters((f) => ({ ...f, agent: null }))}
                    className={`rounded-badge border px-2.5 py-1 text-[11.5px] transition ${
                        filters.agent === null
                            ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary-hover'
                            : 'border-border-default text-text-secondary hover:text-text-primary'
                    }`}
                >
                    Every agent
                </button>

                {data.agents.map((agent) => (
                    <button
                        key={agent.value}
                        data-a={`ai-screen:agent:${agent.value}`}
                        onClick={() => setFilters((f) => ({ ...f, agent: agent.value }))}
                        className={`rounded-badge border px-2.5 py-1 text-[11.5px] transition ${
                            filters.agent === agent.value
                                ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary-hover'
                                : 'border-border-default text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {agent.label}
                        {agent.open > 0 && (
                            <span className="ml-1.5 font-semibold">{agent.open}</span>
                        )}
                    </button>
                ))}
            </div>

            {data.findings.length === 0 ? (
                <p className="rounded-panel border border-dashed border-border-default p-10 text-center text-[13.5px] text-text-secondary">
                    {filters.status === 'open'
                        ? 'Nothing open here. Run an agent from a chapter to review it.'
                        : 'Nothing to show under this filter.'}
                </p>
            ) : (
                <ul className="space-y-2">
                    {data.findings.map((finding) => (
                        <li
                            key={finding.id}
                            className="rounded-panel border border-border-default bg-bg-surface p-4"
                        >
                            <div className="flex items-start gap-3">
                                <span
                                    className={`mt-[7px] h-2 w-2 shrink-0 rounded-full ${
                                        SEVERITY[finding.severity]?.dot ?? 'bg-border-input'
                                    }`}
                                />

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-baseline gap-x-2.5">
                                        <p className="text-[13.5px] font-semibold text-text-primary">
                                            {finding.title}
                                        </p>
                                        <button
                                            data-a={`ai-screen:open:${finding.section_id}`}
                                            onClick={() => onOpenSection?.(finding.section_id)}
                                            className="text-[12px] text-brand-primary hover:underline"
                                        >
                                            {finding.section_title}
                                        </button>
                                        <span className="text-[11px] uppercase tracking-wide text-text-secondary">
                                            {finding.agent_label}
                                        </span>
                                    </div>

                                    {finding.detail && (
                                        <p className="mt-1 text-[12.5px] leading-relaxed text-text-secondary">
                                            {finding.detail}
                                        </p>
                                    )}

                                    {finding.excerpt && (
                                        <p className="mt-1.5 border-l-2 border-border-default pl-2.5 text-[12.5px] italic leading-snug text-text-secondary">
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
                                            </>
                                        ) : (
                                            <>
                                                <span className="text-[12px] text-text-secondary">
                                                    {finding.status === 'accepted'
                                                        ? 'Applied'
                                                        : 'Dismissed'}
                                                </span>
                                                <button
                                                    data-a={`ai-screen:reopen:${finding.id}`}
                                                    disabled={busy === finding.id}
                                                    onClick={() => reopen(finding)}
                                                    className="text-[12.5px] font-semibold text-brand-primary hover:underline disabled:opacity-50"
                                                >
                                                    {/* An applied fix is taken back out of
                                                        the text before it reopens. */}
                                                    {finding.status === 'accepted' && finding.suggestion
                                                        ? 'Undo fix'
                                                        : 'Reopen'}
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>

                                <span className="shrink-0 text-text-secondary">
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
