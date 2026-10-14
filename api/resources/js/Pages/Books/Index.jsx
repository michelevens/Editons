import AppHeader from '@/Components/Studio/AppHeader';
import Icon from '@/Components/Studio/Icon';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The author's shelf.
 *
 * Filters by status rather than hiding archived books behind a soft delete:
 * an archived book is still the author's and still readable, which is what
 * restoring it from here depends on.
 */
export default function Index({ books, filter, counts, can_create: canCreate, plan }) {
    const { auth } = usePage().props;
    const [creating, setCreating] = useState(false);

    const filters = [
        { key: 'active', label: 'Active', count: counts.active },
        { key: 'published', label: 'Published', count: counts.published },
        { key: 'archived', label: 'Archived', count: counts.archived },
        { key: 'all', label: 'All', count: counts.all },
    ];

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="My Books" />

            <AppHeader
                active="books"
                user={auth?.user}
                onSelect={(item) => item.key === 'books' && router.get(route('books.index'))}
                onOpenPalette={() => {}}
                onOpenNotifications={() => {}}
            />

            <main className="min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[1040px] px-8 py-9">
                    <header className="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                                My Books
                            </h1>
                            <p className="mt-1.5 text-[14px] text-text-secondary">
                                {counts.active === 0
                                    ? 'Nothing on the shelf yet.'
                                    : `${counts.active} active${counts.archived ? `, ${counts.archived} archived` : ''}`}
                            </p>
                        </div>

                        <button
                            data-a="books:new"
                            onClick={() => setCreating((value) => !value)}
                            disabled={!canCreate && !creating}
                            title={
                                canCreate
                                    ? undefined
                                    : `The ${plan.name} plan allows ${plan.books} book${plan.books === 1 ? '' : 's'}.`
                            }
                            className="flex h-[40px] items-center gap-2 rounded-control bg-brand-primary px-4 text-[14px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {creating ? 'Cancel' : 'New book'}
                            {!creating && <Icon name="plus" size={15} strokeWidth={2.4} />}
                        </button>
                    </header>

                    {!canCreate && (
                        <p className="mt-3 rounded-control bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] text-status-warning">
                            The {plan.name} plan allows {plan.books} book
                            {plan.books === 1 ? '' : 's'}. Archive one, or move to a
                            larger plan.
                        </p>
                    )}

                    {creating && <NewBookForm onDone={() => setCreating(false)} />}

                    <nav className="mt-6 flex flex-wrap gap-1.5">
                        {filters.map((item) => (
                            <button
                                key={item.key}
                                data-a={`books:filter:${item.key}`}
                                onClick={() =>
                                    router.get(
                                        route('books.index'),
                                        { filter: item.key },
                                        { preserveScroll: true },
                                    )
                                }
                                className={`rounded-badge px-3 py-1.5 text-[12.5px] font-medium transition ${
                                    filter === item.key
                                        ? 'bg-brand-primary text-white'
                                        : 'bg-bg-surface text-text-secondary hover:text-text-primary'
                                }`}
                            >
                                {item.label}
                                <span className="ml-1.5 opacity-70">{item.count}</span>
                            </button>
                        ))}
                    </nav>

                    {books.length === 0 ? (
                        <EmptyShelf filter={filter} />
                    ) : (
                        <ul className="mt-5 grid gap-[14px] sm:grid-cols-2">
                            {books.map((book) => (
                                <BookCard key={book.id} book={book} />
                            ))}
                        </ul>
                    )}
                </div>
            </main>
        </div>
    );
}

