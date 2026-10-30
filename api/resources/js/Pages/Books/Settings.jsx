import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import BookPartsPanel from '@/Components/Studio/BookPartsPanel';
import Icon from '@/Components/Studio/Icon';
import { goTo } from '@/Components/Studio/navigation';
import StudioSidebar from '@/Components/Studio/StudioSidebar';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import LanguageTypography, { LanguageField } from '@/Components/Studio/LanguageTypography';
import { useI18n } from '@/i18n';

/**
 * Book Settings: the book's identity, the autosave preference, and archive.
 *
 * Archive is a status rather than a delete, so an archived book is still the
 * author's and still readable — which is what the shelf's Archived filter
 * restores from.
 */
export default function Settings({
    book,
    genres,
    languages,
    citation_styles: citationStyles,
    can_archive: canArchive,
    anatomy,
    copyright_preview: copyrightPreview,
    typography,
    interior,
}) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    const open = (key) => goTo(book.id, key);
    const [menu, setMenu] = useState(false);
    const closeMenu = useCallback(() => setMenu(false), []);

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('settings.head', { title: book.title })} />

            <AppHeader
                active="writing"
                user={auth?.user}
                account={auth?.account}
                bookId={book.id}
            />

            <div className="flex min-h-0 flex-1">
                <StudioSidebar
                    open={menu}
                    onClose={closeMenu}
                    activeNav="writing"
                    onSelectNav={(item) => open(item.key)}
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

                <main id="main" tabIndex={-1} className="min-w-0 flex-1 overflow-y-auto outline-none">
                    <div className="mx-auto max-w-[760px] px-4 py-6 sm:px-8 sm:py-9">
                        <header className="mb-7">
                            <h1 className="font-display text-[28px] font-extrabold tracking-[-0.03em] text-text-primary">
                                {t('settings.title')}
                            </h1>
                            <p className="mt-1.5 text-[14px] text-text-secondary">
                                {book.title}
                            </p>
                        </header>

                        <DetailsForm book={book} genres={genres} languages={languages} />
                        <PublicationForm book={book} copyrightPreview={copyrightPreview} />
                        <BookPartsPanel bookId={book.id} initial={anatomy} />
                        <WritingPreferences book={book} citationStyles={citationStyles} />
                        <LanguageTypography book={book} typography={typography} interior={interior} />
                        <ArchiveBox book={book} canArchive={canArchive} />
                    </div>
                </main>
            </div>

            <MobileNav current="settings" bookId={book.id} bookTitle={book.title} />
        </div>
    );
}

function DetailsForm({ book, genres, languages }) {
    const { t } = useI18n();
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
            <h2 className="text-[15px] font-semibold text-text-primary">{t('settings.details')}</h2>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                    id="title"
                    label={t('settings.field_title')}
                    value={data.title}
                    error={errors.title}
                    required
                    className="sm:col-span-2"
                    onChange={(value) => setData('title', value)}
                />
                <Field
                    id="subtitle"
                    label={t('settings.subtitle')}
                    value={data.subtitle}
                    error={errors.subtitle}
                    className="sm:col-span-2"
                    onChange={(value) => setData('subtitle', value)}
                />
                <Field
                    id="author_name"
                    label={t('profile.author_name')}
                    value={data.author_name}
                    error={errors.author_name}
                    hint={t('settings.author_hint')}
                    onChange={(value) => setData('author_name', value)}
                />
                <Select
                    id="genre"
                    label={t('settings.genre')}
                    value={data.genre}
                    options={genres}
                    onChange={(value) => setData('genre', value)}
                />
                <LanguageField
                    value={data.language}
                    languages={languages}
                    error={errors.language}
                    onChange={(value) => setData('language', value)}
                />
                <Field
                    id="target_words"
                    label={t('settings.word_target')}
                    type="number"
                    value={data.target_words}
                    error={errors.target_words}
                    placeholder={t('settings.word_target_placeholder')}
                    onChange={(value) => setData('target_words', value)}
                />
            </div>

            <div className="mt-5 flex items-center justify-end gap-3">
                {saved && (
                    <span className="text-[12.5px] font-medium text-status-success">
                        {t('editor.save_saved')}
                    </span>
                )}
                <button
                    disabled={processing}
                    data-a="settings:save"
                    className="h-[38px] rounded-control bg-brand-fill px-4 text-[13.5px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    {processing ? t('editor.save_saving') : t('profile.save_changes')}
                </button>
            </div>
        </form>
    );
}

