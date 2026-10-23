import { useEffect, useState } from 'react';

/**
 * Citations for the open chapter.
 *
 * Shows the two things a bibliography normally hides: a claim resting on an
 * unverified source, and a citation attached to text the author has since
 * deleted. Both are computed on read rather than stored, because both can
 * become true without the citation itself changing.
 */
export default function CitationsTab({ sectionId, bookId, onManageLibrary }) {
    const [citations, setCitations] = useState([]);
    const [sources, setSources] = useState([]);
    const [loading, setLoading] = useState(true);
    const [adding, setAdding] = useState(false);

    useEffect(() => {
        if (!sectionId) return;

        let cancelled = false;
        setLoading(true);

        Promise.all([
            window.axios.get(`/sections/${sectionId}/citations`),
            window.axios.get(`/books/${bookId}/sources`),
        ])
            .then(([c, s]) => {
                if (cancelled) return;
                setCitations(c.data.citations);
                setSources(s.data.sources);
            })
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [sectionId, bookId]);

    const remove = async (citation) => {
        const res = await window.axios.delete(`/citations/${citation.id}`);
        setCitations(res.data.citations);
    };

    const needsSource = citations.filter((c) => c.status === 'needs_source').length;

    if (loading) {
        return <p className="p-3 text-[11px] text-text-secondary">Loading…</p>;
    }

    return (
        <div className="p-3">
            <div className="mb-3 flex items-center justify-between">
                <p className="text-[10px] font-bold uppercase tracking-wider text-text-secondary">
                    Citations
                </p>
                <button
                    onClick={() => setAdding((v) => !v)}
                    className="rounded bg-brand-fill px-2 py-1 text-[11px] font-bold text-white hover:bg-brand-primary-hover"
                >
                    {adding ? 'Cancel' : 'Add'}
                </button>
            </div>

            {needsSource > 0 && (
                <p className="mb-3 rounded bg-status-warning-bg px-2 py-1.5 text-[11px] font-semibold text-status-warning">
                    {needsSource} claim{needsSource === 1 ? '' : 's'} in this
                    chapter still {needsSource === 1 ? 'needs' : 'need'} a
                    source.
                </p>
            )}

            {adding && (
                <AddCitation
                    sectionId={sectionId}
                    sources={sources}
                    onAdded={(rows) => {
                        setCitations(rows);
                        setAdding(false);
                    }}
                    onManageLibrary={onManageLibrary}
                />
            )}

            {citations.length === 0 ? (
                <p className="px-1 text-[11px] leading-relaxed text-text-secondary">
                    No citations in this chapter yet. Add one to record what a
                    claim rests on.
                </p>
            ) : (
                <ul className="space-y-2">
                    {citations.map((c) => (
                        <li
                            key={c.id}
                            className="rounded-lg border border-border-default bg-bg-surface p-2.5"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <p className="text-[12px] font-semibold leading-snug text-text-primary">
                                    {c.claim}
                                </p>
                                <Status value={c.status} />
                            </div>

                            {c.source ? (
                                <p className="mt-1 text-[11px] text-text-secondary">
                                    {c.source.reference}
                                    {c.locator ? `, ${c.locator}` : ''}
                                </p>
                            ) : (
                                <p className="mt-1 text-[11px] italic text-text-secondary">
                                    No source attached.
                                </p>
                            )}

                            {c.rests_on_unverified_source && (
                                <p className="mt-1.5 rounded bg-status-warning-bg px-1.5 py-1 text-[10px] font-semibold text-status-warning">
                                    Marked supported, but its source is{' '}
                                    {c.source?.verification}.
                                </p>
                            )}

                            {c.stale && (
                                <p className="mt-1.5 rounded bg-bg-subtle px-1.5 py-1 text-[10px] font-semibold text-text-secondary">
                                    The cited text has changed since this was
                                    recorded.
                                </p>
                            )}

                            <button
                                onClick={() => remove(c)}
                                className="mt-1.5 text-[11px] font-semibold text-text-secondary hover:text-status-danger"
                            >
                                Remove
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <button
                onClick={onManageLibrary}
                className="mt-4 w-full rounded-lg border border-dashed border-border-input py-2 text-[11px] font-semibold text-text-secondary hover:border-border-input hover:text-text-primary"
            >
                Open the research library
            </button>
        </div>
    );
}

function Status({ value }) {
    const label = {
        needs_source: 'Needs source',
        supported: 'Supported',
        contradicted: 'Contradicted',
    }[value];

    const style = {
        needs_source: 'bg-status-warning-bg text-status-warning',
        supported: 'bg-status-success-bg text-status-success',
        contradicted: 'bg-status-danger-bg text-status-danger',
    }[value];

    return (
        <span
            className={`shrink-0 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide ${style}`}
        >
            {label}
        </span>
    );
}

function AddCitation({ sectionId, sources, onAdded, onManageLibrary }) {
    const [claim, setClaim] = useState('');
    const [sourceId, setSourceId] = useState('');
    const [locator, setLocator] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);

        try {
            const res = await window.axios.post(`/sections/${sectionId}/citations`, {
                claim,
                locator: locator || null,
                research_source_id: sourceId || null,
                // Without a source this can only be a claim awaiting one —
                // the API and the database both refuse anything else.
                status: sourceId ? 'supported' : 'needs_source',
            });
            onAdded(res.data.citations);
        } catch (err) {
            setError(err.response?.data?.message ?? 'That citation could not be saved.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={submit} className="mb-3 rounded-lg bg-bg-subtle p-2.5">
            <textarea
                value={claim}
                onChange={(e) => setClaim(e.target.value)}
                required
                rows={2}
                placeholder="The claim being made…"
                className="w-full rounded border-border-input text-[12px] focus:border-brand-primary focus:ring-brand-primary"
            />

            {sources.length === 0 ? (
                <p className="mt-2 text-[11px] leading-relaxed text-text-secondary">
                    The library is empty, so this will be recorded as needing a
                    source.{' '}
                    <button
                        type="button"
                        onClick={onManageLibrary}
                        className="font-semibold text-brand-primary hover:underline"
                    >
                        Add sources
                    </button>
                </p>
            ) : (
                <select
                    value={sourceId}
                    onChange={(e) => setSourceId(e.target.value)}
                    className="mt-2 w-full rounded border-border-input text-[12px] focus:border-brand-primary focus:ring-brand-primary"
                >
                    <option value="">No source yet</option>
                    {sources.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.reference}
                            {s.verification !== 'verified'
                                ? ` (${s.verification})`
                                : ''}
                        </option>
                    ))}
                </select>
            )}

            <input
                value={locator}
                onChange={(e) => setLocator(e.target.value)}
                placeholder="p. 214"
                className="mt-2 w-full rounded border-border-input text-[12px] focus:border-brand-primary focus:ring-brand-primary"
            />

            {error && (
                <p className="mt-2 text-[11px] font-semibold text-status-danger">{error}</p>
            )}

            <button
                disabled={saving}
                className="mt-2 w-full rounded bg-bg-inverse py-1.5 text-[11px] font-bold text-text-inverse hover:opacity-90 disabled:opacity-60"
            >
                {saving ? 'Saving…' : 'Record citation'}
            </button>
        </form>
    );
}
