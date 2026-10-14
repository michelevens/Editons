import { useCallback, useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * The bibliography, and where each source is actually cited.
 *
 * The second table is the part worth having. A list of sources says what an
 * author collected; a list of sources nothing cites says which ones they
 * never used — the question that matters before publication.
 */
export default function ReferencesPanel({ bookId, onOpenSection }) {
    const [style, setStyle] = useState('APA');
    const [data, setData] = useState(null);
    const [copied, setCopied] = useState(false);

    const load = useCallback(async () => {
        const response = await window.axios.get(
            `/books/${bookId}/references?style=${style}`,
        );

        setData(response.data);
    }, [bookId, style]);

    useEffect(() => {
        load();
    }, [load]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(data.text);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2400);
        } catch {
            // Clipboard access is denied in plenty of ordinary situations —
            // an insecure origin, a browser setting, a headless run. Select
            // the text instead so copying by hand still works.
            const node = document.getElementById('bibliography-text');

            if (node) {
                const range = document.createRange();
                range.selectNodeContents(node);
                window.getSelection()?.removeAllRanges();
                window.getSelection()?.addRange(range);
            }
        }
    };

    if (!data) {
        return <p className="p-8 text-center text-[13px] text-text-secondary">Loading…</p>;
    }

    const uncited = data.usage.filter((row) => row.citation_count === 0);

    return (
        <div className="mx-auto max-w-[1040px] p-8">
            <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">
                        References &amp; Citations
                    </h2>
                    <p className="mt-1.5 text-[14px] text-text-secondary">
                        Your bibliography, and where each source is doing work.
                    </p>
                </div>

                <div className="flex items-center gap-2.5">
                    <label className="flex items-center gap-2 text-[12.5px] text-text-secondary">
                        Style
                        <select
                            value={style}
                            data-a="references:style"
                            onChange={(event) => setStyle(event.target.value)}
                            className="h-[34px] rounded-control border border-border-input px-2.5 text-[13px] focus:border-brand-primary focus:ring-0"
                        >
                            {data.styles.map((option) => (
                                <option key={option}>{option}</option>
                            ))}
                        </select>
                    </label>

                    <button
                        data-a="references:copy"
                        onClick={copy}
                        disabled={data.entries.length === 0}
                        className="h-[34px] rounded-control bg-brand-primary px-3.5 text-[13px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        {copied ? 'Copied' : 'Copy bibliography'}
                    </button>
                </div>
            </header>

            <section className="rounded-panel border border-border-default bg-bg-surface p-5">
                <h3 className="text-[15px] font-semibold text-text-primary">
                    Bibliography — {data.style}
                </h3>

                {data.entries.length === 0 ? (
                    <p className="mt-3 text-[13px] text-text-secondary">
                        No sources yet. Add them in the Research Library and
                        they appear here.
                    </p>
                ) : (
                    <ol
                        id="bibliography-text"
                        className="mt-3.5 space-y-2.5 text-[13.5px] leading-relaxed text-text-body"
                    >
                        {data.entries.map((entry) => (
                            <li key={entry.id} className="flex gap-2.5">
                                <span className="min-w-0 flex-1">{entry.entry}</span>
                                {entry.verification !== 'verified' && (
                                    <span
                                        title="This source has not been verified."
                                        className="mt-0.5 shrink-0 text-status-warning"
                                    >
                                        <Icon name="info" size={14} />
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                )}

                <p className="mt-4 text-[11px] leading-relaxed text-text-secondary">
                    An approximation of {data.style}, enough to hand a publisher
                    a starting point. It is not a substitute for a style manual.
                </p>
            </section>

            <section className="mt-5 rounded-panel border border-border-default bg-bg-surface">
                <header className="flex items-baseline justify-between border-b border-border-divider px-5 py-3.5">
                    <h3 className="text-[15px] font-semibold text-text-primary">
                        Where each source is cited
                    </h3>
                    {uncited.length > 0 && (
                        <span className="text-[12px] text-status-warning">
                            {uncited.length} cited nowhere
                        </span>
                    )}
                </header>

                {data.usage.length === 0 ? (
                    <p className="px-5 py-6 text-[13px] text-text-secondary">
                        Nothing to show yet.
                    </p>
                ) : (
                    <ul className="divide-y divide-border-divider">
                        {data.usage.map((row) => (
                            <li key={row.id} className="px-5 py-3.5">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="text-[13.5px] font-medium text-text-primary">
                                        {row.title}
                                    </span>
                                    <span className="text-[12px] text-text-secondary">
                                        {row.reference}
                                    </span>
                                </div>

                                {row.cited_in.length === 0 ? (
                                    <p className="mt-1.5 text-[12.5px] italic text-text-secondary">
                                        Not cited anywhere yet.
                                    </p>
                                ) : (
                                    <ul className="mt-2 flex flex-wrap gap-1.5">
                                        {row.cited_in.map((use) => (
                                            <li key={use.section_id}>
                                                <button
                                                    data-a={`references:open:${use.section_id}`}
                                                    onClick={() => onOpenSection?.(use.section_id)}
                                                    title={use.claim}
                                                    className="rounded-badge bg-brand-primary-subtle px-2.5 py-1 text-[11.5px] font-medium text-brand-primary-hover transition hover:opacity-80"
                                                >
                                                    {use.section_title}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