/**
 * Publishing details: what the generated pages print — the title page, the
 * copyright page, the series page.
 *
 * Every field is optional and an empty one prints nothing; the preview
 * below shows exactly what the copyright page will say. ISBNs are checked
 * against their checksum and never generated.
 */
function PublicationForm({ book, copyrightPreview }) {
    const { t } = useI18n();
    const [saved, setSaved] = useState(false);
    const publication = book.publication ?? {};

    const { data, setData, patch, processing, errors } = useForm({
        series_name: book.series_name ?? '',
        series_number: book.series_number ?? '',
        publication: {
            publisher: publication.publisher ?? '',
            imprint: publication.imprint ?? '',
            place: publication.place ?? '',
            edition_statement: publication.edition_statement ?? '',
            copyright_holder: publication.copyright_holder ?? '',
            copyright_year: publication.copyright_year ?? '',
            rights_statement: publication.rights_statement ?? '',
            isbn_print: publication.isbn_print ?? '',
            isbn_epub: publication.isbn_epub ?? '',
            isbn_pdf: publication.isbn_pdf ?? '',
            legal_deposit: publication.legal_deposit ?? '',
            country: publication.country ?? '',
            printing: publication.printing ?? '',
            disclaimer: publication.disclaimer ?? '',
            credits: publication.credits?.length ? publication.credits : [{ role: '', name: '' }],
        },
    });

    const set = (key, value) => setData('publication', { ...data.publication, [key]: value });
    const error = (key) => errors[`publication.${key}`];

    const setCredit = (index, key, value) =>
        set(
            'credits',
            data.publication.credits.map((credit, i) =>
                i === index ? { ...credit, [key]: value } : credit,
            ),
        );

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

    // Who the copyright line names when no holder is given.
    const defaultHolder = book.author_name || data.publication.publisher || '';

    return (
        <form
            onSubmit={submit}
            className="mt-5 rounded-panel border border-border-default bg-bg-surface p-6"
            data-a="settings:publication"
        >
            <h2 className="text-[15px] font-semibold text-text-primary">{t('settings.publishing')}</h2>
            <p className="mt-1 text-[12.5px] leading-relaxed text-text-secondary">
                {t('settings.publishing_intro')}
            </p>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                    id="publisher"
                    label={t('settings.publisher')}
                    value={data.publication.publisher}
                    error={error('publisher')}
                    onChange={(v) => set('publisher', v)}
                />
                <Field
                    id="imprint"
                    label={t('settings.imprint')}
                    value={data.publication.imprint}
                    error={error('imprint')}
                    hint={t('settings.imprint_hint')}
                    onChange={(v) => set('imprint', v)}
                />
                <Field
                    id="place"
                    label={t('settings.place')}
                    value={data.publication.place}
                    error={error('place')}
                    placeholder={t('settings.place_placeholder')}
                    onChange={(v) => set('place', v)}
                />
                <label>
                    <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                        {t('settings.country')}
                    </span>
                    <select
                        id="country"
                        value={data.publication.country}
                        data-a="settings:field:country"
                        onChange={(event) => set('country', event.target.value)}
                        className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                    >
                        <option value="">{t('settings.not_set')}</option>
                        {COUNTRIES.map(({ code, tKey }) => (
                            <option key={code} value={code}>
                                {t(tKey)}
                            </option>
                        ))}
                    </select>
                    <span className="mt-1 block text-[11.5px] text-text-secondary">
                        {t('settings.country_hint')}
                    </span>
                    {error('country') && (
                        <span role="alert" className="mt-1 block text-[11.5px] font-medium text-status-danger">
                            {error('country')}
                        </span>
                    )}
                </label>
                <Field
                    id="edition_statement"
                    label={t('settings.edition')}
                    value={data.publication.edition_statement}
                    error={error('edition_statement')}
                    placeholder={t('settings.edition_placeholder')}
                    onChange={(v) => set('edition_statement', v)}
                />
                <Field
                    id="series_name"
                    label={t('shelf.col_series')}
                    value={data.series_name}
                    error={errors.series_name}
                    hint={t('settings.series_hint')}
                    onChange={(v) => setData('series_name', v)}
                />
                <Field
                    id="series_number"
                    label={t('settings.series_number')}
                    type="number"
                    min="1"
                    value={data.series_number}
                    error={errors.series_number}
                    onChange={(v) => setData('series_number', v)}
                />
                <Field
                    id="copyright_holder"
                    label={t('settings.copyright_holder')}
                    value={data.publication.copyright_holder}
                    error={error('copyright_holder')}
                    placeholder={defaultHolder}
                    hint={
                        defaultHolder
                            ? t('settings.holder_default', { name: defaultHolder })
                            : t('settings.holder_none')
                    }
                    onChange={(v) => set('copyright_holder', v)}
                />
                <Field
                    id="copyright_year"
                    label={t('settings.copyright_year')}
                    type="number"
                    value={data.publication.copyright_year}
                    error={error('copyright_year')}
                    placeholder={t('settings.year_placeholder')}
                    onChange={(v) => set('copyright_year', v)}
                />
                <Field
                    id="isbn_print"
                    label={t('settings.isbn_print')}
                    value={data.publication.isbn_print}
                    error={error('isbn_print')}
                    placeholder="978-…"
                    inputMode="numeric"
                    onChange={(v) => set('isbn_print', v)}
                />
                <Field
                    id="isbn_epub"
                    label={t('settings.isbn_epub')}
                    value={data.publication.isbn_epub}
                    error={error('isbn_epub')}
                    placeholder="978-…"
                    inputMode="numeric"
                    onChange={(v) => set('isbn_epub', v)}
                />
                <Field
                    id="isbn_pdf"
                    label={t('settings.isbn_pdf')}
                    value={data.publication.isbn_pdf}
                    error={error('isbn_pdf')}
                    placeholder="978-…"
                    inputMode="numeric"
                    onChange={(v) => set('isbn_pdf', v)}
                />
                <Field
                    id="printing"
                    label={t('settings.printing')}
                    value={data.publication.printing}
                    error={error('printing')}
                    placeholder={t('settings.printing_placeholder')}
                    onChange={(v) => set('printing', v)}
                />
                <Field
                    id="legal_deposit"
                    label={t('settings.legal_deposit')}
                    value={data.publication.legal_deposit}
                    error={error('legal_deposit')}
                    className="sm:col-span-2"
                    placeholder={t('settings.legal_deposit_placeholder')}
                    onChange={(v) => set('legal_deposit', v)}
                />
                <TextArea
                    id="rights_statement"
                    label={t('settings.rights')}
                    value={data.publication.rights_statement}
                    error={error('rights_statement')}
                    hint={t('settings.rights_hint')}
                    onChange={(v) => set('rights_statement', v)}
                />
                <TextArea
                    id="disclaimer"
                    label={t('settings.disclaimer')}
                    value={data.publication.disclaimer}
                    error={error('disclaimer')}
                    onChange={(v) => set('disclaimer', v)}
                />
            </div>

            <fieldset className="mt-5">
                <legend className="text-[12.5px] font-semibold text-text-primary">{t('settings.credits')}</legend>
                <p className="text-[11.5px] text-text-secondary">
                    {t('settings.credits_hint')}
                </p>
                <ul className="mt-2 space-y-2">
                    {data.publication.credits.map((credit, index) => (
                        <li key={index} className="flex gap-2">
                            <input
                                value={credit.role}
                                aria-label={t('settings.credit_role_label', { n: index + 1 })}
                                placeholder={t('settings.role')}
                                data-a={`settings:credit:${index}:role`}
                                onChange={(event) => setCredit(index, 'role', event.target.value)}
                                className="h-[36px] w-2/5 rounded-control border border-border-input px-3 text-[13px] focus:border-brand-primary focus:ring-0"
                            />
                            <input
                                value={credit.name}
                                aria-label={t('settings.credit_name_label', { n: index + 1 })}
                                placeholder={t('settings.name')}
                                data-a={`settings:credit:${index}:name`}
                                onChange={(event) => setCredit(index, 'name', event.target.value)}
                                className="h-[36px] flex-1 rounded-control border border-border-input px-3 text-[13px] focus:border-brand-primary focus:ring-0"
                            />
                            <button
                                type="button"
                                aria-label={t('settings.remove_credit', { n: index + 1 })}
                                onClick={() =>
                                    set(
                                        'credits',
                                        data.publication.credits.filter((_, i) => i !== index),
                                    )
                                }
                                className="h-[36px] rounded-control border border-border-input px-2.5 text-[13px] text-text-secondary hover:text-status-danger"
                            >
                                ×
                            </button>
                        </li>
                    ))}
                </ul>
                <button
                    type="button"
                    data-a="settings:credit:add"
                    onClick={() =>
                        set('credits', [...data.publication.credits, { role: '', name: '' }])
                    }
                    className="mt-2 text-[12.5px] font-medium text-brand-primary hover:underline"
                >
                    {t('settings.add_credit')}
                </button>
            </fieldset>

            <div
                className="mt-5 rounded-control bg-bg-subtle px-4 py-3"
                data-a="settings:copyright-preview"
            >
                <p className="text-[12px] font-semibold uppercase tracking-wide text-text-secondary">
                    {t('settings.copyright_preview')}
                </p>
                {copyrightPreview?.length ? (
                    <div className="mt-1.5 space-y-1 font-serif text-[12.5px] text-text-body">
                        {copyrightPreview.map((line, index) => (
                            <p key={`${line.key}-${index}`}>{line.text}</p>
                        ))}
                    </div>
                ) : (
                    <p className="mt-1 text-[12.5px] text-text-secondary">
                        {t('settings.copyright_empty')}
                    </p>
                )}
            </div>

            <div className="mt-5 flex items-center justify-end gap-3">
                {saved && (
                    <span className="text-[12.5px] font-medium text-status-success">{t('editor.save_saved')}</span>
                )}
                <button
                    disabled={processing}
                    data-a="settings:publication:save"
                    className="h-[38px] rounded-control bg-brand-fill px-4 text-[13.5px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    {processing ? t('editor.save_saving') : t('settings.save_publishing')}
                </button>
            </div>
        </form>
    );
}

