import { useEffect, useRef, useState } from 'react';
import { useI18n } from '@/i18n';

const KINDS = ['book', 'journal_article', 'website', 'report', 'interview', 'other'];
const STANDINGS = ['verified', 'unverified', 'disputed'];
const SORTS = [
    { value: 'title', tKey: 'research.sort_title' },
    { value: 'author', tKey: 'research.sort_author' },
    { value: 'year', tKey: 'research.sort_year' },
    { value: 'added', tKey: 'research.sort_added' },
    { value: 'standing', tKey: 'research.sort_standing' },
];

const EMPTY_FILTERS = { q: '', verification: '', kind: '', tag: '', collection: '', sort: 'title' };

/**
 * The book's research library.
 *
 * Verification standing is shown on every row and never inferred from the
 * presence of a source. A library that displayed unchecked sources the same
 * way as checked ones would undo the reason the distinction is stored.
 *
 * Search, filters and sort run on the server (ResearchController::index),
 * so the list a filter shows is the list the server would act on. Imports
 * (BibTeX, RIS, a DOI) are previewed first and always arrive unverified.
 */
export default function ResearchLibrary({ bookId, onChanged }) {
    const { t } = useI18n();
    const [sources, setSources] = useState([]);
    const [facets, setFacets] = useState(null);
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [loading, setLoading] = useState(true);
    const [mode, setMode] = useState(null); // null | 'add' | 'import'
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const requestRef = useRef(0);

    const load = async (next = filters) => {
        const ticket = ++requestRef.current;
        const params = Object.fromEntries(
            Object.entries(next).filter(([key, value]) => value !== '' && !(key === 'sort' && value === 'title')),
        );

        try {
            const res = await window.axios.get(`/books/${bookId}/sources`, { params });
            // A slower, older answer must not overwrite a newer one.
            if (ticket !== requestRef.current) return;
            setSources(res.data.sources);
            setFacets(res.data.facets);
        } finally {
            if (ticket === requestRef.current) setLoading(false);
        }
    };

    // Typing in the search box waits for a pause; the selects apply at once.
    useEffect(() => {
        const timer = setTimeout(() => load(filters), filters.q ? 250 : 0);

        return () => clearTimeout(timer);
    }, [bookId, filters]);

    const set = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
    const filtering = Object.entries(filters).some(([key, value]) => key !== 'sort' && value !== '');

    /** After any change: re-run the current view and tell the editor. */
    const changed = () => {
        load(filters);
        onChanged?.();
    };

    const verify = async (source, verification) => {
        await window.axios.patch(`/sources/${source.id}/verification`, { verification });
        changed();
    };

    const remove = async (source) => {
        const warning = source.citations_count
            ? t('research.remove_confirm_cited', { count: source.citations_count })
            : t('research.remove_confirm');

        if (!confirm(warning)) return;

        await window.axios.delete(`/sources/${source.id}`);
        changed();
    };

    const organise = async (source, payload) => {
        await window.axios.patch(`/sources/${source.id}`, payload);
        changed();
    };

    const count = (group, key) => facets?.[group]?.[key] ?? 0;

    return (
        <div className="mx-auto max-w-4xl p-8 max-sm:p-4">
            <header className="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-serif text-2xl font-bold text-text-primary">
                        {t('side.research')}
                    </h2>
                    <p className="mt-1 text-sm text-text-secondary">
                        {t('research.intro')}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {facets?.total > 0 && (
                        <a
                            href={`/books/${bookId}/sources/export.bib`}
                            data-a="research:export"
                            className="rounded-md border border-border-input px-3 py-1.5 text-sm font-semibold text-text-primary hover:bg-bg-subtle"
                        >
                            {t('research.export_bibtex')}
                        </a>
                    )}
                    <button
                        data-a="research:import"
                        onClick={() => setMode((m) => (m === 'import' ? null : 'import'))}
                        className="rounded-md border border-border-input px-3 py-1.5 text-sm font-semibold text-text-primary hover:bg-bg-subtle"
                    >
                        {mode === 'import' ? t('common.cancel') : t('research.import_file')}
                    </button>
                    <button
                        data-a="research:add"
                        onClick={() => setMode((m) => (m === 'add' ? null : 'add'))}
                        className="rounded-md bg-brand-fill px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-primary-hover"
                    >
                        {mode === 'add' ? t('common.cancel') : t('research.add_source')}
                    </button>
                </div>
            </header>

            {mode === 'add' && (
                <AddSource
                    bookId={bookId}
                    collections={facets?.collections ?? []}
                    onAdded={() => {
                        setMode(null);
                        setError(null);
                        changed();
                    }}
                    onError={setError}
                />
            )}

            {mode === 'import' && (
                <ImportFile
                    bookId={bookId}
                    collections={facets?.collections ?? []}
                    onImported={(result) => {
                        setMode(null);
                        setNotice(t('research.import_done', { imported: result.imported, duplicates: result.duplicates }));
                        changed();
                    }}
                />
            )}

            {error && (
                <p role="alert" className="mb-4 rounded-md bg-status-danger-bg px-3 py-2 text-sm text-status-danger">
                    {error}
                </p>
            )}

            {notice && (
                <p role="status" data-a="research:notice" className="mb-4 flex items-start justify-between gap-3 rounded-md bg-status-success-bg px-3 py-2 text-sm text-status-success">
                    <span>{notice}</span>
                    <button onClick={() => setNotice(null)} aria-label={t('research.dismiss')} className="font-bold">×</button>
                </p>
            )}

            {facets?.total > 0 && (
                <div data-a="research:filters" className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                    <input
                        type="search"
                        value={filters.q}
                        data-a="research:search"
                        onChange={(e) => set('q', e.target.value)}
                        placeholder={t('research.search_placeholder')}
                        aria-label={t('research.search_placeholder')}
                        className="min-w-0 flex-1 basis-56 rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                    />
                    <FilterSelect label={t('research.filter_standing')} value={filters.verification} onChange={(v) => set('verification', v)} a="standing">
                        <option value="">{t('research.filter_all_standings')}</option>
                        {STANDINGS.map((s) => (
                            <option key={s} value={s}>
                                {t(`research.standing_${s}`)} ({count('verification', s)})
                            </option>
                        ))}
                    </FilterSelect>
                    <FilterSelect label={t('research.field_kind')} value={filters.kind} onChange={(v) => set('kind', v)} a="kind">
                        <option value="">{t('research.filter_all_kinds')}</option>
                        {KINDS.filter((k) => count('kind', k) > 0 || filters.kind === k).map((k) => (
                            <option key={k} value={k}>
                                {t(`research.kind_${k}`)} ({count('kind', k)})
                            </option>
                        ))}
                    </FilterSelect>
                    {(facets.tags.length > 0 || filters.tag) && (
                        <FilterSelect label={t('research.field_tags')} value={filters.tag} onChange={(v) => set('tag', v)} a="tag">
                            <option value="">{t('research.filter_all_tags')}</option>
                            {facets.tags.map((tag) => (
                                <option key={tag.name} value={tag.name}>
                                    {tag.name} ({tag.count})
                                </option>
                            ))}
                        </FilterSelect>
                    )}
                    {(facets.collections.length > 0 || filters.collection) && (
                        <FilterSelect label={t('research.field_collection')} value={filters.collection} onChange={(v) => set('collection', v)} a="collection">
                            <option value="">{t('research.filter_all_collections')}</option>
                            {facets.collections.map((c) => (
                                <option key={c.name} value={c.name}>
                                    {c.name} ({c.count})
                                </option>
                            ))}
                        </FilterSelect>
                    )}
                    <FilterSelect label={t('research.sort_label')} value={filters.sort} onChange={(v) => set('sort', v)} a="sort">
                        {SORTS.map((s) => (
                            <option key={s.value} value={s.value}>
                                {t(s.tKey)}
                            </option>
                        ))}
                    </FilterSelect>
                    {filtering && (
                        <button
                            data-a="research:clear"
                            onClick={() => setFilters({ ...EMPTY_FILTERS, sort: filters.sort })}
                            className="text-xs font-semibold text-brand-primary hover:underline"
                        >
                            {t('research.clear_filters')}
                        </button>
                    )}
                </div>
            )}

            {loading ? (
                <p className="text-sm text-text-secondary">{t('common.loading')}</p>
            ) : sources.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border-input p-6 text-center text-sm text-text-secondary">
                    {filtering ? t('research.none_match') : t('research.empty')}
                </p>
            ) : (
                <>
                    {filtering && (
                        <p data-a="research:showing" className="mb-2 text-xs text-text-secondary">
                            {t('research.showing', { shown: sources.length, total: facets?.total ?? sources.length })}
                        </p>
                    )}
                    <ul className="space-y-2">
                        {sources.map((s) => (
                            <SourceRow
                                key={s.id}
                                source={s}
                                collections={facets?.collections ?? []}
                                onVerify={verify}
                                onRemove={remove}
                                onOrganise={organise}
                                onTag={(tag) => set('tag', tag)}
                            />
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}

function FilterSelect({ label, value, onChange, a, children }) {
    return (
        <select
            value={value}
            aria-label={label}
            data-a={`research:filter:${a}`}
            onChange={(e) => onChange(e.target.value)}
            className="max-w-full rounded-md border-border-input py-1.5 pl-2.5 pr-8 text-sm focus:border-brand-primary focus:ring-brand-primary"
        >
            {children}
        </select>
    );
}

function SourceRow({ source: s, collections, onVerify, onRemove, onOrganise, onTag }) {
    const { t } = useI18n();
    const [editing, setEditing] = useState(false);
    const [tags, setTags] = useState('');
    const [collection, setCollection] = useState('');

    const startEditing = () => {
        setTags((s.tags ?? []).join(', '));
        setCollection(s.collection ?? '');
        setEditing(true);
    };

    const save = async (e) => {
        e.preventDefault();
        await onOrganise(s, {
            tags: tags.split(',').map((tag) => tag.trim()).filter(Boolean),
            collection: collection.trim() || null,
        });
        setEditing(false);
    };

    return (
        <li data-a={`research:source:${s.id}`} className="rounded-lg border border-border-default bg-bg-surface p-4">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="break-words font-semibold text-text-primary">{s.title}</p>
                    <p className="break-words text-sm text-text-secondary">
                        {s.reference}
                        {/* reference() already falls back to the publisher
                            when there is no author, so only append it when
                            it would add something. */}
                        {s.publisher && s.authors ? ` · ${s.publisher}` : ''}
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
                        <p className="mt-1.5 whitespace-pre-line break-words text-sm leading-relaxed text-text-secondary">{s.notes}</p>
                    )}
                    {(s.tags?.length > 0 || s.collection || s.origin) && (
                        <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                            {s.collection && (
                                <span className="rounded bg-bg-subtle px-1.5 py-0.5 font-semibold text-text-primary">
                                    {s.collection}
                                </span>
                            )}
                            {(s.tags ?? []).map((tag) => (
                                <button
                                    key={tag}
                                    onClick={() => onTag(tag)}
                                    title={t('research.filter_by_tag')}
                                    className="rounded-full border border-border-default px-2 py-0.5 text-text-secondary hover:border-brand-primary hover:text-brand-primary"
                                >
                                    #{tag}
                                </button>
                            ))}
                            {s.origin && (
                                <span className="text-text-secondary">
                                    {t(`research.origin_${s.origin}`)}
                                </span>
                            )}
                        </div>
                    )}
                </div>

                <Standing value={s.verification} />
            </div>

            {editing && (
                <form onSubmit={save} data-a={`research:organise-form:${s.id}`} className="mt-3 grid gap-2 sm:grid-cols-2">
                    <label className="text-xs font-semibold text-text-secondary">
                        {t('research.field_tags')}
                        <input
                            value={tags}
                            autoFocus
                            data-a="research:tags-input"
                            onChange={(e) => setTags(e.target.value)}
                            placeholder={t('research.tags_placeholder')}
                            className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                        />
                    </label>
                    <label className="text-xs font-semibold text-text-secondary">
                        {t('research.field_collection')}
                        <input
                            value={collection}
                            list={`research-collections-${s.id}`}
                            maxLength={80}
                            data-a="research:collection-input"
                            onChange={(e) => setCollection(e.target.value)}
                            className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                        />
                        <datalist id={`research-collections-${s.id}`}>
                            {collections.map((c) => (
                                <option key={c.name} value={c.name} />
                            ))}
                        </datalist>
                    </label>
                    <div className="flex gap-3 sm:col-span-2">
                        <button data-a="research:organise-save" className="rounded-md bg-bg-inverse px-3 py-1 text-xs font-bold text-text-inverse">
                            {t('research.save')}
                        </button>
                        <button type="button" onClick={() => setEditing(false)} className="text-xs text-text-secondary">
                            {t('common.cancel')}
                        </button>
                    </div>
                </form>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border-default pt-2.5 text-xs">
                <span className="text-text-secondary">
                    {t('research.citations_count', { count: s.citations_count })}
                </span>

                <span className="ml-auto flex flex-wrap gap-3">
                    {!editing && (
                        <button
                            data-a={`research:organise:${s.id}`}
                            onClick={startEditing}
                            className="font-semibold text-text-secondary hover:text-text-primary"
                        >
                            {t('research.organise')}
                        </button>
                    )}
                    {s.verification !== 'verified' && (
                        <button
                            onClick={() => onVerify(s, 'verified')}
                            className="font-semibold text-status-success hover:text-status-success"
                        >
                            {t('research.mark_verified')}
                        </button>
                    )}
                    {s.verification !== 'disputed' && (
                        <button
                            onClick={() => onVerify(s, 'disputed')}
                            className="font-semibold text-status-warning hover:text-status-warning"
                        >
                            {t('research.dispute')}
                        </button>
                    )}
                    <button
                        onClick={() => onRemove(s)}
                        className="font-semibold text-text-secondary hover:text-status-danger"
                    >
                        {t('research.remove')}
                    </button>
                </span>
            </div>
        </li>
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

/**
 * Import a .bib or .ris file: preview first, untick what you do not want,
 * then import. The server re-reads the same file for the import rather
 * than trusting rows sent back, and skips anything already in the library.
 */
function ImportFile({ bookId, collections, onImported }) {
    const { t } = useI18n();
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [picked, setPicked] = useState(new Set());
    const [collection, setCollection] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const message = (err) =>
        err.response?.data?.errors?.file?.[0] ?? err.response?.data?.message ?? t('research.import_failed');

    const choose = async (chosen) => {
        setFile(chosen);
        setPreview(null);
        setError(null);

        if (!chosen) return;

        setBusy(true);
        try {
            const body = new FormData();
            body.append('file', chosen);
            const res = await window.axios.post(`/books/${bookId}/sources/import/preview`, body);
            setPreview(res.data);
            setPicked(new Set(res.data.entries.filter((e) => !e.duplicate_of).map((e) => e.index)));
        } catch (err) {
            setError(message(err));
        } finally {
            setBusy(false);
        }
    };

    const toggle = (index) =>
        setPicked((current) => {
            const next = new Set(current);
            next.has(index) ? next.delete(index) : next.add(index);
            return next;
        });

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const body = new FormData();
            body.append('file', file);
            [...picked].forEach((index) => body.append('only[]', index));
            if (collection.trim()) body.append('collection', collection.trim());
            const res = await window.axios.post(`/books/${bookId}/sources/import`, body);
            onImported(res.data);
        } catch (err) {
            setError(message(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section data-a="research:import-panel" className="mb-6 rounded-lg border border-border-default bg-bg-subtle p-4">
            <label className="block text-xs font-semibold text-text-secondary">
                {t('research.import_choose')}
                <input
                    type="file"
                    accept=".bib,.bibtex,.ris,.txt"
                    data-a="research:import-file"
                    onChange={(e) => choose(e.target.files?.[0] ?? null)}
                    className="mt-1 block w-full max-w-full text-sm text-text-primary"
                />
            </label>
            <p className="mt-1 text-xs text-text-secondary">{t('research.import_help')}</p>

            {busy && !preview && <p className="mt-3 text-sm text-text-secondary">{t('common.loading')}</p>}

            {error && (
                <p role="alert" className="mt-3 rounded-md bg-status-danger-bg px-3 py-2 text-sm text-status-danger">
                    {error}
                </p>
            )}

            {preview && (
                <div className="mt-4">
                    <p data-a="research:import-summary" className="text-sm text-text-primary">
                        {t('research.import_found', {
                            count: preview.entries.length,
                            format: preview.format === 'ris' ? 'RIS' : 'BibTeX',
                        })}
                        {preview.skipped > 0 && ` ${t('research.import_skipped', { count: preview.skipped })}`}
                        {preview.truncated && ` ${t('research.import_truncated')}`}
                    </p>

                    <ul className="mt-2 max-h-80 divide-y divide-border-divider overflow-y-auto rounded-md border border-border-default bg-bg-surface">
                        {preview.entries.map((entry) => (
                            <li key={entry.index} className="flex items-start gap-2.5 px-3 py-2">
                                <input
                                    type="checkbox"
                                    checked={picked.has(entry.index)}
                                    disabled={!!entry.duplicate_of}
                                    onChange={() => toggle(entry.index)}
                                    data-a={`research:import-pick:${entry.index}`}
                                    aria-label={entry.title}
                                    className="mt-1 rounded border-border-input text-brand-primary focus:ring-brand-primary"
                                />
                                <div className="min-w-0 text-sm">
                                    <p className="break-words font-semibold text-text-primary">{entry.title}</p>
                                    <p className="break-words text-xs text-text-secondary">
                                        {[entry.authors, entry.published, entry.container].filter(Boolean).join(' · ')}
                                    </p>
                                    {entry.duplicate_of && (
                                        <p className="text-xs font-semibold text-status-warning">
                                            {t('research.import_duplicate', { title: entry.duplicate_of })}
                                        </p>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-3 flex flex-wrap items-end gap-3">
                        <label className="min-w-0 flex-1 basis-48 text-xs font-semibold text-text-secondary">
                            {t('research.import_into_collection')}
                            <input
                                value={collection}
                                maxLength={80}
                                list="research-import-collections"
                                onChange={(e) => setCollection(e.target.value)}
                                className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                            />
                            <datalist id="research-import-collections">
                                {collections.map((c) => (
                                    <option key={c.name} value={c.name} />
                                ))}
                            </datalist>
                        </label>
                        <button
                            data-a="research:import-submit"
                            disabled={busy || picked.size === 0}
                            onClick={submit}
                            className="rounded-md bg-bg-inverse px-4 py-1.5 text-sm font-bold text-text-inverse hover:opacity-90 disabled:opacity-60"
                        >
                            {t('research.import_submit', { count: picked.size })}
                        </button>
                    </div>
                    <p className="mt-2 text-xs text-text-secondary">{t('research.import_unverified')}</p>
                </div>
            )}
        </section>
    );
}

const BLANK = {
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
    collection: '',
};

function AddSource({ bookId, collections, onAdded, onError }) {
    const { t } = useI18n();
    const [form, setForm] = useState(BLANK);
    const [origin, setOrigin] = useState(null);
    const [saving, setSaving] = useState(false);
    const [doi, setDoi] = useState('');
    const [looking, setLooking] = useState(false);
    const [lookup, setLookup] = useState(null); // { message, tone }

    const field = (key) => ({
        value: form[key] ?? '',
        onChange: (e) => setForm({ ...form, [key]: e.target.value }),
        className:
            'w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary',
    });

    /** Fill the form from Crossref. Nothing is saved until the author adds it. */
    const find = async () => {
        if (!doi.trim()) return;

        setLooking(true);
        setLookup(null);
        try {
            const res = await window.axios.post(`/books/${bookId}/sources/doi`, { doi: doi.trim() });
            const filled = Object.fromEntries(
                Object.keys(BLANK).map((key) => [key, res.data.entry[key] ?? '']),
            );
            setForm({ ...filled, collection: form.collection });
            setOrigin('crossref');
            setLookup(
                res.data.duplicate_of
                    ? { tone: 'warning', message: t('research.import_duplicate', { title: res.data.duplicate_of }) }
                    : { tone: 'ok', message: t('research.doi_found') },
            );
        } catch (err) {
            setLookup({
                tone: 'danger',
                message: err.response?.data?.errors?.doi?.[0] ?? err.response?.data?.message ?? t('research.doi_unreachable'),
            });
        } finally {
            setLooking(false);
        }
    };

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);

        try {
            // Strip empties: the API validates url as a URL, and an empty
            // string is not one.
            const payload = Object.fromEntries(
                Object.entries(form).filter(([, v]) => v !== ''),
            );

            if (origin) payload.origin = origin;

            await window.axios.post(`/books/${bookId}/sources`, payload);
            onAdded();
        } catch (err) {
            onError(
                err.response?.data?.message ??
                    t('research.save_failed'),
            );
        } finally {
            setSaving(false);
        }
    };

    const tone = {
        ok: 'text-status-success',
        warning: 'text-status-warning',
        danger: 'text-status-danger',
    };

    return (
        <form
            onSubmit={submit}
            className="mb-6 grid grid-cols-2 gap-3 rounded-lg border border-border-default bg-bg-subtle p-4"
        >
            <div className="col-span-2 flex flex-wrap items-end gap-2 border-b border-border-default pb-3">
                <label className="min-w-0 flex-1 basis-48 text-xs font-semibold text-text-secondary">
                    {t('research.doi_lookup')}
                    <input
                        value={doi}
                        data-a="research:doi"
                        onChange={(e) => setDoi(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                find();
                            }
                        }}
                        placeholder="10.1000/xyz"
                        className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                    />
                </label>
                <button
                    type="button"
                    data-a="research:doi-find"
                    disabled={looking || !doi.trim()}
                    onClick={find}
                    className="rounded-md border border-border-input bg-bg-surface px-3 py-1.5 text-sm font-semibold text-text-primary hover:bg-bg-subtle disabled:opacity-60"
                >
                    {looking ? t('common.loading') : t('research.doi_find')}
                </button>
                {lookup && (
                    <p role="status" data-a="research:doi-result" className={`basis-full text-xs font-semibold ${tone[lookup.tone]}`}>
                        {lookup.message}
                    </p>
                )}
            </div>

            <label className="col-span-2 text-xs font-semibold text-text-secondary">
                {t('research.field_title')}
                <input {...field('title')} required />
            </label>

            <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
                {t('research.field_kind')}
                <select {...field('kind')}>
                    {KINDS.map((k) => (
                        <option key={k} value={k}>{t(`research.kind_${k}`)}</option>
                    ))}
                </select>
            </label>

            <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
                {t('research.field_authors')}
                <input {...field('authors')} placeholder="Smith, J.; Lee, K." />
            </label>

            <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
                {t('research.field_publisher')}
                <input {...field('publisher')} />
            </label>

            <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
                {t('research.field_published')}
                <input {...field('published')} placeholder="2019" />
            </label>

            {form.kind === 'book' ? (
                <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
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

            <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
                DOI
                <input {...field('doi')} placeholder="10.1000/xyz" />
            </label>

            <label className="text-xs font-semibold text-text-secondary max-sm:col-span-2">
                {t('research.field_collection')}
                <input {...field('collection')} maxLength={80} list="research-add-collections" />
                <datalist id="research-add-collections">
                    {collections.map((c) => (
                        <option key={c.name} value={c.name} />
                    ))}
                </datalist>
            </label>

            <label className="col-span-2 text-xs font-semibold text-text-secondary">
                URL
                <input {...field('url')} type="url" placeholder="https://…" />
            </label>

            <label className="col-span-2 text-xs font-semibold text-text-secondary">
                {t('research.field_notes')}
                <textarea {...field('notes')} rows={2} />
            </label>

            <div className="col-span-2 flex flex-wrap items-center gap-3">
                <button
                    disabled={saving}
                    data-a="research:add-submit"
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
