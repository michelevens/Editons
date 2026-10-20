import { useEffect, useState } from 'react';

/**
 * The book's research library.
 *
 * Verification standing is shown on every row and never inferred from the
 * presence of a source. A library that displayed unchecked sources the same
 * way as checked ones would undo the reason the distinction is stored.
 */
export default function ResearchLibrary({ bookId, onChanged }) {
    const [sources, setSources] = useState([]);
    const [loading, setLoading] = useState(true);
    const [adding, setAdding] = useState(false);
    const [error, setError] = useState(null);

    const load = async () => {
        setLoading(true);
        try {
            const res = await window.axios.get(`/books/${bookId}/sources`);
            setSources(res.data.sources);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, [bookId]);

    const verify = async (source, verification) => {
        const res = await window.axios.patch(`/sources/${source.id}/verification`, {
            verification,
        });
        setSources(res.data.sources);
        onChanged?.();
    };

    const remove = async (source) => {
        const warning = source.citations_count
            ? `${source.citations_count} claim(s) cite this source. Removing it marks them unsupported again. Continue?`
            : 'Remove this source?';

        if (!confirm(warning)) return;

        const res = await window.axios.delete(`/sources/${source.id}`);
        setSources(res.data.sources);
        onChanged?.();
    };

    return (
        <div className="mx-auto max-w-4xl p-8">
            <header className="mb-6 flex items-baseline justify-between">
                <div>
                    <h2 className="font-serif text-2xl font-bold text-slate-900">
                        Research Library
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Sources for this book. A source must be verified before
                        it can back a claim.
                    </p>
                </div>
                <button
                    onClick={() => setAdding((v) => !v)}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700"
                >
                    {adding ? 'Cancel' : 'Add source'}
                </button>
            </header>

            {adding && (
                <AddSource
                    bookId={bookId}
                    onAdded={(rows) => {
                        setSources(rows);
                        setAdding(false);
                        setError(null);
                        onChanged?.();
                    }}
                    onError={setError}
                />
            )}

            {error && (
                <p className="mb-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {error}
                </p>
            )}

            {loading ? (
                <p className="text-sm text-slate-500">Loading…</p>
            ) : sources.length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    No sources yet. Add the first one to start building this
                    book's bibliography.
                </p>
            ) : (
                <ul className="space-y-2">
                    {sources.map((s) => (
                        <li
                            key={s.id}
                            className="rounded-lg border border-slate-200 bg-white p-4"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <p className="font-semibold text-slate-900">
                                        {s.title}
                                    </p>
                                    <p className="text-sm text-slate-600">
                                        {s.reference}
                                        {/* reference() already falls back to
                                            the publisher when there is no
                                            author, so only append it when it
                                            would add something. */}
                                        {s.publisher && s.authors
                                            ? ` · ${s.publisher}`
                                            : ''}
                                    </p>
                                    {s.url && (
                                        <a
                                            href={s.url}
                                            target="_blank"
                                            rel="noreferrer noopener"
                                            className="mt-0.5 block truncate text-xs text-indigo-600 hover:underline"
                                        >
                                            {s.url}
                                        </a>
                                    )}
                                    {s.notes && (
                                        <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                                            {s.notes}
                                        </p>
                                    )}
                                </div>

                                <Standing value={s.verification} />
                            </div>

                            <div className="mt-3 flex items-center gap-3 border-t border-slate-100 pt-2.5 text-xs">
                                <span className="text-slate-400">
                                    {s.citations_count} citation
                                    {s.citations_count === 1 ? '' : 's'}
                                </span>

                                <span className="ml-auto flex gap-3">
                                    {s.verification !== 'verified' && (
                                        <button
                                            onClick={() => verify(s, 'verified')}
                                            className="font-semibold text-emerald-700 hover:text-emerald-900"
                                        >
                                            Mark verified
                                        </button>
                                    )}
                                    {s.verification !== 'disputed' && (
                                        <button
                                            onClick={() => verify(s, 'disputed')}
                                            className="font-semibold text-amber-700 hover:text-amber-900"
                                        >
                                            Dispute
                                        </button>
                                    )}
                                    <button
                                        onClick={() => remove(s)}
                                        className="font-semibold text-slate-400 hover:text-rose-600"
                                    >
                                        Remove
                                    </button>
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Standing({ value }) {
    const style = {
        verified: 'bg-emerald-50 text-emerald-700',
        disputed: 'bg-rose-50 text-rose-700',
        unverified: 'bg-slate-100 text-slate-600',
    }[value];

    return (
        <span
            className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${style}`}
        >
            {value}
        </span>
    );
}

function AddSource({ bookId, onAdded, onError }) {
    const [form, setForm] = useState({
        kind: 'journal_article',
        title: '',
        authors: '',
        publisher: '',
        published: '',
        container: '',
        volume: '',
        issue: '',
        pages: '',
        edition: '',
        doi: '',
        url: '',
        notes: '',
    });
    const [saving, setSaving] = useState(false);

    const field = (key) => ({
        value: form[key],
        onChange: (e) => setForm({ ...form, [key]: e.target.value }),
        className:
            'w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500',
    });

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);

        try {
            // Strip empties: the API validates url as a URL, and an empty
            // string is not one.
            const payload = Object.fromEntries(
                Object.entries(form).filter(([, v]) => v !== ''),
            );

            const res = await window.axios.post(`/books/${bookId}/sources`, payload);
            onAdded(res.data.sources);
        } catch (err) {
            onError(
                err.response?.data?.message ??
                    'That source could not be saved. Check the fields and try again.',
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="mb-6 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4"
        >
            <label className="col-span-2 text-xs font-semibold text-slate-600">
                Title
                <input {...field('title')} required />
            </label>

            <label className="text-xs font-semibold text-slate-600">
                Kind
                <select {...field('kind')}>
                    <option value="book">Book</option>
                    <option value="journal_article">Journal article</option>
                    <option value="website">Website</option>
                    <option value="report">Report</option>
                    <option value="interview">Interview</option>
                    <option value="other">Other</option>
                </select>
            </label>

            <label className="text-xs font-semibold text-slate-600">
                Authors
                <input {...field('authors')} placeholder="Smith, J.; Lee, K." />
            </label>

            <label className="text-xs font-semibold text-slate-600">
                Publisher
                <input {...field('publisher')} />
            </label>

            <label className="text-xs font-semibold text-slate-600">
                Published
                <input {...field('published')} placeholder="2019" />
            </label>

            {form.kind === 'book' ? (
                <label className="text-xs font-semibold text-slate-600">
                    Edition
                    <input {...field('edition')} placeholder="2" />
                </label>
            ) : (
                <>
                    <label className="col-span-2 text-xs font-semibold text-slate-600">
                        {form.kind === 'journal_article' ? 'Journal' : 'Website or series'}
                        <input {...field('container')} />
                    </label>
                    <div className="col-span-2 grid grid-cols-3 gap-3">
                        <label className="text-xs font-semibold text-slate-600">
                            Volume
                            <input {...field('volume')} />
                        </label>
                        <label className="text-xs font-semibold text-slate-600">
                            Issue
                            <input {...field('issue')} />
                        </label>
                        <label className="text-xs font-semibold text-slate-600">
                            Pages
                            <input {...field('pages')} placeholder="12–34" />
                        </label>
                    </div>
                </>
            )}

            <label className="text-xs font-semibold text-slate-600">
                DOI
                <input {...field('doi')} placeholder="10.1000/xyz" />
            </label>

            <label className="col-span-2 text-xs font-semibold text-slate-600">
                URL
                <input {...field('url')} type="url" placeholder="https://…" />
            </label>

            <label className="col-span-2 text-xs font-semibold text-slate-600">
                Notes
                <textarea {...field('notes')} rows={2} />
            </label>

            <div className="col-span-2 flex items-center gap-3">
                <button
                    disabled={saving}
                    className="rounded-md bg-slate-800 px-4 py-1.5 text-sm font-bold text-white hover:bg-slate-900 disabled:opacity-60"
                >
                    {saving ? 'Saving…' : 'Add to library'}
                </button>
                <p className="text-xs text-slate-500">
                    New sources start unverified.
                </p>
            </div>
        </form>
    );
}
