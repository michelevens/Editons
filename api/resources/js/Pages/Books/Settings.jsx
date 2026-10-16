import AppHeader from '@/Components/Studio/AppHeader';
import BookSidebar from '@/Components/Studio/BookSidebar';
import Icon from '@/Components/Studio/Icon';
import { goTo } from '@/Components/Studio/navigation';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Book Settings: the book's identity, the autosave preference, and archive.
 *
 * Archive is a status rather than a delete, so an archived book is still the
 * author's and still readable — which is what the shelf's Archived filter
 * restores from.
 */
export default function Settings({ book, genres, languages, can_archive: canArchive }) {
    const { auth } = usePage().props;

    const open = (key) => goTo(book.id, key);

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={`${book.title} — Settings`} />

            <AppHeader
                active="writing"
                user={auth?.user}
                account={auth?.account}
                bookId={book.id}
            />

            <div className="flex min-h-0 flex-1">
                <BookSidebar
                    book={book}
                    active="settings"
                    onSelect={(item) => {
                        if (item.key === 'settings') return;
                        if (item.key === 'overview') {
                            router.get(route('books.overview', book.id));

                            return;
                        }

                        open(item.key);
                    }}
                />

                <main className="min-w-0 flex-1 overflow-y-auto">
                    <div className="mx-auto max-w-[760px] px-8 py-9">
                        <header className="mb-7">
                            <h1 className="font-display text-[28px] font-extrabold tracking-[-0.03em] text-text-primary">
                                Book Settings
                            </h1>
                            <p className="mt-1.5 text-[14px] text-text-secondary">
                                {book.title}
                            </p>
                        </header>

                        <DetailsForm book={book} genres={genres} languages={languages} />
                        <WritingPreferences book={book} />
                        <ArchiveBox book={book} canArchive={canArchive} />
                    </div>
                </main>
            </div>
        </div>
    );
}