function TextArea({ id, label, value, error, hint, onChange }) {
    return (
        <label className="sm:col-span-2">
            <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                {label}
            </span>
            <textarea
                id={id}
                value={value}
                rows={2}
                data-a={`settings:field:${id}`}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? 'true' : undefined}
                className={`w-full rounded-control border px-3 py-2 text-[13.5px] focus:ring-0 ${
                    error ? 'border-status-danger' : 'border-border-input focus:border-brand-primary'
                }`}
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

/**
 * The writing preferences: autosave, which the editor honours, and the
 * citation style the bibliography and exports use.
 *
 * Each saves on change rather than waiting for a Save button: a switch that
 * needs confirming reads as broken, and these are preferences, not a form.
 */
function WritingPreferences({ book, citationStyles }) {
    const { t } = useI18n();
    const [settings, setSettings] = useState(book.settings);
    const [busy, setBusy] = useState(null);

    const toggle = (key) => {
        const next = { ...settings, [key]: !settings[key] };

        setSettings(next);
        setBusy(key);

        router.patch(
            route('books.update', book.id),
            // The one toggle: sending the whole object would write back a
            // stale copy of settings saved elsewhere on the page.
            { settings: { [key]: next[key] } },
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

    const chooseStyle = (value) => {
        const next = { ...settings, citation_style: value };

        setSettings(next);
        setBusy('citation_style');

        router.patch(
            route('books.update', book.id),
            { settings: { citation_style: value } },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setSettings(settings),
                onFinish: () => setBusy(null),
            },
        );
    };

    const rows = [
        {
            key: 'autosave',
            title: t('settings.autosave'),
            detail: t('settings.autosave_detail'),
        },
        // Inline AI suggestions and Review on save were listed here, saved,
        // and read by nothing. They return when the editor does them.
    ];

    return (
        <section className="mt-5 rounded-panel border border-border-default bg-bg-surface p-6">
            <h2 className="text-[15px] font-semibold text-text-primary">{t('settings.writing')}</h2>

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
                                settings[row.key] ? 'bg-brand-fill' : 'bg-border-input'
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

                <li className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-3.5">
                    <div className="min-w-0">
                        <p className="text-[13.5px] font-medium text-text-primary">
                            {t('settings.citation_style')}
                        </p>
                        <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">
                            {t('settings.citation_detail')}
                        </p>
                    </div>

                    <select
                        value={settings.citation_style}
                        aria-label={t('settings.citation_style')}
                        data-a="settings:citation-style"
                        disabled={busy === 'citation_style'}
                        onChange={(event) => chooseStyle(event.target.value)}
                        className="h-[36px] max-w-full shrink-0 rounded-control border border-border-input px-2.5 text-[13px] focus:border-brand-primary focus:ring-0"
                    >
                        {citationStyles.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </li>
            </ul>
        </section>
    );
}

function ArchiveBox({ book, canArchive }) {
    const { t } = useI18n();
    const [confirming, setConfirming] = useState(false);
    const archived = book.status === 'archived';

    const setStatus = (status) =>
        router.patch(route('books.update', book.id), { status }, { preserveScroll: true });

    return (
        <section className="mt-5 rounded-panel border border-status-danger/25 bg-status-danger-bg/40 p-6">
            <h2 className="text-[15px] font-semibold text-text-primary">
                {archived ? t('settings.restore_title') : t('settings.archive_title')}
            </h2>
            <p className="mt-1.5 text-[13px] leading-relaxed text-text-secondary">
                {archived
                    ? t('settings.archived_detail')
                    : t('settings.archive_detail')}
            </p>

            {archived ? (
                <button
                    data-a="settings:restore"
                    onClick={() => setStatus('draft')}
                    className="mt-4 h-[36px] rounded-control bg-bg-inverse px-4 text-[13px] font-semibold text-text-inverse transition hover:opacity-90"
                >
                    {t('settings.restore_drafts')}
                </button>
            ) : !canArchive ? (
                <p className="mt-4 flex items-center gap-2 text-[12.5px] text-text-secondary">
                    <Icon name="info" size={15} />
                    {t('settings.only_book')}
                </p>
            ) : confirming ? (
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <span className="text-[13px] font-medium text-status-danger">
                        {t('settings.archive_confirm', { title: book.title })}
                    </span>
                    <button
                        data-a="settings:archive:cancel"
                        onClick={() => setConfirming(false)}
                        className="h-[34px] rounded-control border border-border-input bg-bg-surface px-3.5 text-[13px] font-medium text-text-body"
                    >
                        {t('common.cancel')}
                    </button>
                    <button
                        data-a="settings:archive:confirm"
                        onClick={() => setStatus('archived')}
                        className="h-[34px] rounded-control bg-status-danger-fill px-3.5 text-[13px] font-semibold text-white transition hover:opacity-90"
                    >
                        {t('settings.archive_book')}
                    </button>
                </div>
            ) : (
                <button
                    data-a="settings:archive"
                    onClick={() => setConfirming(true)}
                    className="mt-4 h-[36px] rounded-control border border-status-danger/40 bg-bg-surface px-4 text-[13px] font-semibold text-status-danger transition hover:bg-status-danger-bg"
                >
                    {t('settings.archive_title')}
                </button>
            )}
        </section>
    );
}

/** Countries of publication offered; the server takes any ISO 3166 code. */
const COUNTRIES = [
    { code: 'HT', tKey: 'settings.country_ht' },
    { code: 'US', tKey: 'settings.country_us' },
    { code: 'CA', tKey: 'settings.country_ca' },
    { code: 'FR', tKey: 'settings.country_fr' },
    { code: 'BE', tKey: 'settings.country_be' },
    { code: 'CH', tKey: 'settings.country_ch' },
    { code: 'GB', tKey: 'settings.country_gb' },
    { code: 'DO', tKey: 'settings.country_do' },
    { code: 'ES', tKey: 'settings.country_es' },
    { code: 'DE', tKey: 'settings.country_de' },
    { code: 'PT', tKey: 'settings.country_pt' },
];

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
