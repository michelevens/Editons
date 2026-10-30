import { Pager } from '@/Components/Admin/Kit';
import { Link, router } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { formatDate, formatNumber, t, useI18n } from '@/i18n';

/**
 * A shelf of books (ADR-0045 — Shelf views and bulk actions): the author's
 * own, and the console's House catalog. Search, sort, group by series, cards
 * or a table with chosen columns, all done on the server (App\Shelf\Shelf);
 * the state lives in the address bar and is remembered per person, so the
 * shelf comes back the way it was left. Selection feeds the bulk bar, which
 * runs one action across the books and reports each one.
 *
 * `renderCard(book, { selecting, selected, toggle })` draws a card: the page
 * decides what a book looks like; this decides which books, in what order.
 */

export const COLUMN_LABELS = {
    words: 'Words',
    status: 'Status',
    series: 'Series',
    language: 'Language',
    isbns: 'ISBNs',
    channels: 'Live channels',
    exports: 'Last export',
    house_style: 'House style',
    updated: 'Updated',
};

const SORT_LABELS = { updated: 'Last updated', title: 'Title', series: 'Series and number', words: 'Words', status: 'Status' };

// The same labels in the reader's language (COLUMN_LABELS stays the English,
// for the CSV export's headers).
const COLUMN_KEYS = {
    words: { tKey: 'shelf.col_words' },
    status: { tKey: 'shelf.col_status' },
    series: { tKey: 'shelf.col_series' },
    language: { tKey: 'shelf.col_language' },
    isbns: { tKey: 'shelf.col_isbns' },
    channels: { tKey: 'shelf.col_channels' },
    exports: { tKey: 'shelf.col_exports' },
    house_style: { tKey: 'shelf.col_house_style' },
    updated: { tKey: 'shelf.col_updated' },
};
const SORT_KEYS = {
    updated: { tKey: 'shelf.sort_updated' },
    title: { tKey: 'shelf.sort_title' },
    series: { tKey: 'shelf.sort_series' },
    words: { tKey: 'shelf.sort_words' },
    status: { tKey: 'shelf.sort_status' },
};
const columnLabel = (c) => (COLUMN_KEYS[c] ? t(COLUMN_KEYS[c].tKey) : c);
const sortLabel = (s) => (SORT_KEYS[s] ? t(SORT_KEYS[s].tKey) : s);

const FORMAT_LABELS = { pdf: 'PDF', epub: 'EPUB', pdf_interior: 'Print interior', cover_wrap: 'Cover wrap' };
const FORMAT_KEYS = { pdf_interior: { tKey: 'shelfbulk.format_pdf_interior' }, cover_wrap: { tKey: 'shelfbulk.format_cover_wrap' } };
const formatLabel = (f) => (FORMAT_KEYS[f] ? t(FORMAT_KEYS[f].tKey) : FORMAT_LABELS[f] ?? f);

const control =
    'h-[34px] rounded-control border border-border-input bg-bg-surface px-2.5 text-[12.5px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20';
const chip = 'rounded-control border border-border-input bg-bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-text-body transition hover:border-brand-primary/40 disabled:opacity-50';
const primary = 'rounded-control bg-brand-fill px-3 py-1.5 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50';

/** The state as query parameters: columns as one comma list. */
function toQuery(state) {
    return { ...state, columns: state.columns.join(',') };
}

