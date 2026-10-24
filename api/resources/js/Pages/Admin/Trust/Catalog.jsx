import { money } from '@/Components/Store/money';
import AdminLayout, { Panel, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { Badge, Empty, Pager, ReasonAction } from '@/Components/Admin/Kit';
import { Chips, SearchBox } from '@/Components/Admin/TrustKit';

/**
 * Every book on the platform and every storefront listing: metadata only,
 * never a word of a manuscript. Taking a listing down hides its public page;
 * buyers keep their copies, and the author is told why.
 */
export default function Catalog(props) {
    const { tab } = props;

    return (
        <AdminLayout title="Catalog" active="catalog">
            <nav aria-label="Catalog" className="mb-4 flex gap-1 border-b border-border-default">
                {[
                    ['books', 'Books', 'admin.catalog.index'],
                    ['listings', 'Storefront listings', 'admin.catalog.listings'],
                ].map(([key, label, name]) => (
                    <Link
                        key={key}
                        href={route(name)}
                        data-a={`admin:catalog:tab:${key}`}
                        aria-current={tab === key ? 'page' : undefined}
                        className={`tap -mb-px border-b-2 px-3 py-2 text-[13px] font-semibold ${
                            tab === key ? 'border-brand-primary text-text-primary' : 'border-transparent text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {label}
                    </Link>
                ))}
            </nav>
            {tab === 'books' ? <Books {...props} /> : <Listings {...props} />}
        </AdminLayout>
    );
}

function Books({ books, filters, options, counts }) {
    const go = (next) => router.get(route('admin.catalog.index'), clean({ ...filters, ...next }), { preserveState: true });

    return (
        <>
            <p className="mb-3 text-[12.5px] text-text-secondary">
                {counts.books.toLocaleString()} books · {counts.deleted.toLocaleString()} in the bin · {counts.on_sale.toLocaleString()} on sale ·{' '}
                {counts.taken_down.toLocaleString()} taken down
            </p>
            <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                <SearchBox routeName="admin.catalog.index" params={clean({ ...filters, q: undefined })} value={filters.q} placeholder="Title, account email or name, or book id" dataA="admin:catalog:search" />
                <div className="flex flex-wrap gap-2">
                    <Select label="Type" value={filters.type} onChange={(type) => go({ type })} options={options.types} />
                    <Select label="Language" value={filters.language} onChange={(language) => go({ language })} options={options.languages} />
                </div>
            </div>
            <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <Chips
                    label="Status"
                    value={filters.status}
                    onChange={(status) => go({ status })}
                    dataA="admin:catalog:status"
                    options={[['', 'Active'], ['draft', 'Draft'], ['in_review', 'In review'], ['published', 'Published'], ['archived', 'Archived'], ['deleted', 'In the bin']]}
                />
                <Chips
                    label="Flags"
                    value={filters.filter}
                    onChange={(filter) => go({ filter })}
                    dataA="admin:catalog:filter"
                    options={[['', 'All'], ['on_sale', 'On sale'], ['reported', 'Reported'], ['taken_down', 'Taken down']]}
                />
            </div>

            <ul className="mt-4 space-y-2" data-a="admin:catalog:books">
                {books.data.length === 0 && <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">No books match.</li>}
                {books.data.map((book) => (
                    <li key={book.id} className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]" data-a={`admin:catalog:book:${book.id}`}>
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="min-w-0 break-words text-[13.5px] font-semibold text-text-primary">{book.title}</span>
                            <span className="text-text-muted">#{book.id}</span>
                            <span className="ml-auto flex flex-wrap gap-1.5 text-[11.5px]">
                                <Badge tone={book.status === 'deleted' ? 'danger' : book.status === 'published' ? 'success' : 'neutral'}>{book.status.replace('_', ' ')}</Badge>
                                {book.product?.on_sale && <Badge tone="success">On sale</Badge>}
                                {book.product?.taken_down && <Badge tone="danger">Taken down</Badge>}
                                {book.open_reports > 0 && <Badge tone="warning">{book.open_reports} report{book.open_reports === 1 ? '' : 's'}</Badge>}
                            </span>
                        </div>
                        <p className="mt-1 text-text-secondary">
                            {book.account ? (
                                <Link href={route('admin.accounts.show', book.account.id)} className="text-brand-primary hover:underline">
                                    {book.account.name} · {book.account.email}
                                </Link>
                            ) : (
                                'No account'
                            )}
                        </p>
                        <p className="mt-0.5 text-text-muted">
                            {book.type ?? 'no type'} · {book.language ?? 'no language'} · {book.words.toLocaleString()} words · {book.exports} export{book.exports === 1 ? '' : 's'} · created{' '}
                            {formatDate(book.created_at, false)} · updated {formatDate(book.updated_at)}
                        </p>
                    </li>
                ))}
            </ul>
            <Pager links={books.links} />
        </>
    );
}

function Listings({ listings, authors, filters, can }) {
    const go = (next) => router.get(route('admin.catalog.listings'), clean({ ...filters, ...next }), { preserveState: true });

    return (
        <>
            <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                <SearchBox routeName="admin.catalog.listings" params={clean({ ...filters, q: undefined })} value={filters.q} placeholder="Title or store address" dataA="admin:listings:search" />
                <Chips
                    label="State"
                    value={filters.state}
                    onChange={(state) => go({ state })}
                    dataA="admin:listings:state"
                    options={[['', 'All'], ['on_sale', 'On sale'], ['draft', 'Draft'], ['taken_down', 'Taken down']]}
                />
            </div>

            <ul className="mt-4 space-y-2" data-a="admin:listings">
                {listings.data.length === 0 && <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">No listings.</li>}
                {listings.data.map((p) => (
                    <li key={p.id} className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]" data-a={`admin:listing:${p.id}`}>
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="min-w-0 break-words text-[13.5px] font-semibold text-text-primary">{p.title}</span>
                            <span className="tabular-nums text-text-secondary">{money(p.price_cents, p.currency)}</span>
                            <span className="ml-auto flex flex-wrap gap-1.5 text-[11.5px]">
                                <Badge tone={p.state === 'on_sale' ? 'success' : p.state === 'taken_down' ? 'danger' : 'neutral'}>{p.state.replace('_', ' ')}</Badge>
                                {p.open_reports > 0 && <Badge tone="warning">{p.open_reports} open report{p.open_reports === 1 ? '' : 's'}</Badge>}
                            </span>
                        </div>
                        <p className="mt-1 text-text-secondary">
                            {p.account && (
                                <Link href={route('admin.accounts.show', p.account.id)} className="text-brand-primary hover:underline">
                                    {p.account.name} · {p.account.email}
                                </Link>
                            )}
                            {' · '}
                            {p.formats.join(', ').toUpperCase()} · {p.sold} sold
                        </p>
                        {p.state === 'taken_down' && (
                            <p className="mt-1 text-status-danger">
                                Taken down {formatDate(p.taken_down_at)}: {p.taken_down_reason}
                            </p>
                        )}
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {p.state === 'on_sale' && (
                                <a href={p.url} target="_blank" rel="noreferrer" className="text-brand-primary hover:underline">
                                    Public page
                                </a>
                            )}
                            {can.owner && (
                                <span className="ml-auto">
                                    {p.state === 'taken_down' ? (
                                        <ReasonAction label="Restore listing" route={route('admin.catalog.restore', p.id)} dataA={`admin:listing:${p.id}:restore`} detail="The public page comes back as it was. The author is told." />
                                    ) : (
                                        <ReasonAction
                                            label="Take down"
                                            danger
                                            route={route('admin.catalog.take-down', p.id)}
                                            dataA={`admin:listing:${p.id}:take-down`}
                                            detail="The public page goes offline at once. Buyers keep their licences and downloads. The author is emailed this reason and finds it in their inbox."
                                        />
                                    )}
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <Pager links={listings.links} />

            <Panel title="Author pages" detail="Public shelves (/store/authors/…) with at least one book on sale. A shelf shows only books that carry an author name." className="mt-5">
                {authors.length === 0 ? (
                    <Empty>No author pages yet.</Empty>
                ) : (
                    <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:listings:authors">
                        {authors.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-baseline gap-x-3 py-1.5">
                                <Link href={route('admin.accounts.show', a.id)} className="font-medium text-text-primary hover:underline">
                                    {a.name}
                                </Link>
                                <span className="text-text-secondary">{a.on_sale} on sale</span>
                                <a href={a.url} target="_blank" rel="noreferrer" className="ml-auto break-all text-brand-primary hover:underline">
                                    /store/authors/{a.handle}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </>
    );
}

function Select({ label, value, onChange, options }) {
    const id = `catalog-${label.toLowerCase()}`;

    return (
        <span className="flex items-center gap-1.5">
            <label htmlFor={id} className="text-[12px] text-text-secondary">
                {label}
            </label>
            <select id={id} value={value ?? ''} onChange={(event) => onChange(event.target.value)} className={`${inputClass} w-auto py-1.5`}>
                <option value="">Any</option>
                {options.map((o) => (
                    <option key={o} value={o}>
                        {o}
                    </option>
                ))}
            </select>
        </span>
    );
}

function clean(params) {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}
