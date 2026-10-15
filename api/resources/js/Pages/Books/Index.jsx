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
export default function Index({
    books,
    filter,
    counts,
    can_create: canCreate,
    plan,
    book_types: bookTypes = [],
}) {
    const { auth } = usePage().props;
    const [creating, setCreating] = useState(false);

    const totalWords = books.reduce((sum, book) => sum + (book.word_count ?? 0), 0);

    // Order and labels follow the design: All first, then the three states.
    const filters = [
        { key: 'all', label: 'All', count: counts.all },
        { key: 'active', label: 'Drafts', count: counts.active },
        { key: 'published', label: 'Published', count: counts.published },
        { key: 'archived', label: 'Archived', count: counts.archived },
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
                                    : `${counts.active} active · ${totalWords.toLocaleString()} words written`}
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

                    {creating && (
                        <NewBookForm
                            bookTypes={bookTypes}
                            onDone={() => setCreating(false)}
                        />
                    )}

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
            className={`flex gap-4 rounded-panel border border-border-default bg-bg-surface p-4 transition hover:border-brand-primary-border ${
                archived ? 'opacity-70' : ''
            }`}
        >
            {/* The cover carries the card, as it does on a shelf. A list of
                titles with no artwork is a list of filenames. */}
            <Link
                href={route('books.overview', book.id)}
                data-a={`books:open:${book.id}`}
                className="shrink-0"
            >
                <ShelfCover cover={book.cover} title={book.title} />
            </Link>

            <div className="flex min-w-0 flex-1 flex-col">
                <Link
                    href={route('books.overview', book.id)}
                    className="min-w-0"
                >
                    <h2 className="font-display text-[17px] font-bold leading-[1.2] tracking-[-0.02em] text-text-primary">
                        {book.title}
                    </h2>
                </Link>

                <p className="mt-1 text-[12.5px] text-text-secondary">
                    {book.genre || 'Unclassified'} · {book.word_count.toLocaleString()} words
                </p>

                <div className="mt-2 flex items-center gap-2">
                    <StatusChip status={book.status} />
                    <span className="text-[11px] text-text-secondary">{book.updated_at}</span>
                </div>

                {pct !== null && (
                    <div className="mt-2.5">
                        <div className="h-[6px] overflow-hidden rounded-full bg-bg-subtle">
                            <div
                                className="h-full rounded-full bg-brand-primary transition-[width]"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <p className="mt-1 text-[10.5px] text-text-secondary">
                            {pct}% of {book.target_words.toLocaleString()} words
                        </p>
                    </div>
                )}

                <div className="mt-auto flex items-center gap-2 pt-3">
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
                            className="rounded-control bg-brand-primary px-3.5 py-1.5 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                        >
                            Restore
                        </button>
                    ) : (
                        <>
                            <Link
                                href={route('books.edit', book.id)}
                                data-a={`books:write:${book.id}`}
                                className="rounded-control bg-brand-primary px-3.5 py-1.5 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                            >
                                Open
                            </Link>

                            <button
                                data-a={`books:archive:${book.id}`}
                                onClick={() =>
                                    router.patch(
                                        route('books.update', book.id),
                                        { status: 'archived' },
                                        { preserveScroll: true },
                                    )
                                }
                                className="rounded-control border border-border-input px-3.5 py-1.5 text-[12.5px] font-medium text-text-body transition hover:border-border-input/80"
                            >
                                Archive
                            </button>
                        </>
                    )}

                    <Link
                        href={route('books.settings', book.id)}
                        data-a={`books:settings:${book.id}`}
                        className="ml-auto text-[12px] text-text-secondary hover:text-text-primary"
                    >
                        Settings
                    </Link>
                </div>
            </div>
        </li>
    );
}

/**
 * A book's cover on the shelf.
 *
 * Drawn from the palette the author picked when there is no uploaded
 * artwork, so every book has one. Small enough that only the title fits,
 * which is what a spine-sized thumbnail is for.
 */