export default function Shelf({
    shelf,
    books,
    routeName,
    filters = null,
    renderCard,
    cardsClassName = 'grid grid-cols-1 gap-[14px] sm:grid-cols-2',
    linkFor,
    houseStyles = null,
    coverTemplates = null,
    canSelect = true,
    selected: controlledSelected,
    setSelected: controlledSetSelected,
    extraBulk = null,
    empty = null,
    prefix = 'shelf',
}) {
    const { t } = useI18n();
    // What was asked for shows at once; the server's answer replaces it.
    const [pending, setPending] = useState({});
    useEffect(() => setPending({}), [shelf.state]);
    const state = { ...shelf.state, ...pending };
    const [ownSelected, ownSetSelected] = useState([]);
    const selected = controlledSelected ?? ownSelected;
    const setSelected = controlledSetSelected ?? ownSetSelected;
    const [selecting, setSelecting] = useState(false);
    const [collapsed, setCollapsed] = useState([]);
    const [q, setQ] = useState(state.q);
    const typing = useRef(null);

    useEffect(() => setQ(shelf.state.q), [shelf.state.q]);

    const go = (patch) => {
        const next = { ...state, ...patch };
        setPending((current) => ({ ...current, ...patch }));
        router.get(route(routeName), toQuery(next), { preserveState: true, preserveScroll: true, replace: true });
        // Remembered for next time; a failure only means it is not.
        window.axios.put(route('shelf.current', shelf.view), { state: next }).catch(() => {});
    };

    const onSearch = (value) => {
        setQ(value);
        clearTimeout(typing.current);
        typing.current = setTimeout(() => go({ q: value }), 350);
    };

    const toggle = (id) => setSelected((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
    const pageIds = books.map((b) => b.id);
    const showChecks = canSelect && (selecting || state.view === 'table' || selected.length > 0);

    // Group headers between the page's books, in the server's order.
    const groupCount = useMemo(() => Object.fromEntries((shelf.groups ?? []).map((g) => [g.name ?? '', g.count])), [shelf.groups]);
    const grouped = state.group === 'series';
    const sections = useMemo(() => {
        if (!grouped) return [{ key: null, books }];
        const out = [];
        for (const book of books) {
            const key = book.series?.name ?? '';
            if (!out.length || out[out.length - 1].key !== key) out.push({ key, books: [] });
            out[out.length - 1].books.push(book);
        }
        return out;
    }, [books, grouped]);

    return (
        <div data-a={`${prefix}:shelf`}>
            {filters && (
                <nav className="mt-6 flex flex-wrap gap-1.5" aria-label={t('shelf.filter_label')}>
                    {filters.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            data-a={`${prefix === 'shelf' ? 'books' : prefix}:filter:${item.key}`}
                            aria-pressed={state.filter === item.key}
                            onClick={() => go({ filter: item.key, page: 1 })}
                            className={`rounded-badge px-3 py-1.5 text-[12.5px] font-medium transition ${
                                state.filter === item.key ? 'bg-brand-fill text-white' : 'bg-bg-surface text-text-secondary hover:text-text-primary'
                            }`}
                        >
                            {item.label}
                            <span className="ml-1.5">{item.count}</span>
                        </button>
                    ))}
                </nav>
            )}

            <Toolbar shelf={shelf} state={state} q={q} onSearch={onSearch} go={go} routeName={routeName} />

            {canSelect && books.length > 0 && (
                <div className="mt-3 flex flex-wrap items-center gap-2 text-[12.5px]">
                    {state.view === 'cards' && !selecting && selected.length === 0 ? (
                        <button type="button" data-a="books:prepare:start" onClick={() => setSelecting(true)} className={chip}>
                            {t('shelf.select_books')}
                        </button>
                    ) : (
                        <>
                            <button type="button" data-a="shelf:select-page" onClick={() => setSelected((ids) => [...ids, ...pageIds.filter((id) => !ids.includes(id))])} className={chip}>
                                {t('shelf.select_page')}
                            </button>
                            <button type="button" data-a="shelf:select-all" onClick={() => setSelected(shelf.ids)} className={chip}>
                                {t('shelf.select_all', { count: Math.min(shelf.page.total, shelf.max_select) })}
                            </button>
                            {(selected.length > 0 || selecting) && (
                                <button
                                    type="button"
                                    data-a="shelf:select-none"
                                    onClick={() => {
                                        setSelected([]);
                                        setSelecting(false);
                                    }}
                                    className="px-2 text-text-secondary hover:text-text-primary"
                                >
                                    {t('shelf.clear')}
                                </button>
                            )}
                        </>
                    )}
                    <span className="ml-auto text-text-secondary" data-a="shelf:range">
                        {shelf.page.total === 0 ? t('shelf.no_books') : t('shelf.range', { from: shelf.page.from, to: shelf.page.to, total: shelf.page.total })}
                    </span>
                </div>
            )}

            {books.length === 0 ? (
                empty ?? <p className="mt-5 rounded-panel border border-dashed border-border-default p-10 text-center text-[13.5px] text-text-secondary">{t('shelf.no_match')}</p>
            ) : state.view === 'table' ? (
                <ShelfTable
                    books={books}
                    columns={state.columns}
                    sections={sections}
                    grouped={grouped}
                    groupCount={groupCount}
                    collapsed={collapsed}
                    setCollapsed={setCollapsed}
                    selected={selected}
                    toggle={toggle}
                    canSelect={canSelect}
                    linkFor={linkFor}
                />
            ) : (
                <div className="mt-5 space-y-5">
                    {sections.map((section) => (
                        <section key={section.key ?? 'all'} aria-label={grouped ? section.key || t('shelf.no_series') : undefined}>
                            {grouped && (
                                <GroupHeader name={section.key} count={groupCount[section.key] ?? section.books.length} collapsed={collapsed} setCollapsed={setCollapsed} />
                            )}
                            {!(grouped && collapsed.includes(section.key)) && (
                                <ul className={cardsClassName}>
                                    {section.books.map((book) => (
                                        <Fragment key={book.id}>
                                            {renderCard(book, { selecting: showChecks, selected: selected.includes(book.id), toggle: () => toggle(book.id) })}
                                        </Fragment>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </div>
            )}

            <Pager links={shelf.page.links} />

            {canSelect && selected.length > 0 && (
                <BulkBar
                    shelf={shelf}
                    selected={selected}
                    setSelected={setSelected}
                    houseStyles={houseStyles}
                    coverTemplates={coverTemplates}
                    extra={extraBulk}
                />
            )}
        </div>
    );
}

function GroupHeader({ name, count, collapsed, setCollapsed }) {
    const { t } = useI18n();
    const closed = collapsed.includes(name);

    return (
        <h2 className="mb-2">
            <button
                type="button"
                data-a={`shelf:group:${name || 'none'}`}
                aria-expanded={!closed}
                onClick={() => setCollapsed((list) => (closed ? list.filter((n) => n !== name) : [...list, name]))}
                className="flex w-full items-center gap-2 border-b border-border-default pb-1.5 text-left font-display text-[15px] font-bold text-text-primary"
            >
                <span aria-hidden="true" className={`inline-block transition ${closed ? '-rotate-90' : ''}`}>▾</span>
                {name || t('shelf.no_series')}
                <span className="text-[12px] font-medium text-text-secondary">{t('shelf.group_count', { count })}</span>
            </button>
        </h2>
    );
}

/** Search, sort, group, view, columns, page size and saved views. */
function Toolbar({ shelf, state, q, onSearch, go, routeName }) {
    const { t } = useI18n();

    return (
        <div className="mt-4 flex flex-wrap items-center gap-2" data-a="shelf:toolbar">
            <label className="min-w-[180px] flex-1">
                <span className="sr-only">{t('shelf.search_label')}</span>
                <input
                    type="search"
                    value={q}
                    data-a="shelf:search"
                    placeholder={t('shelf.search')}
                    onChange={(e) => onSearch(e.target.value)}
                    className={`${control} w-full`}
                />
            </label>

            <label className="flex items-center gap-1">
                <span className="sr-only">{t('shelf.sort_by')}</span>
                <select data-a="shelf:sort" value={state.sort} onChange={(e) => go({ sort: e.target.value, dir: undefined, page: 1 })} className={control}>
                    {shelf.options.sorts.map((s) => (
                        <option key={s} value={s}>{sortLabel(s)}</option>
                    ))}
                </select>
            </label>
            <button
                type="button"
                data-a="shelf:dir"
                aria-label={state.dir === 'asc' ? t('shelf.asc') : t('shelf.desc')}
                onClick={() => go({ dir: state.dir === 'asc' ? 'desc' : 'asc', page: 1 })}
                className={`${control} px-2`}
            >
                {state.dir === 'asc' ? '↑' : '↓'}
            </button>

            <label className="flex items-center gap-1.5 text-[12.5px] text-text-body">
                <input type="checkbox" data-a="shelf:group" checked={state.group === 'series'} onChange={(e) => go({ group: e.target.checked ? 'series' : 'none', page: 1 })} />
                {t('shelf.group_by_series')}
            </label>

            <div role="group" aria-label={t('shelf.view')} className="flex overflow-hidden rounded-control border border-border-input">
                {['cards', 'table'].map((v) => (
                    <button
                        key={v}
                        type="button"
                        data-a={`shelf:view:${v}`}
                        aria-pressed={state.view === v}
                        onClick={() => go({ view: v })}
                        className={`px-3 py-1.5 text-[12.5px] font-semibold ${state.view === v ? 'bg-brand-fill text-white' : 'bg-bg-surface text-text-body'}`}
                    >
                        {v === 'cards' ? t('shelf.cards') : t('shelf.table')}
                    </button>
                ))}
            </div>

            {state.view === 'table' && <ColumnPicker shelf={shelf} state={state} go={go} />}

            <label>
                <span className="sr-only">{t('shelf.per_page_label')}</span>
                <select data-a="shelf:per-page" value={state.per_page} onChange={(e) => go({ per_page: Number(e.target.value), page: 1 })} className={control}>
                    {shelf.options.per_page.map((n) => (
                        <option key={n} value={n}>{t('shelf.per_page', { count: n })}</option>
                    ))}
                </select>
            </label>

            <SavedViews shelf={shelf} state={state} routeName={routeName} />
        </div>
    );
}

function ColumnPicker({ shelf, state, go }) {
    const { t } = useI18n();
    const toggleColumn = (column) => {
        const on = state.columns.includes(column);
        go({ columns: on ? state.columns.filter((c) => c !== column) : shelf.options.columns.filter((c) => c === column || state.columns.includes(c)) });
    };

    return (
        <details className="relative" data-a="shelf:columns">
            <summary className={`${control} flex cursor-pointer list-none items-center`}>{t('shelf.columns', { count: state.columns.length })}</summary>
            <fieldset className="absolute right-0 z-20 mt-1 w-[200px] rounded-panel border border-border-default bg-bg-surface p-2 shadow-lg">
                <legend className="sr-only">{t('shelf.columns_legend')}</legend>
                {shelf.options.columns.map((column) => (
                    <label key={column} className="flex items-center gap-2 px-1 py-1 text-[12.5px] text-text-body">
                        <input type="checkbox" data-a={`shelf:column:${column}`} checked={state.columns.includes(column)} onChange={() => toggleColumn(column)} />
                        {columnLabel(column)}
                    </label>
                ))}
            </fieldset>
        </details>
    );
}

/** Named views: apply, save the current one under a name, delete. */
function SavedViews({ shelf, state, routeName }) {
    const { t } = useI18n();
    const [saved, setSaved] = useState(shelf.saved);
    const [naming, setNaming] = useState(false);
    const [name, setName] = useState('');
    const [error, setError] = useState(null);

    useEffect(() => setSaved(shelf.saved), [shelf.saved]);

    const same = (a, b) => JSON.stringify({ ...a, page: undefined }) === JSON.stringify({ ...b, page: undefined });
    const current = saved.find((v) => same(v.state, { ...state, page: undefined }));

    const apply = (id) => {
        const view = saved.find((v) => String(v.id) === String(id));
        if (!view) return;
        router.get(route(routeName), toQuery(view.state), { preserveScroll: true, replace: true });
        window.axios.put(route('shelf.current', shelf.view), { state: view.state }).catch(() => {});
    };

    const save = async (event) => {
        event.preventDefault();
        setError(null);
        try {
            const { data } = await window.axios.post(route('shelf.views.store', shelf.view), { name, state });
            setSaved(data.saved);
            setNaming(false);
            setName('');
        } catch (e) {
            setError(e.response?.data?.message ?? t('shelfbulk.view_not_saved'));
        }
    };

    const remove = async () => {
        if (!current || !window.confirm(t('shelfbulk.view_delete_confirm', { name: current.name }))) return;
        await window.axios.delete(route('shelf.views.destroy', current.id));
        setSaved((list) => list.filter((v) => v.id !== current.id));
    };

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {saved.length > 0 && (
                <label>
                    <span className="sr-only">{t('shelfbulk.saved_views')}</span>
                    <select data-a="shelf:views" value={current?.id ?? ''} onChange={(e) => apply(e.target.value)} className={control}>
                        <option value="">{current ? current.name : t('shelfbulk.saved_views_pick')}</option>
                        {saved.map((v) => (
                            <option key={v.id} value={v.id}>{v.name}</option>
                        ))}
                    </select>
                </label>
            )}
            {current && (
                <button type="button" data-a="shelf:views:delete" onClick={remove} className="px-1 text-[12px] text-text-secondary hover:text-status-danger" aria-label={t('shelfbulk.view_delete_label', { name: current.name })}>
                    {t('shelfbulk.view_delete')}
                </button>
            )}
            {naming ? (
                <form onSubmit={save} className="flex items-center gap-1">
                    <label>
                        <span className="sr-only">{t('shelfbulk.view_name')}</span>
                        <input autoFocus required maxLength={60} value={name} data-a="shelf:views:name" placeholder={t('shelfbulk.view_name_placeholder')} onChange={(e) => setName(e.target.value)} className={`${control} w-[150px]`} />
                    </label>
                    <button type="submit" data-a="shelf:views:save" className={chip}>{t('shelfbulk.save')}</button>
                    <button type="button" onClick={() => setNaming(false)} className="px-1 text-[12px] text-text-secondary">{t('common.cancel')}</button>
                    {error && <span role="alert" className="text-[11.5px] text-status-danger">{error}</span>}
                </form>
            ) : (
                <button type="button" data-a="shelf:views:new" onClick={() => setNaming(true)} className="px-1 text-[12px] font-medium text-brand-primary hover:underline">
                    {t('shelfbulk.save_view')}
                </button>
            )}
        </div>
    );
}

function when(iso) {
    return iso ? formatDate(iso, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
}

/** One cell of the table, for a column. */
function Cell({ book, column }) {
    const extra = book.columns ?? {};

    switch (column) {
        case 'words':
            return formatNumber(book.word_count ?? book.words ?? 0);
        case 'status':
            return <span className="rounded-badge bg-bg-subtle px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-wide text-text-secondary">{['draft', 'in_review', 'published', 'archived'].includes(book.status) ? t(`book.status_${book.status}`) : String(book.status).replace('_', ' ')}</span>;
        case 'series':
            return book.series ? `${book.series.name}${book.series.number ? ` · ${book.series.number}` : ''}` : '—';
        case 'language':
            return book.language || '—';
        case 'isbns': {
            const isbns = Object.entries(extra.isbns ?? {});
            return isbns.length ? (
                <ul>
                    {isbns.map(([format, isbn]) => (
                        <li key={format} className="whitespace-nowrap"><span className="text-text-secondary">{format}</span> {isbn}</li>
                    ))}
                </ul>
            ) : '—';
        }
        case 'channels':
            return (extra.channels ?? []).length ? extra.channels.join(', ') : '—';
        case 'exports': {
            const files = Object.entries(extra.exports ?? {}).filter(([, f]) => f.at || f.rendering);
            return files.length ? (
                <ul>
                    {files.map(([format, f]) => (
                        <li key={format} className="whitespace-nowrap" data-a={`shelf:export:${book.id}:${format}`}>
                            <span className="text-text-secondary">{formatLabel(format)}</span> {when(f.at)}{' '}
                            {f.rendering ? (
                                <span className="text-status-info">{t('shelf.rendering')}</span>
                            ) : f.fresh ? (
                                <span className="text-status-success">{t('shelf.current')}</span>
                            ) : (
                                <span className="font-semibold text-status-warning" title={f.reason ?? ''}>{t('shelf.stale')}</span>
                            )}
                        </li>
                    ))}
                </ul>
            ) : '—';
        }
        case 'house_style':
            return extra.house_style ?? '—';
        case 'updated':
            return when(book.updated_iso);
        default:
            return null;
    }
}

function ShelfTable({ books, columns, sections, grouped, groupCount, collapsed, setCollapsed, selected, toggle, canSelect, linkFor }) {
    const { t } = useI18n();
    const span = columns.length + 1 + (canSelect ? 1 : 0);

    return (
        <>
            {/* A table from 640px; below it, a compact list of the same books. */}
            <div className="mt-4 hidden overflow-x-auto rounded-panel border border-border-default bg-bg-surface sm:block">
                <table className="w-full text-left text-[12.5px]" data-a="shelf:table">
                    <thead className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                        <tr>
                            {canSelect && <th scope="col" className="w-8 px-3 py-2"><span className="sr-only">{t('shelf.selected')}</span></th>}
                            <th scope="col" className="px-3 py-2">{t('shelf.sort_title')}</th>
                            {columns.map((c) => (
                                <th key={c} scope="col" className="px-3 py-2" data-a={`shelf:th:${c}`}>{columnLabel(c)}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {sections.map((section) => (
                            <Fragment key={section.key ?? 'all'}>
                                {grouped && (
                                    <tr className="bg-bg-subtle">
                                        <td colSpan={span} className="px-3 pt-3 pb-1">
                                            <GroupHeader name={section.key} count={groupCount[section.key] ?? section.books.length} collapsed={collapsed} setCollapsed={setCollapsed} />
                                        </td>
                                    </tr>
                                )}
                                {!(grouped && collapsed.includes(section.key)) &&
                                    section.books.map((book) => (
                                        <tr key={book.id} className="border-b border-border-default align-top last:border-0" data-a={`shelf:row:${book.id}`}>
                                            {canSelect && (
                                                <td className="px-3 py-2">
                                                    <input type="checkbox" aria-label={t('shelf.select_book', { title: book.title })} data-a={`books:select:${book.id}`} checked={selected.includes(book.id)} onChange={() => toggle(book.id)} />
                                                </td>
                                            )}
                                            <th scope="row" className="px-3 py-2 font-semibold text-text-primary">
                                                <Link href={linkFor(book)} className="hover:underline">{book.title}</Link>
                                                {book.subtitle && <span className="block text-[11.5px] font-normal text-text-secondary">{book.subtitle}</span>}
                                            </th>
                                            {columns.map((c) => (
                                                <td key={c} className="px-3 py-2 text-text-body">
                                                    <Cell book={book} column={c} />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 space-y-4 sm:hidden" data-a="shelf:compact">
                {sections.map((section) => (
                    <section key={section.key ?? 'all'} aria-label={grouped ? section.key || t('shelf.no_series') : undefined}>
                        {grouped && <GroupHeader name={section.key} count={groupCount[section.key] ?? section.books.length} collapsed={collapsed} setCollapsed={setCollapsed} />}
                        {!(grouped && collapsed.includes(section.key)) && (
                            <ul className="divide-y divide-border-default rounded-panel border border-border-default bg-bg-surface">
                                {section.books.map((book) => (
                                    <li key={book.id} className="flex gap-3 px-3 py-2.5">
                                        {canSelect && (
                                            <input type="checkbox" aria-label={t('shelf.select_book', { title: book.title })} data-a={`shelf:compact-select:${book.id}`} checked={selected.includes(book.id)} onChange={() => toggle(book.id)} className="mt-1 h-4 w-4 shrink-0" />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <Link href={linkFor(book)} className="block truncate text-[13.5px] font-semibold text-text-primary">{book.title}</Link>
                                            <p className="mt-0.5 flex flex-wrap gap-x-2 text-[11.5px] text-text-secondary">
                                                {columns.filter((c) => !['isbns', 'exports'].includes(c)).map((c) => (
                                                    <span key={c}><span className="sr-only">{t('shelfbulk.column_prefix', { column: columnLabel(c) })}</span><Cell book={book} column={c} /></span>
                                                ))}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>
        </>
    );
}

const RESULT_TONE = { true: 'bg-status-success-bg text-status-success', false: 'bg-status-danger-bg text-status-danger' };

/**
 * The bulk bar: sticky at the bottom of the screen while books are
 * selected. Each action opens its own small form; destructive ones ask
 * first; every run ends in a summary of what was done and what was
 * skipped, and why.
 */
function BulkBar({ shelf, selected, setSelected, houseStyles, coverTemplates, extra }) {
    const { t } = useI18n();
    const [action, setAction] = useState(null);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [reason, setReason] = useState('');
    const needsReason = shelf.needs_reason;

    const run = async (name, params = {}) => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await window.axios.post(route('shelf.bulk'), { action: name, books: selected, reason: reason || null, ...params });
            setResult(data);
            setAction(null);
            if (['archive', 'unarchive', 'series', 'house_style', 'cover_template'].includes(name)) {
                router.reload({ preserveScroll: true });
            }
        } catch (e) {
            setError(e.response?.data?.message ?? t('shelfbulk.failed'));
        } finally {
            setBusy(false);
        }
    };

    const csv = route('shelf.csv', { books: selected, ...(reason ? { reason } : {}) });
    const actions = [
        { key: 'archive', tKey: 'shelfbulk.archive' },
        { key: 'unarchive', tKey: 'shelfbulk.unarchive' },
        { key: 'series', tKey: 'shelfbulk.set_series' },
        { key: 'house_style', tKey: 'shelfbulk.house_style_action' },
        { key: 'cover_template', tKey: 'shelfbulk.cover_template_action' },
        { key: 'regenerate', tKey: 'shelfbulk.regenerate_action' },
        { key: 'prepare', tKey: 'shelfbulk.prepare' },
    ];

    return (
        <section
            data-a="books:prepare"
            aria-label={t('shelfbulk.bulk_actions')}
            className="sticky bottom-0 z-30 mt-5 -mx-4 border-t border-border-default bg-bg-surface/95 px-4 py-3 shadow-[0_-8px_24px_-16px_rgba(15,34,61,.35)] backdrop-blur sm:mx-0 sm:rounded-panel sm:border"
        >
            <div className="flex flex-wrap items-center gap-2" aria-live="polite">
                <p className="mr-1 text-[12.5px] font-semibold text-text-primary" data-a="shelf:selected">
                    {t('shelfbulk.selected', { count: selected.length })}
                </p>
                {actions.map(({ key, tKey }) => (
                    <button
                        key={key}
                        type="button"
                        data-a={`shelf:bulk:${key}`}
                        aria-expanded={action === key}
                        disabled={busy}
                        onClick={() => setAction(action === key ? null : key)}
                        className={action === key ? primary : chip}
                    >
                        {t(tKey)}
                    </button>
                ))}
                <a href={csv} data-a="shelf:bulk:csv" className={chip} download>
                    {t('shelfbulk.metadata_csv')}
                </a>
                <button type="button" onClick={() => setSelected([])} className="ml-auto px-2 text-[12px] text-text-secondary hover:text-text-primary">
                    {t('shelf.clear')}
                </button>
            </div>

            {needsReason && (
                <label className="mt-2 flex flex-wrap items-center gap-2 text-[12px] text-text-body">
                    <span>{t('shelfbulk.reason')}</span>
                    <input value={reason} data-a="shelf:bulk:reason" minLength={4} onChange={(e) => setReason(e.target.value)} className={`${control} min-w-0 flex-1`} />
                </label>
            )}

            {action === 'archive' && (
                <Confirm busy={busy} label={t('shelfbulk.archive_n', { count: selected.length })} onConfirm={() => run('archive')} onCancel={() => setAction(null)}>
                    {t('shelfbulk.archive_help')}
                </Confirm>
            )}
            {action === 'unarchive' && (
                <Confirm busy={busy} label={t('shelfbulk.unarchive_n', { count: selected.length })} onConfirm={() => run('unarchive')} onCancel={() => setAction(null)}>
                    {t('shelfbulk.unarchive_help')}
                </Confirm>
            )}
            {action === 'series' && <SeriesForm busy={busy} count={selected.length} onRun={(p) => run('series', p)} />}
            {action === 'house_style' && <HouseStyleForm busy={busy} houseStyles={houseStyles} onRun={(p) => run('house_style', p)} />}
            {action === 'cover_template' && <CoverTemplateForm busy={busy} selected={selected} coverTemplates={coverTemplates} onRun={(p) => run('cover_template', p)} />}
            {action === 'regenerate' && <RegenerateForm busy={busy} formats={shelf.options.regenerate} onRun={(p) => run('regenerate', p)} />}
            {action === 'prepare' && (
                <Confirm busy={busy} label={t('shelfbulk.prepare')} dataA="books:prepare:run" onConfirm={() => run('prepare')} onCancel={() => setAction(null)}>
                    {t('shelfbulk.prepare_help')}
                </Confirm>
            )}

            {error && <p role="alert" className="mt-2 text-[12px] text-status-danger">{error}</p>}
            {result && <Result result={result} onClose={() => setResult(null)} />}
            {extra && extra(selected)}
        </section>
    );
}

function Confirm({ children, label, onConfirm, onCancel, busy, dataA = 'shelf:bulk:confirm' }) {
    const { t } = useI18n();
    return (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border-default pt-3">
            <p className="min-w-0 flex-1 text-[12px] text-text-secondary">{children}</p>
            <button type="button" data-a={dataA} disabled={busy} onClick={onConfirm} className={primary}>
                {busy ? t('shelfbulk.working') : label}
            </button>
            <button type="button" onClick={onCancel} className="px-2 text-[12px] text-text-secondary">{t('common.cancel')}</button>
        </div>
    );
}

function SeriesForm({ count, onRun, busy }) {
    const { t } = useI18n();
    const [name, setName] = useState('');
    const [start, setStart] = useState(1);

    return (
        <form
            className="mt-3 flex flex-wrap items-end gap-2 border-t border-border-default pt-3"
            data-a="shelf:series"
            onSubmit={(e) => {
                e.preventDefault();
                onRun({ series_name: name, start: Number(start) || 1 });
            }}
        >
            <label className="text-[12px] text-text-body">
                {t('shelfbulk.series_name')}
                <input value={name} data-a="shelf:series:name" maxLength={255} onChange={(e) => setName(e.target.value)} className={`${control} mt-1 block w-[220px] max-w-full`} />
            </label>
            <label className="text-[12px] text-text-body">
                {t('shelfbulk.first_number')}
                <input type="number" min={1} max={9999} value={start} data-a="shelf:series:start" onChange={(e) => setStart(e.target.value)} className={`${control} mt-1 block w-[90px]`} />
            </label>
            <button type="submit" data-a="shelf:series:run" disabled={busy} className={primary}>
                {name.trim() ? t('shelfbulk.number_in_order', { count }) : t('shelfbulk.no_series')}
            </button>
            <p className="w-full text-[11px] text-text-secondary">{t('shelfbulk.series_help')}</p>
        </form>
    );
}

/** The account's house styles (HouseStyles), or the House catalog's given ones. */
function HouseStyleForm({ houseStyles, onRun, busy }) {
    const { t } = useI18n();
    const [styles, setStyles] = useState(houseStyles);
    const [styleId, setStyleId] = useState(houseStyles?.[0]?.id ?? '');
    const [follow, setFollow] = useState(true);

    useEffect(() => {
        if (houseStyles) return;
        window.axios.get(route('house-styles.index')).then(({ data }) => {
            setStyles(data.styles);
            setStyleId(data.styles[0]?.id ?? '');
        }).catch(() => setStyles([]));
    }, [houseStyles]);

    if (!styles) return <p className="mt-3 text-[12px] text-text-secondary">{t('shelfbulk.loading_styles')}</p>;

    if (styles.length === 0) {
        return (
            <p className="mt-3 text-[11.5px] text-text-secondary" data-a="books:house-style:none">
                {t('shelfbulk.no_house_styles')}
            </p>
        );
    }

    return (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border-default pt-3" data-a="books:house-style">
            <label className="text-[12.5px] font-medium text-text-body" htmlFor="shelf-house-style">{t('shelfbulk.house_style')}</label>
            <select id="shelf-house-style" value={styleId} data-a="books:house-style:pick" onChange={(e) => setStyleId(Number(e.target.value))} className={`${control} min-w-0 max-w-full`}>
                {styles.map((style) => (
                    <option key={style.id} value={style.id}>{style.name}{style.summary ? ` — ${style.summary}` : ''}</option>
                ))}
            </select>
            <label className="flex items-center gap-1.5 text-[12px] text-text-body">
                <input type="checkbox" checked={follow} data-a="books:house-style:follow" onChange={(e) => setFollow(e.target.checked)} />
                {t('shelfbulk.follow')}
            </label>
            <button type="button" data-a="books:house-style:apply" disabled={busy || !styleId} onClick={() => onRun({ house_style_id: styleId, follow })} className={primary}>
                {busy ? t('shelfbulk.applying') : t('shelfbulk.apply_selected')}
            </button>
            <p className="w-full text-[11px] text-text-secondary">{t('shelfbulk.house_style_help')}</p>
        </div>
    );
}

/**
 * Apply a cover template (CoverTemplates) to the selected books: preview
 * first — each book's own front with the template, and where its text
 * overflows the template's boxes — then apply. Books with an uploaded
 * finished cover are left as they are unless the artwork choice is made.
 */
function CoverTemplateForm({ coverTemplates, selected, onRun, busy }) {
    const { t } = useI18n();
    const [templates, setTemplates] = useState(coverTemplates);
    const [templateId, setTemplateId] = useState(coverTemplates?.[0]?.id ?? '');
    const [follow, setFollow] = useState(true);
    const [artwork, setArtwork] = useState('');
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (coverTemplates) return;
        window.axios.get(route('cover-templates.index')).then(({ data }) => {
            setTemplates(data.templates);
            setTemplateId(data.templates[0]?.id ?? '');
        }).catch(() => setTemplates([]));
    }, [coverTemplates]);

    // A new choice of template or artwork needs a new preview.
    useEffect(() => setPreview(null), [templateId, artwork, selected]);

    if (!templates) return <p className="mt-3 text-[12px] text-text-secondary">{t('shelfbulk.loading_templates')}</p>;

    if (templates.length === 0) {
        return (
            <p className="mt-3 text-[11.5px] text-text-secondary" data-a="shelf:cover-template:none">
                {t('shelfbulk.no_cover_templates')}
            </p>
        );
    }

    const look = async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await window.axios.post(route('cover-templates.preview', { template: templateId }), { books: selected.slice(0, 50), artwork: artwork || null });
            setPreview(data.books);
        } catch (e) {
            setError(e.response?.data?.message ?? t('shelfbulk.preview_failed'));
        } finally {
            setLoading(false);
        }
    };

    const waiting = preview?.filter((b) => b.needs_choice).length ?? 0;

    return (
        <div className="mt-3 space-y-2 border-t border-border-default pt-3" data-a="shelf:cover-template">
            <div className="flex flex-wrap items-center gap-2">
                <label className="text-[12.5px] font-medium text-text-body" htmlFor="shelf-cover-template">{t('shelfbulk.cover_template')}</label>
                <select id="shelf-cover-template" value={templateId} data-a="shelf:cover-template:pick" onChange={(e) => setTemplateId(Number(e.target.value))} className={`${control} min-w-0 max-w-full`}>
                    {templates.map((tpl) => (
                        <option key={tpl.id} value={tpl.id}>{tpl.name}{tpl.summary ? ` — ${tpl.summary}` : ''}</option>
                    ))}
                </select>
                <label className="flex items-center gap-1.5 text-[12px] text-text-body">
                    <input type="checkbox" checked={follow} data-a="shelf:cover-template:follow" onChange={(e) => setFollow(e.target.checked)} />
                    {t('shelfbulk.follow')}
                </label>
                <button type="button" data-a="shelf:cover-template:preview" disabled={loading || !templateId} onClick={look} className={chip}>
                    {loading ? t('shelfbulk.drawing') : t('shelfbulk.preview')}
                </button>
                <button
                    type="button"
                    data-a="shelf:cover-template:apply"
                    disabled={busy || !templateId || !preview}
                    onClick={() => onRun({ cover_template_id: templateId, follow, artwork: artwork || null })}
                    className={primary}
                >
                    {busy ? t('shelfbulk.applying') : t('shelfbulk.apply_n', { count: selected.length })}
                </button>
            </div>
            <fieldset className="flex flex-wrap gap-x-3 gap-y-1 text-[12px] text-text-body" data-a="shelf:cover-template:artwork">
                <legend className="mb-0.5 text-[11.5px] font-semibold text-text-secondary">{t('shelfbulk.artwork_legend')}</legend>
                {[{ key: '', tKey: 'shelfbulk.artwork_leave' }, { key: 'keep', tKey: 'shelfbulk.artwork_keep' }, { key: 'replace', tKey: 'shelfbulk.artwork_replace' }].map(({ key, tKey }) => (
                    <label key={key || 'none'} className="flex items-center gap-1.5">
                        <input type="radio" name="shelf-cover-artwork" value={key} checked={artwork === key} data-a={`shelf:cover-template:artwork:${key || 'none'}`} onChange={() => setArtwork(key)} />
                        {t(tKey)}
                    </label>
                ))}
            </fieldset>
            <p className="text-[11px] text-text-secondary">
                {t('shelfbulk.cover_template_help')}
            </p>
            {error && <p role="alert" className="text-[12px] text-status-danger">{error}</p>}
            {preview && (
                <>
                    {waiting > 0 && (
                        <p className="rounded-control bg-status-warning-bg px-2.5 py-1.5 text-[11.5px] text-status-warning" data-a="shelf:cover-template:waiting">
                            {t('shelfbulk.waiting', { count: waiting })}
                        </p>
                    )}
                    <ul className="grid max-h-[340px] grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-4 lg:grid-cols-6" data-a="shelf:cover-template:previews">
                        {preview.map((b) => (
                            <li key={b.id} data-a={`shelf:cover-template:preview:${b.id}`} className="rounded-control border border-border-default p-1.5">
                                {b.thumb ? (
                                    <img src={b.thumb} alt={t('shelfbulk.preview_alt', { title: b.title })} className="aspect-[5/8] w-full rounded-[2px] object-cover" />
                                ) : (
                                    <div className="flex aspect-[5/8] w-full items-center justify-center rounded-[2px] bg-bg-subtle text-[11px] text-text-secondary">{b.message || t('shelfbulk.no_preview')}</div>
                                )}
                                <p className="mt-1 line-clamp-2 text-[11.5px] font-semibold text-text-primary">{b.title ?? `#${b.id}`}</p>
                                {b.needs_choice && <p className="text-[10.5px] text-status-warning">{t('shelfbulk.uploaded_left')}</p>}
                                {b.flags?.map((f, i) => (
                                    <p key={i} className="text-[10.5px] leading-snug text-status-warning" data-a="shelf:cover-template:flag">{f}</p>
                                ))}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}

function RegenerateForm({ formats, onRun, busy }) {
    const { t } = useI18n();
    const [chosen, setChosen] = useState(['epub', 'pdf_interior']);
    const [missing, setMissing] = useState(false);
    const flip = (f) => setChosen((list) => (list.includes(f) ? list.filter((x) => x !== f) : [...list, f]));

    return (
        <fieldset className="mt-3 flex flex-wrap items-center gap-3 border-t border-border-default pt-3" data-a="shelf:regenerate">
            <legend className="sr-only">{t('shelfbulk.formats_legend')}</legend>
            {formats.map((f) => (
                <label key={f} className="flex items-center gap-1.5 text-[12.5px] text-text-body">
                    <input type="checkbox" data-a={`shelf:regenerate:${f}`} checked={chosen.includes(f)} onChange={() => flip(f)} />
                    {formatLabel(f)}
                </label>
            ))}
            <label className="flex items-center gap-1.5 text-[12px] text-text-secondary">
                <input type="checkbox" data-a="shelf:regenerate:missing" checked={missing} onChange={(e) => setMissing(e.target.checked)} />
                {t('shelfbulk.also_missing')}
            </label>
            <button type="button" data-a="shelf:regenerate:run" disabled={busy || chosen.length === 0} onClick={() => onRun({ formats: chosen, missing })} className={primary}>
                {busy ? t('shelfbulk.queuing') : t('shelfbulk.regenerate')}
            </button>
            <p className="w-full text-[11px] text-text-secondary">{t('shelfbulk.regenerate_help')}</p>
        </fieldset>
    );
}

/** What was done and what was skipped, and for a regenerate its progress. */
function Result({ result, onClose }) {
    const { t } = useI18n();
    return (
        <div className="mt-3 border-t border-border-default pt-3" data-a="shelf:result">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-[12.5px] font-semibold text-text-primary" data-a="shelf:result:summary">
                    {t('shelfbulk.result_summary', { done: result.done, skipped: result.skipped })}
                </p>
                {result.links && (
                    <span className="flex flex-wrap gap-2" data-a="books:prepare:result">
                        <a href={result.links.google_play} className={primary}>{t('shelfbulk.google_play_zip')}</a>
                        <a href={result.links.kdp_checklist} target="_blank" rel="noopener noreferrer" className={chip}>{t('shelfbulk.kdp_checklist')}</a>
                    </span>
                )}
                <button type="button" onClick={onClose} className="ml-auto px-2 text-[12px] text-text-secondary">{t('common.dismiss')}</button>
            </div>
            {result.action === 'regenerate' && <Progress result={result} />}
            <ul className="mt-2 max-h-[220px] space-y-1 overflow-y-auto text-[12.5px]" data-a={result.action === 'house_style' ? 'books:house-style:result' : undefined}>
                {result.rows.map((row) => (
                    <li key={row.id} data-a={`shelf:result:${row.id}`}>
                        <span className={`mr-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ${RESULT_TONE[row.ok]}`}>{row.ok ? t('shelfbulk.row_done') : t('shelfbulk.row_skipped')}</span>
                        <span className="font-semibold text-text-primary">{row.title ?? `#${row.id}`}</span> <span className="text-text-secondary">{row.message}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Polls where the queued files stand until none is rendering. */
function Progress({ result }) {
    const { t } = useI18n();
    const books = useMemo(() => result.rows.filter((r) => r.ok).map((r) => r.id), [result]);
    const formats = useMemo(() => [...new Set(result.rows.flatMap((r) => (r.files ?? []).filter((f) => ['queued', 'already'].includes(f.result)).map((f) => f.format)))], [result]);
    const [state, setState] = useState(null);

    useEffect(() => {
        if (books.length === 0 || formats.length === 0) return undefined;
        let stop = false;
        let timer = null;

        const poll = async () => {
            try {
                const { data } = await window.axios.get(route('shelf.progress', { books, formats }));
                if (stop) return;
                setState(data.books);
                const rendering = Object.values(data.books).some((files) => Object.values(files).some((f) => f.rendering));
                if (rendering) timer = setTimeout(poll, 3000);
            } catch {
                // A failed poll leaves the last known progress on screen.
            }
        };

        poll();

        return () => {
            stop = true;
            clearTimeout(timer);
        };
    }, [books, formats]);

    if (books.length === 0 || formats.length === 0) return null;

    const rendering = state ? Object.values(state).filter((files) => Object.values(files).some((f) => f.rendering)).length : books.length;
    const finished = books.length - rendering;

    return (
        <div className="mt-2" data-a="shelf:progress">
            <div className="h-[6px] overflow-hidden rounded-full bg-bg-subtle" role="progressbar" aria-valuemin={0} aria-valuemax={books.length} aria-valuenow={finished} aria-label={t('shelfbulk.files_regenerated')}>
                <div className="h-full rounded-full bg-brand-fill transition-[width]" style={{ width: `${Math.round((finished / books.length) * 100)}%` }} />
            </div>
            <p className="mt-1 text-[11px] text-text-secondary" data-a="shelf:progress:text">
                {rendering === 0 ? t('shelfbulk.all_finished', { count: books.length }) : t('shelfbulk.progress', { finished, total: books.length, rendering })}
            </p>
        </div>
    );
}
