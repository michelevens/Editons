import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * The book's research library.
 *
 * Verification standing is shown on every row and never inferred from the
 * presence of a source. A library that displayed unchecked sources the same
 * way as checked ones would undo the reason the distinction is stored.
 */
export default function ResearchLibrary({ bookId, onChanged }) {
    const { t } = useI18n();
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
            ? t('research.remove_confirm_cited', { count: source.citations_count })
            : t('research.remove_confirm');

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
                        {t('side.research')}
                    </h2>
                    <p className="mt-1 text-sm text-text-secondary">
                        {t('research.intro')}
                    </p>
                </div>
                <button
                    onClick={() => setAdding((v) => !v)}
                    className="rounded-md bg-brand-fill px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-primary-hover"
                >
                    {adding ? t('common.cancel') : t('research.add_source')}
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
                <p className="text-sm text-text-secondary">{t('common.loading')}</p>
            ) : sources.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border-input p-6 text-center text-sm text-text-secondary">
                    {t('research.empty')}
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
                                    {t('research.citations_count', { count: s.citations_count })}
                                </span>

                                <span className="ml-auto flex gap-3">
                                    {s.verification !== 'verified' && (
                                        <button
                                            onClick={() => verify(s, 'verified')}
                                            className="font-semibold text-status-success hover:text-status-success"
                                        >
                                            {t('research.mark_verified')}
                                        </button>
                                    )}
                                    {s.verification !== 'disputed' && (
                                        <button
                                            onClick={() => verify(s, 'disputed')}
                                            className="font-semibold text-status-warning hover:text-status-warning"
                                        >
                                            {t('research.dispute')}
                                        </button>
                                    )}
                                    <button
                                        onClick={() => remove(s)}
                                        className="font-semibold text-text-secondary hover:text-status-danger"
                                    >
                                        {t('research.remove')}
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
    const { t } = useI18n();
    const style = {
        verified: 'bg-status-success-bg text-status-success',
        disputed: 'bg-status-danger-bg text-status-danger',
        unverified: 'bg-bg-subtle text-text-secondary',
    }[value];

    return (
        <span
            className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${style}`}
        >
            {{ verified: t('research.standing_verified'), disputed: t('research.standing_disputed'), unverified: t('research.standing_unverified') }[value] ?? value}
        </span>
    );
}

function AddSource({ bookId, onAdded, onError }) {
    const { t } = useI18n();
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
                    t('research.save_failed'),
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
                {t('research.field_title')}
                <input {...field('title')} required />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                {t('research.field_kind')}
                <select {...field('kind')}>
                    <option value="book">{t('research.kind_book')}</option>
                    <option value="journal_article">{t('research.kind_journal_article')}</option>
                    <option value="website">{t('research.kind_website')}</option>
                    <option value="report">{t('research.kind_report')}</option>
                    <option value="interview">{t('research.kind_interview')}</option>
                    <option value="other">{t('research.kind_other')}</option>
                </select>
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                {t('research.field_authors')}
                <input {...field('authors')} placeholder="Smith, J.; Lee, K." />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                {t('research.field_publisher')}
                <input {...field('publisher')} />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                {t('research.field_published')}
                <input {...field('published')} placeholder="2019" />
            </label>

            {form.kind === 'book' ? (
                <label className="text-xs font-semibold text-text-secondary">
                    {t('research.field_edition')}
                    <input {...field('edition')} placeholder="2" />
                </label>
            ) : (
                <>
                    <label className="col-span-2 text-xs font-semibold text-text-secondary">
                        {form.kind === 'journal_article' ? t('research.field_journal') : t('research.field_container')}
                        <input {...field('container')} />
                    </label>
                    <div className="col-span-2 grid grid-cols-3 gap-3">
                        <label className="text-xs font-semibold text-text-secondary">
                            {t('research.field_volume')}
                            <input {...field('volume')} />
                        </label>
                        <label className="text-xs font-semibold text-text-secondary">
                            {t('research.field_issue')}
                            <input {...field('issue')} />
                        </label>
                        <label className="text-xs font-semibold text-text-secondary">
                            {t('research.field_pages')}
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
                {t('research.field_notes')}
                <textarea {...field('notes')} rows={2} />
            </label>

            <div className="col-span-2 flex items-center gap-3">
                <button
                    disabled={saving}
                    className="rounded-md bg-bg-inverse px-4 py-1.5 text-sm font-bold text-text-inverse hover:opacity-90 disabled:opacity-60"
                >
                    {saving ? t('editor.save_saving') : t('research.add_to_library')}
                </button>
                <p className="text-xs text-text-secondary">
                    {t('research.starts_unverified')}
                </p>
            </div>
        </form>
    );
}
