import { useEffect, useState } from 'react';

/**
 * Characters, terms and their agreed spellings — plus the cross-chapter scan
 * that uses them.
 *
 * The vocabulary is declared by the author, never inferred. The empty state
 * says so, because a reader who expects the software to work it out would
 * otherwise read "no issues" as "no inconsistencies exist".
 */
export default function ConsistencyPanel({ bookId, onOpenSection }) {
    const [terms, setTerms] = useState([]);
    const [issues, setIssues] = useState([]);
    const [loading, setLoading] = useState(true);
    const [adding, setAdding] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const res = await window.axios.get(`/books/${bookId}/consistency`);
            setTerms(res.data.terms);
            setIssues(res.data.issues);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, [bookId]);

    const remove = async (term) => {
        if (!confirm(`Remove "${term.canonical}" from the vocabulary?`)) return;
        const res = await window.axios.delete(`/terms/${term.id}`);
        setTerms(res.data.terms);
        setIssues(res.data.issues);
    };

    return (
        <div className="mx-auto max-w-4xl p-8">
            <header className="mb-6 flex items-baseline justify-between">
                <div>
                    <h2 className="font-serif text-2xl font-bold text-slate-900">
                        Characters &amp; Terms
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        The spellings that are correct for this book, and
                        anywhere the manuscript disagrees with them.
                    </p>
                </div>
                <button
                    onClick={() => setAdding((v) => !v)}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700"
                >
                    {adding ? 'Cancel' : 'Add term'}
                </button>
            </header>

            {adding && (
                <AddTerm
                    bookId={bookId}
                    onAdded={(data) => {
                        setTerms(data.terms);
                        setIssues(data.issues);
                        setAdding(false);
                    }}
                />
            )}

            <section className="mb-8">
                <h3 className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">
                    Inconsistencies across the book
                </h3>

                {loading ? (
                    <p className="text-sm text-slate-500">Scanning…</p>
                ) : terms.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm leading-relaxed text-slate-500">
                        Nothing to check yet. This scan compares the manuscript
                        against terms you declare — it does not guess which
                        spelling is canonical, because guessing would
                        eventually "correct" a deliberate one.
                    </p>
                ) : issues.length === 0 ? (
                    <p className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        No disagreements found for the {terms.length} term
                        {terms.length === 1 ? '' : 's'} declared below.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {issues.map((issue, i) => (
                            <li
                                key={`${issue.term_id}-${issue.variant}-${i}`}
                                className="rounded-lg border border-amber-200 bg-amber-50 p-4"
                            >
                                <p className="text-sm text-amber-900">
                                    <span className="font-bold">
                                        “{issue.variant}”
                                    </span>{' '}
                                    appears {issue.total} time
                                    {issue.total === 1 ? '' : 's'}, but this
                                    book uses{' '}
                                    <span className="font-bold">
                                        “{issue.canonical}”
                                    </span>
                                    .
                                </p>

                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {issue.sections.map((s) => (
                                        <li key={s.section_id}>
                                            <button
                                                onClick={() =>
                                                    onOpenSection?.(s.section_id)
                                                }
                                                className="rounded bg-white px-2 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100"
                                            >
                                                {s.section_title} ×
                                                {s.occurrences}
                                            </button>
                                        </li>
                                    ))}
                                </ul>

                                {issue.note && (
                                    <p className="mt-2 text-xs italic text-amber-700">
                                        {issue.note}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section>
                <h3 className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">
                    Declared vocabulary
                </h3>

                {terms.length === 0 ? (
                    <p className="text-sm text-slate-500">Nothing declared yet.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
                        {terms.map((t) => (
                            <li
                                key={t.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="w-24 shrink-0 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                    {t.kind}
                                </span>
                                <span className="font-semibold text-slate-800">
                                    {t.canonical}
                                </span>
                                {t.variants?.length > 0 && (
                                    <span className="text-sm text-slate-500">
                                        not: {t.variants.join(', ')}
                                    </span>
                                )}
                                <button
                                    onClick={() => remove(t)}
                                    className="ml-auto text-xs font-semibold text-slate-400 hover:text-rose-600"
                                >
                                    Remove
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function AddTerm({ bookId, onAdded }) {
    const [kind, setKind] = useState('character');
    const [canonical, setCanonical] = useState('');
    const [variants, setVariants] = useState('');
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);

        try {
            const res = await window.axios.post(`/books/${bookId}/terms`, {
                kind,
                canonical,
                variants: variants
                    .split(',')
                    .map((v) => v.trim())
                    .filter(Boolean),
                note: note || null,
            });
            onAdded(res.data);
        } finally {
            setSaving(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="mb-6 grid grid-cols-3 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4"
        >
            <label className="text-xs font-semibold text-slate-600">
                Kind
                <select
                    value={kind}
                    onChange={(e) => setKind(e.target.value)}
                    className="w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="character">Character</option>
                    <option value="place">Place</option>
                    <option value="term">Term</option>
                    <option value="organization">Organization</option>
                    <option value="other">Other</option>
                </select>
            </label>

            <label className="text-xs font-semibold text-slate-600">
                Correct spelling
                <input
                    value={canonical}
                    onChange={(e) => setCanonical(e.target.value)}
                    required
                    className="w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </label>

            <label className="text-xs font-semibold text-slate-600">
                Flag these instead
                <input
                    value={variants}
                    onChange={(e) => setVariants(e.target.value)}
                    placeholder="Jon, Johnny"
                    className="w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </label>

            <label className="col-span-3 text-xs font-semibold text-slate-600">
                Note
                <input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    className="w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </label>

            <div className="col-span-3">
                <button
                    disabled={saving}
                    className="rounded-md bg-slate-800 px-4 py-1.5 text-sm font-bold text-white hover:bg-slate-900 disabled:opacity-60"
                >
                    {saving ? 'Saving…' : 'Add term'}
                </button>
            </div>
        </form>
    );
}
