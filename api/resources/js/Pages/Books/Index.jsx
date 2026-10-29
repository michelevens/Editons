import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import Icon from '@/Components/Studio/Icon';
import ImportDialog from '@/Components/Studio/ImportDialog';
import Shelf from '@/Components/Shelf/Shelf';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useI18n } from '@/i18n';

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
    default_book_type: defaultBookType = null,
    house = null,
    shelf,
}) {
    const { auth } = usePage().props;
    const { t, formatNumber } = useI18n();
    const [creating, setCreating] = useState(false);
    const [importing, setImporting] = useState(false);
    const totalWords = counts.words ?? books.reduce((sum, book) => sum + (book.word_count ?? 0), 0);

    // Order and labels follow the design: All first, then the three states.
    const filters = [
        { key: 'all', label: t('shelf.filter_all'), count: counts.all },
        { key: 'active', label: t('shelf.filter_active'), count: counts.active },
        { key: 'published', label: t('shelf.filter_published'), count: counts.published },
        { key: 'archived', label: t('shelf.filter_archived'), count: counts.archived },
        // Staff only: the platform's own imprints (ADR — house catalog).
        ...(house ? [{ key: 'house', label: 'House catalog', count: counts.house }] : []),
    ];

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('nav.books')} />

            <AppHeader
                active="books"
                user={auth?.user}
                account={auth?.account}
            />

            <main id="main" tabIndex={-1} className="min-w-0 flex-1 overflow-y-auto outline-none">
                <div className="mx-auto max-w-[1040px] px-4 py-6 sm:px-8 sm:py-9">
                    <header className="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                                {t('nav.books')}
                            </h1>
                            <p className="mt-1.5 text-[14px] text-text-secondary">
                                {counts.active === 0
                                    ? t('shelf.empty_title')
                                    : t('shelf.summary', { count: counts.active, words: formatNumber(totalWords) })}
                            </p>
                        </div>

                        <button
                            data-a="books:new"
                            onClick={() => setCreating((value) => !value)}
                            disabled={!canCreate && !creating}
                            title={
                                canCreate
                                    ? undefined
                                    : t('shelf.plan_limit', { plan: plan.name, count: plan.books })
                            }
                            className="flex h-[40px] items-center gap-2 rounded-control bg-brand-fill px-4 text-[14px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {creating ? t('common.cancel') : t('shelf.new_book')}
                            {!creating && <Icon name="plus" size={15} strokeWidth={2.4} />}
                        </button>
                    </header>

                    {/*
                     * The simplest door, for somebody who wants to type
                     * rather than answer questions about the book first.
                     * A quiet link rather than a second button: the full
                     * form is the right default for most people here.
                     */}
                    {canCreate && !creating && (
                        <div className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1">
                            <button
                                data-a="books:quick"
                                onClick={() => router.get(route('books.quick'))}
                                className="text-[12.5px] font-medium text-text-secondary transition hover:text-brand-primary"
                            >
                                {t('shelf.quick')}
                            </button>
                            <button
                                data-a="books:import"
                                onClick={() => setImporting(true)}
                                className="text-[12.5px] font-medium text-text-secondary transition hover:text-brand-primary"
                            >
                                {t('shelf.import')}
                            </button>
                        </div>
                    )}

                    {importing && (
                        <ImportDialog
                            title={t('shelf.import_title')}
                            hint={t('shelf.import_hint')}
                            url="/books/import"
                            accept=".md,.markdown,.txt,.docx,.ecrivain"
                            doneLabel={t('shelf.open_book')}
                            onClose={() => setImporting(false)}
                            onDone={(result) => router.visit(result.book.url)}
                        />
                    )}

                    {!canCreate && (
                        <p className="mt-3 rounded-control bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] text-status-warning">
                            {t('shelf.plan_limit_note', { plan: plan.name, count: plan.books })}
                        </p>
                    )}

                    {creating && (
                        <NewBookForm
                            bookTypes={bookTypes}
                            defaultBookType={defaultBookType}
                            onDone={() => setCreating(false)}
                        />
                    )}

                    {filter === 'house' && house && (
                        <p className="mt-4 rounded-control bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] text-status-warning" data-a="books:house-note">
                            House books belong to the platform&rsquo;s imprints. Anything you change is saved as you.{' '}
                            <a href={house.console} className="font-semibold underline">Open the House catalog</a>
                        </p>
                    )}

                    <Shelf
                        shelf={shelf}
                        books={books}
                        routeName="books.index"
                        filters={filters}
                        linkFor={(book) => route('books.overview', book.id)}
                        canSelect={books.length > 0}
                        empty={<EmptyShelf filter={filter} searching={shelf.state.q !== ''} />}
                        renderCard={(book, { selecting, selected, toggle }) => (
                            <BookCard book={book} selecting={selecting} selected={selected} onToggle={toggle} />
                        )}
                    />
                </div>
            </main>

            <MobileNav current="books" />
        </div>
    );
}