function DetailsForm({ book, genres, languages }) {
    const [saved, setSaved] = useState(false);

    const { data, setData, patch, processing, errors } = useForm({
        title: book.title ?? '',
        subtitle: book.subtitle ?? '',
        author_name: book.author_name ?? '',
        genre: book.genre ?? genres[0],
        language: book.language ?? languages[0],
        target_words: book.target_words ?? '',
        description: book.description ?? '',
    });

    const submit = (event) => {
        event.preventDefault();

        patch(route('books.update', book.id), {
            preserveScroll: true,
            onSuccess: () => {
                setSaved(true);
                window.setTimeout(() => setSaved(false), 2600);
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            className="rounded-panel border border-border-default bg-bg-surface p-6"
        >
            <h2 className="text-[15px] font-semibold text-text-primary">Details</h2>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field
                    id="title"
                    label="Title"
                    value={data.title}
                    error={errors.title}
                    required
                    className="sm:col-span-2"
                    onChange={(value) => setData('title', value)}
                />
                <Field
                    id="subtitle"
                    label="Subtitle"
                    value={data.subtitle}
                    error={errors.subtitle}
                    className="sm:col-span-2"
                    onChange={(value) => setData('subtitle', value)}
                />
                <Field
                    id="author_name"
                    label="Author name"
                    value={data.author_name}
                    error={errors.author_name}
                    hint="As it should appear on the cover."
                    onChange={(value) => setData('author_name', value)}
                />
                <Select
                    id="genre"
                    label="Genre"
                    value={data.genre}
                    options={genres}
                    onChange={(value) => setData('genre', value)}
                />
                <Select
                    id="language"
                    label="Language"
                    value={data.language}
                    options={languages}
                    onChange={(value) => setData('language', value)}
                />
                <Field
                    id="target_words"
                    label="Word target"
                    type="number"
                    value={data.target_words}
                    error={errors.target_words}
                    placeholder="e.g. 80000"
                    onChange={(value) => setData('target_words', value)}
                />
            </div>

            <div className="mt-5 flex items-center justify-end gap-3">
                {saved && (
                    <span className="text-[12.5px] font-medium text-status-success">
                        Saved
                    </span>
                )}
                <button
                    disabled={processing}
                    data-a="settings:save"
                    className="h-[38px] rounded-control bg-brand-primary px-4 text-[13.5px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    {processing ? 'Saving…' : 'Save changes'}
                </button>
            </div>
        </form>
    );
}

/**
 * The writing preference: autosave, which the editor honours.
 *
 * Each saves on change rather than waiting for a Save button: a switch that
 * needs confirming reads as broken, and these are preferences, not a form.
 */
function WritingPreferences({ book }) {
    const [settings, setSettings] = useState(book.settings);
    const [busy, setBusy] = useState(null);

    const toggle = (key) => {
        const next = { ...settings, [key]: !settings[key] };

        setSettings(next);
        setBusy(key);

        router.patch(
            route('books.update', book.id),
            { settings: next },
            {
                preserveScroll: true,
                preserveState: true,
                // Put it back if the server refused, rather than leaving the
                // switch showing a state that was never saved.
                onError: () => setSettings(settings),
                onFinish: () => setBusy(null),
            },
        );
    };

    const rows = [
        {
            key: 'autosave',
            title: 'Autosave',
            detail: 'Save changes as you type. Turn off to save manually with Ctrl/Cmd+S.',
        },
        // Inline AI suggestions and Review on save were listed here, saved,
        // and read by nothing. They return when the editor does them.
    ];

    return (
        <section className="mt-5 rounded-panel border border-border-default bg-bg-surface p-6">
            <h2 className="text-[15px] font-semibold text-text-primary">Writing</h2>

            <ul className="mt-2 divide-y divide-border-divider">
                {rows.map((row) => (
                    <li
                        key={row.key}
                        className="flex items-center justify-between gap-6 py-3.5"
                    >
                        <div className="min-w-0">
                            <p className="text-[13.5px] font-medium text-text-primary">
                                {row.title}
                            </p>
                            <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">
                                {row.detail}
                            </p>
                        </div>

                        <button
                            type="button"
                            role="switch"
                            aria-checked={settings[row.key]}
                            aria-label={row.title}
                            data-a={`settings:toggle:${row.key}`}
                            disabled={busy === row.key}
                            onClick={() => toggle(row.key)}
                            className={`relative h-[24px] w-[42px] shrink-0 rounded-full transition disabled:opacity-60 ${
                                settings[row.key] ? 'bg-brand-primary' : 'bg-border-input'
                            }`}
                        >
                            <span
                                className={`absolute top-[3px] h-[18px] w-[18px] rounded-full bg-white transition-all ${
                                    settings[row.key] ? 'left-[21px]' : 'left-[3px]'
                                }`}
                            />
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function ArchiveBox({ book, canArchive }) {
    const [confirming, setConfirming] = useState(false);
    const archived = book.status === 'archived';

    const setStatus = (status) =>
        router.patch(route('books.update', book.id), { status }, { preserveScroll: true });

    return (
        <section className="mt-5 rounded-panel border border-status-danger/25 bg-status-danger-bg/40 p-6">
            <h2 className="text-[15px] font-semibold text-text-primary">
                {archived ? 'Restore this book' : 'Archive this book'}
            </h2>
            <p className="mt-1.5 text-[13px] leading-relaxed text-text-secondary">
                {archived
                    ? 'This book is archived and hidden from your active shelf. Nothing has been deleted.'
                    : 'Archived books leave your active shelf. Nothing is deleted, and you can restore it from My Books at any time.'}
            </p>

            {archived ? (
                <button
                    data-a="settings:restore"
                    onClick={() => setStatus('draft')}
                    className="mt-4 h-[36px] rounded-control bg-text-primary px-4 text-[13px] font-semibold text-white transition hover:opacity-90"
                >
                    Restore to drafts
                </button>
            ) : !canArchive ? (
                <p className="mt-4 flex items-center gap-2 text-[12.5px] text-text-secondary">
                    <Icon name="info" size={15} />
                    This is your only active book. Create another before
                    archiving it.
                </p>
            ) : confirming ? (
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <span className="text-[13px] font-medium text-status-danger">
                        Archive “{book.title}”?
                    </span>
                    <button
                        data-a="settings:archive:cancel"
                        onClick={() => setConfirming(false)}
                        className="h-[34px] rounded-control border border-border-input bg-bg-surface px-3.5 text-[13px] font-medium text-text-body"
                    >
                        Cancel
                    </button>
                    <button
                        data-a="settings:archive:confirm"
                        onClick={() => setStatus('archived')}
                        className="h-[34px] rounded-control bg-status-danger px-3.5 text-[13px] font-semibold text-white transition hover:opacity-90"
                    >
                        Archive book
                    </button>
                </div>
            ) : (
                <button
                    data-a="settings:archive"
                    onClick={() => setConfirming(true)}
                    className="mt-4 h-[36px] rounded-control border border-status-danger/40 bg-bg-surface px-4 text-[13px] font-semibold text-status-danger transition hover:bg-status-danger-bg"
                >
                    Archive this book
                </button>
            )}
        </section>
    );
}

function Field({ id, label, type = 'text', value, error, hint, className = '', onChange, ...rest }) {
    return (
        <label className={className}>
            <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                {label}
            </span>
            <input
                id={id}
                type={type}
                value={value}
                data-a={`settings:field:${id}`}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? 'true' : undefined}
                className={`h-[38px] w-full rounded-control border px-3 text-[13.5px] focus:ring-0 ${
                    error
                        ? 'border-status-danger focus:border-status-danger'
                        : 'border-border-input focus:border-brand-primary'
                }`}
                {...rest}
            />
            {hint && !error && (
                <span className="mt-1 block text-[11.5px] text-text-secondary">{hint}</span>
            )}
            {error && (
                <span role="alert" className="mt-1 block text-[11.5px] font-medium text-status-danger">
                    {error}
                </span>
            )}
        </label>
    );
}

function Select({ id, label, value, options, onChange }) {
    return (
        <label>
            <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                {label}
            </span>
            <select
                id={id}
                value={value}
                data-a={`settings:field:${id}`}
                onChange={(event) => onChange(event.target.value)}
                className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
            >
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </label>
    );
}
