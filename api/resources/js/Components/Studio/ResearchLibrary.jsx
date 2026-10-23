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
                    <h2 className="font-serif text-2xl font-bold text-text-primary">
                        Research Library
                    </h2>
                    <p className="mt-1 text-sm text-text-secondary">
                        Sources for this book. A source must be verified before
                        it can back a claim.
                    </p>
                </div>
                <button
                    onClick={() => setAdding((v) => !v)}
                    className="rounded-md bg-brand-fill px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-primary-hover"
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
                <p className="mb-4 rounded-md bg-status-danger-bg px-3 py-2 text-sm text-status-danger">
                    {error}
                </p>
            )}

            {loading ? (
                <p className="text-sm text-text-secondary">Loading…</p>
            ) : sources.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border-input p-6 text-center text-sm text-text-secondary">
                    No sources yet. Add the first one to start building this
                    book's bibliography.
                </p>
            ) : (
                <ul className="space-y-2">
                    {sources.map((s) => (
                        <li
                            key={s.id}
                            className="rounded-lg border border-border-default bg-bg-surface p-4"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <p className="font-semibold text-text-primary">
                                        {s.title}
                                    </p>
                                    <p className="text-sm text-text-secondary">
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
                                            className="mt-0.5 block truncate text-xs text-brand-primary hover:underline"
                                        >
                                            {s.url}
                                        </a>
                                    )}
                                    {s.notes && (
                                        <p className="mt-1.5 text-sm leading-relaxed text-text-secondary">
                                            {s.notes}
                                        </p>
                                    )}
                                </div>

                                <Standing value={s.verification} />
                            </div>

                            <div className="mt-3 flex items-center gap-3 border-t border-border-default pt-2.5 text-xs">
                                <span className="text-text-secondary">
                                    {s.citations_count} citation
                                    {s.citations_count === 1 ? '' : 's'}
                                </span>

                                <span className="ml-auto flex gap-3">
                                    {s.verification !== 'verified' && (
                                        <button
                                            onClick={() => verify(s, 'verified')}
                                            className="font-semibold text-status-success hover:text-status-success"
                                        >
                                            Mark verified
                                        </button>
                                    )}
                                    {s.verification !== 'disputed' && (
                                        <button
                                            onClick={() => verify(s, 'disputed')}
                                            className="font-semibold text-status-warning hover:text-status-warning"
                                        >
                                            Dispute
                                        </button>
                                    )}
                                    <button
                                        onClick={() => remove(s)}
                                        className="font-semibold text-text-secondary hover:text-status-danger"
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
        verified: 'bg-status-success-bg text-status-success',
        disputed: 'bg-status-danger-bg text-status-danger',
        unverified: 'bg-bg-subtle text-text-secondary',
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
            'w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary',
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
            className="mb-6 grid grid-cols-2 gap-3 rounded-lg border border-border-default bg-bg-subtle p-4"
        >
            <label className="col-span-2 text-xs font-semibold text-text-secondary">
                Title
                <input {...field('title')} required />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
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

            <label className="text-xs font-semibold text-text-secondary">
                Authors
                <input {...field('authors')} placeholder="Smith, J.; Lee, K." />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                Publisher
                <input {...field('publisher')} />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                Published
                <input {...field('published')} placeholder="2019" />
            </label>

            {form.kind === 'book' ? (
                <label className="text-xs font-semibold text-text-secondary">
                    Edition
                    <input {...field('edition')} placeholder="2" />
                </label>
            ) : (
                <>
                    <label className="col-span-2 text-xs font-semibold text-text-secondary">
                        {form.kind === 'journal_article' ? 'Journal' : 'Website or series'}
                        <input {...field('container')} />
                    </label>
                    <div className="col-span-2 grid grid-cols-3 gap-3">
                        <label className="text-xs font-semibold text-text-secondary">
                            Volume
                            <input {...field('volume')} />
                        </label>
                        <label className="text-xs font-semibold text-text-secondary">
                            Issue
                            <input {...field('issue')} />
                        </label>
                        <label className="text-xs font-semibold text-text-secondary">
                            Pages
                            <input {...field('pages')} placeholder="12–34" />
                        </label>
                    </div>
                </>
            )}

            <label className="text-xs font-semibold text-text-secondary">
                DOI
                <input {...field('doi')} placeholder="10.1000/xyz" />
            </label>

            <label className="col-span-2 text-xs font-semibold text-text-secondary">
                URL
                <input {...field('url')} type="url" placeholder="https://…" />
            </label>

            <label className="col-span-2 text-xs font-semibold text-text-secondary">
                Notes
                <textarea {...field('notes')} rows={2} />
            </label>

            <div className="col-span-2 flex items-center gap-3">
                <button
                    disabled={saving}
                    className="rounded-md bg-bg-inverse px-4 py-1.5 text-sm font-bold text-text-inverse hover:opacity-90 disabled:opacity-60"
                >
                    {saving ? 'Saving…' : 'Add to library'}
                </button>
                <p className="text-xs text-text-secondary">
                    New sources start unverified.
                </p>
            </div>
        </form>
    );
}