function BookCard({ book, selecting = false, selected = false, onToggle }) {
    const { t, formatNumber } = useI18n();
    const archived = book.status === 'archived';
    const pct = book.target_words
        ? Math.min(100, Math.round((book.word_count / book.target_words) * 100))
        : null;

    return (
        <li
            // Archived reads as set aside by its background and a greyed
            // cover, not by fading the text below a readable contrast.
            className={`flex gap-4 rounded-panel border border-border-default p-4 transition hover:border-brand-primary-border ${
                archived ? 'bg-bg-subtle [&_img]:grayscale' : 'bg-bg-surface'
            }`}
        >
            {selecting && (
                <input
                    type="checkbox"
                    aria-label={t('shelf.select_book', { title: book.title })}
                    data-a={`books:select:${book.id}`}
                    checked={selected}
                    onChange={onToggle}
                    className="mt-1 h-4 w-4 shrink-0 rounded border-border-input"
                />
            )}
            {/* The cover carries the card, as it does on a shelf. A list of
                titles with no artwork is a list of filenames. */}
            <Link
                href={route('books.overview', book.id)}
                data-a={`books:open:${book.id}`}
                aria-label={t('shelf.open_title', { title: book.title })}
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
                    {book.genre || t('book.unclassified')} · {t('book.words', { count: book.word_count, words: formatNumber(book.word_count) })}
                </p>

                <div className="mt-2 flex items-center gap-2">
                    <StatusChip status={book.status} />
                    <span className="text-[11px] text-text-secondary">{book.updated_at}</span>
                </div>

                {pct !== null && (
                    <div className="mt-2.5">
                        <div className="h-[6px] overflow-hidden rounded-full bg-bg-subtle">
                            <div
                                className="h-full rounded-full bg-brand-fill transition-[width]"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <p className="mt-1 text-[10.5px] text-text-secondary">
                            {t('shelf.progress', { pct, words: formatNumber(book.target_words) })}
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
                            className="rounded-control bg-brand-fill px-3.5 py-1.5 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                        >
                            {t('shelf.restore')}
                        </button>
                    ) : (
                        <>
                            <Link
                                href={route('books.edit', book.id)}
                                data-a={`books:write:${book.id}`}
                                className="rounded-control bg-brand-fill px-3.5 py-1.5 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                            >
                                {t('shelf.open')}
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
                                {t('shelf.archive')}
                            </button>
                        </>
                    )}

                    <Link
                        href={route('books.settings', book.id)}
                        data-a={`books:settings:${book.id}`}
                        className="ml-auto text-[12px] text-text-secondary hover:text-text-primary"
                    >
                        {t('shelf.settings')}
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
                src={cover.thumb ?? cover.url}
                alt=""
                width={width}
                height={height}
                loading="lazy"
                decoding="async"
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
    const { t } = useI18n();
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
            {STATUS_KEYS[status] ? t(STATUS_KEYS[status].tKey) : String(status).replace('_', ' ')}
        </span>
    );
}

// Book::STATUSES, as the shelf's chips say them (upper-cased by CSS).
const STATUS_KEYS = {
    draft: { tKey: 'book.status_draft' },
    in_review: { tKey: 'book.status_in_review' },
    published: { tKey: 'book.status_published' },
    archived: { tKey: 'book.status_archived' },
};