function ShelfCover({ cover, title }) {
    // The proportions stores ask for (1600x2560), at the size the design
    // draws it on the shelf.
    const width = 104;
    const height = Math.round(width * (2560 / 1600));

    if (cover?.url) {
        return (
            <img
                src={cover.url}
                alt=""
                style={{ width, height }}
                className="rounded-[3px] object-cover shadow-[0_2px_10px_rgba(15,34,61,.18)]"
            />
        );
    }

    if (!cover) return null;

    return (
        <div
            data-a="shelf:cover"
            style={{
                width,
                height,
                background: `linear-gradient(170deg, ${cover.from}, ${cover.to})`,
                color: cover.ink,
            }}
            className="flex flex-col justify-between overflow-hidden rounded-[3px] p-2.5 shadow-[0_2px_10px_rgba(15,34,61,.18)]"
        >
            <span className="line-clamp-4 text-[10px] font-bold uppercase leading-[1.2] tracking-tight">
                {cover.title || title}
            </span>
            {cover.author && (
                <span className="truncate text-[7.5px] font-semibold uppercase tracking-[0.08em] opacity-90">
                    {cover.author}
                </span>
            )}
        </div>
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

/**
 * Starting a book.
 *
 * Two steps, not a wizard. The first asks what the book is; the second asks
 * how big and how much help. Both have a way past them, because an author who
 * knows what they want should not be walked through questions to reach an
 * empty page — "Skip, just make the book" is always one click away.
 *
 * The lengths shown are published trade norms, labelled as typical and
 * editable. They are a starting point to argue with, not a prediction.
 */
function NewBookForm({ onDone, bookTypes }) {
    const [step, setStep] = useState('what');

    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        subtitle: '',
        genre: '',
        book_type: '',
        chapters: '',
        target_words: '',
        assistance: 'author_led',
        started_with: 'blank',
    });

    const chosen = bookTypes.find((type) => type.key === data.book_type);

    const choose = (type) => {
        // Picking a type fills the scale fields with its typical values, so
        // the second step opens with sensible numbers rather than empty boxes.
        setData((current) => ({
            ...current,
            book_type: type.key,
            genre: type.name,
            chapters: type.suggested_chapters,
            target_words: type.suggested_words,
            started_with: 'template',
        }));

        setStep('scale');
    };

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
            data-a="books:new:form"
            className="mt-5 rounded-panel border border-border-default bg-bg-surface p-5"
        >
            <label className="block">
                <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                    What is the book called?
                </span>
                <input
                    value={data.title}
                    data-a="books:new:title"
                    required
                    autoFocus
                    placeholder="A working title is fine — you can change it later"
                    onChange={(event) => setData('title', event.target.value)}
                    className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                />
                {errors.title && (
                    <span role="alert" className="mt-1 block text-[11.5px] text-status-danger">
                        {errors.title}
                    </span>
                )}
            </label>

            {step === 'what' ? (
                <>
                    <p className="mt-5 mb-2 text-[12.5px] font-semibold text-text-primary">
                        What kind of book is it?
                    </p>
                    <p className="mb-3 text-[11.5px] text-text-secondary">
                        This sets up the structure and decides which editors matter most.
                        You can change it at any point.
                    </p>

                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {bookTypes.map((type) => (
                            <button
                                key={type.key}
                                type="button"
                                data-a={`books:new:type:${type.key}`}
                                onClick={() => choose(type)}
                                className="rounded-tile border border-border-default p-3 text-left transition hover:border-brand-primary hover:bg-brand-primary/[0.03]"
                            >
                                <span className="block text-[12.5px] font-semibold text-text-primary">
                                    {type.name}
                                </span>
                                <span className="mt-0.5 block text-[11px] leading-snug text-text-secondary">
                                    {type.blurb}
                                </span>
                                <span className="mt-1.5 block text-[10.5px] text-text-secondary">
                                    Typically {type.words[0].toLocaleString()}–
                                    {type.words[1].toLocaleString()} words,{' '}
                                    {type.chapters[0]}–{type.chapters[1]} chapters
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="mt-4 flex items-center gap-3 border-t border-border-divider pt-3">
                        <button
                            type="submit"
                            data-a="books:new:skip"
                            disabled={processing || !data.title.trim()}
                            className="text-[12.5px] font-semibold text-brand-primary hover:underline disabled:opacity-50"
                        >
                            Skip — just make the book
                        </button>
                        <span className="text-[11.5px] text-text-secondary">
                            One empty chapter, nothing else.
                        </span>

                        <button
                            type="button"
                            data-a="books:new:cancel"
                            onClick={onDone}
                            className="ml-auto text-[12.5px] text-text-secondary hover:text-text-primary"
                        >
                            Cancel
                        </button>
                    </div>
                </>
            ) : (
                <>
                    <div className="mt-5 flex items-baseline gap-2">
                        <p className="text-[12.5px] font-semibold text-text-primary">
                            {chosen?.name}
                        </p>
                        <button
                            type="button"
                            data-a="books:new:back"
                            onClick={() => setStep('what')}
                            className="text-[11.5px] text-brand-primary hover:underline"
                        >
                            Change
                        </button>
                    </div>

                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        <label>
                            <span className="mb-1.5 block text-[12px] font-medium text-text-secondary">
                                How many chapters?
                            </span>
                            <input
                                type="number"
                                min="1"
                                max="60"
                                value={data.chapters}
                                data-a="books:new:chapters"
                                onChange={(event) => setData('chapters', event.target.value)}
                                className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                            />
                            <span className="mt-1 block text-[10.5px] text-text-secondary">
                                {chosen?.chapters[0]}–{chosen?.chapters[1]} is typical. Add
                                or remove them whenever you like.
                            </span>
                        </label>

                        <label>
                            <span className="mb-1.5 block text-[12px] font-medium text-text-secondary">
                                Roughly how many words?
                            </span>
                            <input
                                type="number"
                                min="100"
                                step="1000"
                                value={data.target_words}
                                data-a="books:new:words"
                                onChange={(event) => setData('target_words', event.target.value)}
                                className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                            />
                            <span className="mt-1 block text-[10.5px] text-text-secondary">
                                {chosen?.words[0].toLocaleString()}–
                                {chosen?.words[1].toLocaleString()} is typical for this kind
                                of book. It is a target, not a limit.
                            </span>
                        </label>
                    </div>

                    <p className="mt-5 mb-2 text-[12.5px] font-semibold text-text-primary">
                        How much help do you want?
                    </p>

                    <div className="grid gap-2 sm:grid-cols-3">
                        {ASSISTANCE.map((level) => (
                            <button
                                key={level.key}
                                type="button"
                                data-a={`books:new:assistance:${level.key}`}
                                aria-pressed={data.assistance === level.key}
                                onClick={() => setData('assistance', level.key)}
                                className={`rounded-tile border p-3 text-left transition ${
                                    data.assistance === level.key
                                        ? 'border-brand-primary bg-brand-primary/[0.04]'
                                        : 'border-border-default hover:border-border-input'
                                }`}
                            >
                                <span className="block text-[12.5px] font-semibold text-text-primary">
                                    {level.name}
                                </span>
                                <span className="mt-0.5 block text-[11px] leading-snug text-text-secondary">
                                    {level.blurb}
                                </span>
                            </button>
                        ))}
                    </div>

                    <p className="mt-2 text-[11px] leading-relaxed text-text-secondary">
                        Whatever you pick, nothing is written into your manuscript without
                        you accepting it, and every AI edit is marked as one in your
                        version history. You can change this at any time.
                    </p>

                    <div className="mt-4 flex items-center gap-3 border-t border-border-divider pt-3">
                        <button
                            type="submit"
                            data-a="books:new:create"
                            disabled={processing || !data.title.trim()}
                            className="h-[38px] rounded-control bg-brand-primary px-4 text-[13px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            {processing ? 'Setting it up…' : 'Create the book'}
                        </button>

                        <span className="text-[11.5px] text-text-secondary">
                            {data.chapters || chosen?.suggested_chapters} empty chapters,
                            ready to write into.
                        </span>

                        <button
                            type="button"
                            data-a="books:new:cancel"
                            onClick={onDone}
                            className="ml-auto text-[12.5px] text-text-secondary hover:text-text-primary"
                        >
                            Cancel
                        </button>
                    </div>
                </>
            )}
        </form>
    );
}

/**
 * How much the AI does.
 *
 * Author-led first and selected by default: the level somebody has not
 * chosen should be the one that does least on their behalf.
 */
const ASSISTANCE = [
    {
        key: 'author_led',
        name: 'I write',
        blurb: 'You write. The editors run when you ask them to.',
    },
    {
        key: 'coach',
        name: 'Coach me',
        blurb: 'Questions, structure and suggestions as you go — but the words are yours.',
    },
    {
        key: 'collaborative',
        name: 'Draft with me',
        blurb: 'Drafts passages from your notes and outline, for you to accept or reject.',
    },
];