function BookCard({ book }) {
    const archived = book.status === 'archived';
    const pct = book.target_words
        ? Math.min(100, Math.round((book.word_count / book.target_words) * 100))
        : null;

    return (
        <li
            className={`rounded-panel border border-border-default bg-bg-surface p-5 transition hover:border-brand-primary-border ${
                archived ? 'opacity-70' : ''
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <Link
                    href={route('books.overview', book.id)}
                    data-a={`books:open:${book.id}`}
                    className="min-w-0"
                >
                    <h2 className="truncate font-display text-[17px] font-bold tracking-[-0.02em] text-text-primary">
                        {book.title}
                    </h2>
                    {book.subtitle && (
                        <p className="mt-0.5 truncate text-[12.5px] text-text-secondary">
                            {book.subtitle}
                        </p>
                    )}
                </Link>

                <StatusChip status={book.status} />
            </div>

            <p className="mt-3 text-[12.5px] text-text-secondary">
                {book.word_count.toLocaleString()} words ·{' '}
                {book.sections_count} section{book.sections_count === 1 ? '' : 's'} ·{' '}
                {book.updated_at}
            </p>

            {pct !== null && (
                <div className="mt-3">
                    <div className="h-1.5 overflow-hidden rounded-full bg-bg-subtle">
                        <div
                            className="h-full rounded-full bg-brand-primary"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                    <p className="mt-1.5 text-[11px] text-text-secondary">
                        {pct}% of {book.target_words.toLocaleString()}
                    </p>
                </div>
            )}

            <div className="mt-4 flex items-center gap-4 border-t border-border-divider pt-3 text-[12.5px]">
                {archived ? (
                    <button
                        data-a={`books:restore:${book.id}`}
                        onClick={() =>
                            router.patch(
                                route('books.update', book.id),
                                { status: 'draft' },
                                { preserveScroll: true },
                            )
                        }
                        className="font-semibold text-brand-primary hover:text-brand-primary-hover"
                    >
                        Restore
                    </button>
                ) : (
                    <Link
                        href={route('books.edit', book.id)}
                        data-a={`books:write:${book.id}`}
                        className="font-semibold text-brand-primary hover:text-brand-primary-hover"
                    >
                        Open manuscript
                    </Link>
                )}

                <Link
                    href={route('books.settings', book.id)}
                    data-a={`books:settings:${book.id}`}
                    className="ml-auto text-text-secondary hover:text-text-primary"
                >
                    Settings
                </Link>
            </div>
        </li>
    );
}

function StatusChip({ status }) {
    const style = {
        draft: 'bg-bg-subtle text-text-secondary',
        in_review: 'bg-status-info-bg text-status-info',
        published: 'bg-status-success-bg text-status-success',
        archived: 'bg-status-warning-bg text-status-warning',
    }[status];

    return (
        <span
            className={`shrink-0 rounded-badge px-2.5 py-1 text-[10.5px] font-bold uppercase tracking-wide ${style}`}
        >
            {String(status).replace('_', ' ')}
        </span>
    );
}

function EmptyShelf({ filter }) {
    const message = {
        archived: 'Nothing archived.',
        published: 'Nothing published yet.',
        active: 'No active books. Start one above.',
        all: 'No books yet. Start one above.',
    }[filter];

    return (
        <p className="mt-5 rounded-panel border border-dashed border-border-default p-10 text-center text-[13.5px] text-text-secondary">
            {message}
        </p>
    );
}

function NewBookForm({ onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        subtitle: '',
        genre: 'Nonfiction',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('books.store'), {
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-5 grid gap-4 rounded-panel border border-border-default bg-bg-surface p-5 sm:grid-cols-2"
        >
            <label className="sm:col-span-2">
                <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                    Title
                </span>
                <input
                    value={data.title}
                    data-a="books:new:title"
                    required
                    autoFocus
                    placeholder="What is it called?"
                    onChange={(event) => setData('title', event.target.value)}
                    className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                />
                {errors.title && (
                    <span role="alert" className="mt-1 block text-[11.5px] text-status-danger">
                        {errors.title}
                    </span>
                )}
            </label>

            <label>
                <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                    Subtitle
                </span>
                <input
                    value={data.subtitle}
                    data-a="books:new:subtitle"
                    onChange={(event) => setData('subtitle', event.target.value)}
                    className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                />
            </label>

            <label>
                <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                    Genre
                </span>
                <select
                    value={data.genre}
                    data-a="books:new:genre"
                    onChange={(event) => setData('genre', event.target.value)}
                    className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                >
                    {['Nonfiction', 'Memoir', 'Textbook', 'Technical', 'Fiction'].map((genre) => (
                        <option key={genre}>{genre}</option>
                    ))}
                </select>
            </label>

            <div className="sm:col-span-2">
                <button
                    disabled={processing}
                    data-a="books:new:save"
                    className="h-[38px] rounded-control bg-text-primary px-4 text-[13.5px] font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
                >
                    {processing ? 'Creating…' : 'Create book'}
                </button>
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    A first chapter is created with it, so there is somewhere to
                    type straight away.
                </p>
            </div>
        </form>
    );
}