function EmptyShelf({ filter, searching = false }) {
    const { t } = useI18n();
    const message = searching ? t('shelf.empty_search') : {
        archived: t('shelf.empty_archived'),
        published: t('shelf.empty_published'),
        active: t('shelf.empty_active'),
        all: t('shelf.empty_all'),
        house: 'No house books yet.',
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
function NewBookForm({ onDone, bookTypes, defaultBookType }) {
    const { t, formatNumber } = useI18n();
    // An author who writes one kind of book has said so in their profile.
    // Opening on the scale step skips a question they have already answered,
    // and "Change" is right there if this book is different.
    const preset = bookTypes.find((type) => type.key === defaultBookType);

    const [step, setStep] = useState(preset ? 'scale' : 'what');

    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        subtitle: '',
        genre: preset?.name ?? '',
        book_type: preset?.key ?? '',
        chapters: preset?.suggested_chapters ?? '',
        target_words: preset?.suggested_words ?? '',
        assistance: 'author_led',
        started_with: preset ? 'template' : 'blank',
        premise: '',
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
                    {t('newbook.title')}
                </span>
                <input
                    value={data.title}
                    data-a="books:new:title"
                    required
                    autoFocus
                    placeholder={t('newbook.title_placeholder')}
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
                        {t('newbook.kind')}
                    </p>
                    <p className="mb-3 text-[11.5px] text-text-secondary">
                        {t('newbook.kind_hint')}
                    </p>

                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
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
                                    {t('newbook.typical', {
                                        from: formatNumber(type.words[0]),
                                        to: formatNumber(type.words[1]),
                                        min: type.chapters[0],
                                        max: type.chapters[1],
                                    })}
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
                            {t('newbook.skip')}
                        </button>
                        <span className="text-[11.5px] text-text-secondary">
                            {t('newbook.skip_hint')}
                        </span>

                        <button
                            type="button"
                            data-a="books:new:cancel"
                            onClick={onDone}
                            className="ml-auto text-[12.5px] text-text-secondary hover:text-text-primary"
                        >
                            {t('common.cancel')}
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
                            {t('shortcuts.change')}
                        </button>
                    </div>

                    <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label>
                            <span className="mb-1.5 block text-[12px] font-medium text-text-secondary">
                                {t('newbook.chapters')}
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
                                {t('newbook.chapters_hint', { min: chosen?.chapters[0], max: chosen?.chapters[1] })}
                            </span>
                        </label>

                        <label>
                            <span className="mb-1.5 block text-[12px] font-medium text-text-secondary">
                                {t('newbook.words')}
                            </span>
                            <input
                                type="number"
                                min="100"
                                /*
                                 * step="any", not 1000.
                                 *
                                 * With min="100" a step of 1000 makes the
                                 * valid values 100, 1100, 2100… and every
                                 * one of the fifteen genres suggests a round
                                 * number — 38,000 for a cookbook, 65,000 for
                                 * nonfiction. None of them are on that
                                 * ladder, so checkValidity() was false and
                                 * the browser blocked submit silently: the
                                 * "Create the book" button did nothing, with
                                 * no message anywhere.
                                 *
                                 * Invisible to the Pest tests, which POST
                                 * directly and never run HTML5 validation.
                                 */
                                step="any"
                                value={data.target_words}
                                data-a="books:new:words"
                                onChange={(event) => setData('target_words', event.target.value)}
                                className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                            />
                            <span className="mt-1 block text-[10.5px] text-text-secondary">
                                {t('newbook.words_hint', { from: formatNumber(chosen?.words[0]), to: formatNumber(chosen?.words[1]) })}
                            </span>
                        </label>
                    </div>

                    {/*
                     * The one Discover question, in this genre's own words.
                     *
                     * Asked here because the moment somebody has just chosen
                     * "Cookbook" is the moment "What ties these recipes
                     * together?" is easiest to answer. Optional — it is also
                     * the moment somebody wants to get on with it, and the
                     * same question waits in the guided view.
                     */}
                    <label className="mt-5 block">
                        <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                            {chosen?.questions?.premise ?? t('newbook.premise')}
                        </span>
                        <textarea
                            rows={2}
                            value={data.premise}
                            data-a="books:new:premise"
                            placeholder={t('newbook.premise_placeholder')}
                            onChange={(event) => setData('premise', event.target.value)}
                            className="w-full resize-y rounded-control border border-border-input px-3 py-2 text-[13.5px] leading-relaxed focus:border-brand-primary focus:ring-0"
                        />
                        <span className="mt-1 block text-[10.5px] text-text-secondary">
                            {t('newbook.premise_hint')}
                        </span>
                    </label>

                    <p className="mt-5 mb-2 text-[12.5px] font-semibold text-text-primary">
                        {t('newbook.help')}
                    </p>

                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
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
                                    {t(level.tKey)}
                                </span>
                                <span className="mt-0.5 block text-[11px] leading-snug text-text-secondary">
                                    {t(level.blurbKey)}
                                </span>
                            </button>
                        ))}
                    </div>

                    <p className="mt-2 text-[11px] leading-relaxed text-text-secondary">
                        {t('newbook.help_note')}
                    </p>

                    <div className="mt-4 flex items-center gap-3 border-t border-border-divider pt-3">
                        <button
                            type="submit"
                            data-a="books:new:create"
                            disabled={processing || !data.title.trim()}
                            className="h-[38px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            {processing ? t('newbook.creating') : t('newbook.create')}
                        </button>

                        <span className="text-[11.5px] text-text-secondary">
                            {t('newbook.empty_chapters', { count: Number(data.chapters || chosen?.suggested_chapters) || 0 })}
                        </span>

                        <button
                            type="button"
                            data-a="books:new:cancel"
                            onClick={onDone}
                            className="ml-auto text-[12.5px] text-text-secondary hover:text-text-primary"
                        >
                            {t('common.cancel')}
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
        tKey: 'assist.author_led',
        blurb: 'You write. The editors run when you ask them to.',
        blurbKey: 'assist.author_led_blurb',
    },
    {
        key: 'coach',
        name: 'Coach me',
        tKey: 'assist.coach',
        blurb: 'Questions, structure and suggestions as you go — but the words are yours.',
        blurbKey: 'assist.coach_blurb',
    },
    {
        key: 'collaborative',
        name: 'Draft with me',
        tKey: 'assist.collaborative',
        blurb: 'Drafts passages from your notes and outline, for you to accept or reject.',
        blurbKey: 'assist.collaborative_blurb',
    },
];
