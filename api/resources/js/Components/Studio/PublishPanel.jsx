import { useCallback, useEffect, useRef, useState } from 'react';
import { formatNumber, t, useI18n } from '../../i18n';
import ExportProfiles, { formatProblem, profileInput } from './ExportProfiles';
import Icon from './Icon';
import PrintKit from './PrintKit';

/**
 * Export & Publish.
 *
 * The blockers list is shown before an author tries to submit, not only after
 * a refusal. Each message is keyed to its field so it appears next to the
 * input it is about: being told "description is required" and then having to
 * find the description is the failure this avoids.
 */
export default function PublishPanel({ bookId, onNotify, onPreview }) {
    useI18n();
    const [data, setData] = useState(null);
    // When the payload arrived, and the clock it is read against: a
    // generating row's wait_seconds counts from the former.
    const [loadedAt, setLoadedAt] = useState(0);
    const [now, setNow] = useState(() => Date.now());
    const [busy, setBusy] = useState(null);
    // The export profile the next file is made with (ExportProfiles).
    const [choice, setChoice] = useState(null);
    // A reply that lands after the panel closed must not set state.
    const alive = useRef(true);
    const pending = useRef(null);
    // Every change since the last send. A single timer used to send only
    // the last edit, so a second field typed within the debounce dropped
    // the first, and the server's reply then put the old value back.
    const queued = useRef({});

    const apply = useCallback((payload) => {
        if (!alive.current) return;

        const at = Date.now();
        setData(payload);
        setLoadedAt(at);
        setNow(at);
    }, []);

    const load = useCallback(async () => {
        try {
            const response = await window.axios.get(`/books/${bookId}/publishing`);
            apply(response.data);
        } catch {
            // A failed poll is retried by the next one; the first load
            // leaves "Loading…" up, which is honest.
        }
    }, [bookId, apply]);

    useEffect(() => {
        alive.current = true;
        load();

        return () => {
            alive.current = false;
        };
    }, [load]);

    const save = (changes) => {
        setData((current) => ({ ...current, book: { ...current.book, ...changes } }));

        queued.current = { ...queued.current, ...changes };
        clearTimeout(pending.current);

        pending.current = setTimeout(async () => {
            const batch = queued.current;
            queued.current = {};

            try {
                const response = await window.axios.patch(`/books/${bookId}/publishing`, batch);
                apply(response.data);
            } catch (error) {
                onNotify?.(error.response?.data?.message ?? t('publish.change_not_saved'));
                load();
            }
        }, 600);
    };

    const toggleChannel = async (channel, enabled) => {
        setBusy(`channel:${channel}`);

        try {
            const response = await window.axios.post(`/books/${bookId}/channels`, {
                channel,
                enabled,
            });

            apply(response.data);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('publish.store_not_changed'));
        } finally {
            setBusy(null);
        }
    };

    const unpublish = async (channelLabel, channelId) => {
        // Confirmed because it takes a book off sale, which readers see.
        if (!window.confirm(t('publish.confirm_unpublish', { store: channelLabel }))) return;

        setBusy(`unpublish:${channelId}`);

        try {
            const response = await window.axios.post(
                `/books/${bookId}/channels/${channelId}/unpublish`,
            );

            apply(response.data);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('publish.store_not_changed'));
        } finally {
            setBusy(null);
        }
    };

    // Écrivain does not send books to stores: the author uploads the file
    // and then says so here, which is when the store's review begins.
    const markSubmitted = async (channelId) => {
        setBusy(`submitted:${channelId}`);

        try {
            const response = await window.axios.post(
                `/books/${bookId}/channels/${channelId}/submitted`,
            );

            apply(response.data);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('publish.store_not_changed'));
        } finally {
            setBusy(null);
        }
    };

    const submit = async () => {
        setBusy('submit');

        try {
            const response = await window.axios.post(`/books/${bookId}/submit`);
            apply(response.data);
            onNotify?.(
                response.data.message ??
                    t('publish.ready_to_upload_toast'),
            );
        } catch (error) {
            // Flushed into the payload so every message lands beside its
            // field, rather than appearing only in a toast that scrolls away.
            if (error.response?.status === 422) {
                setData((current) => ({
                    ...current,
                    blockers: error.response.data.errors ?? current.blockers,
                }));
                onNotify?.(error.response.data.message ?? t('publish.not_ready_to_submit'));
            } else {
                onNotify?.(t('publish.not_marked_ready'));
            }
        } finally {
            setBusy(null);
        }
    };

    // A PDF or EPUB renders on a worker and can take minutes: while any
    // export is still generating, check back every few seconds. Not for
    // ever: past the job timeout the server fails the row, and a row still
    // "generating" then belongs to a worker that died, so the screen stops
    // asking and offers to try again.
    const waiting = (item) =>
        item.status === 'generating' && loadedAt + (item.wait_seconds ?? 0) * 1000 > now;
    const generating = data?.exports.some(waiting);

    useEffect(() => {
        if (!generating) return undefined;

        const timer = setInterval(() => {
            setNow(Date.now());
            load();
        }, 5000);

        return () => clearInterval(timer);
    }, [generating, load]);

    const generate = async (format, options = undefined, profile = undefined) => {
        setBusy(`format:${format}`);

        try {
            const response = await window.axios.post(`/books/${bookId}/exports`, { format, options, profile });
            apply(response.data.book);
            onNotify?.(
                response.data.already
                    ? t('publish.generate_already', { label: response.data.export.label })
                    : response.data.export.status === 'generating'
                      ? t('publish.generate_started', { label: response.data.export.label })
                      : t('publish.generate_ready', { label: response.data.export.label }),
            );
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('publish.generate_failed'));
        } finally {
            setBusy(null);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { book, channels, formats, exports, blockers, categories, warnings = {}, profiles } = data;
    const current = choice ?? { key: 'full', options: profiles.presets[0].options, changed: false };
    // A failed or expired file is made again as it was made.
    const again = (item) =>
        item.profile_options
            ? { ...item.profile_options, preset: profiles.presets.some((p) => p.key === item.profile_options.preset) ? item.profile_options.preset : 'custom' }
            : undefined;
    // Shown from the start rather than only after a failed attempt: an
    // author should be able to see what is left before trying.
    const problem = (key) => blockers[key];

    const live = channels.filter((channel) => channel.status === 'live').length;
    const reviewing = channels.filter((channel) => channel.status === 'in_review').length;
    const toUpload = channels.filter((channel) => channel.status === 'ready_to_submit').length;
    const ready = Object.keys(blockers).length === 0;
    // One render per format at a time: the button stays off while one runs.
    const rendering = (format) => exports.some((item) => item.format === format && waiting(item));
    const available = (format) => formats.find((item) => item.format === format)?.available;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">
                        {t('publish.panel_title')}
                    </h2>
                    <p className="text-[11.5px] text-text-secondary">
                        {live > 0
                            ? `${t('publish.live_in', { count: live })}${reviewing ? ` · ${t('publish.in_review_count', { count: reviewing })}` : ''}`
                            : reviewing > 0
                              ? t('publish.reviewing', { count: reviewing })
                              : toUpload > 0
                                ? t('publish.ready_upload_to', { count: toUpload })
                                : t('publish.not_published')}
                    </p>
                </div>

                {onPreview && (
                    <button
                        type="button"
                        data-a="publish:preview"
                        onClick={onPreview}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-control border border-border-input px-3 py-2 text-[12.5px] font-medium text-text-body transition hover:border-brand-primary/40"
                    >
                        <Icon name="eye" size={13} strokeWidth={2} />
                        {t('publish.preview')}
                    </button>
                )}

                <button
                    data-a="publish:submit"
                    onClick={submit}
                    disabled={busy === 'submit'}
                    className={`${onPreview ? '' : 'ml-auto '}inline-flex items-center gap-1.5 rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50`}
                >
                    <Icon name="exp" size={13} strokeWidth={2} />
                    {busy === 'submit' ? t('publish.checking') : t('publish.prepare')}
                </button>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                {!ready && (
                    <div
                        role="status"
                        data-a="publish:blockers"
                        className="rounded-tile border border-status-warning/30 bg-status-warning-bg p-3"
                    >
                        <p className="text-[12.5px] font-semibold text-status-warning">
                            {t('publish.blockers_title')}
                        </p>
                        <ul className="mt-1.5 list-disc space-y-0.5 pl-4">
                            {Object.entries(blockers).map(([key, message]) => (
                                <li key={key} className="text-[11.5px] text-status-warning">
                                    {message}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Not blocking: pending suggestions are left out of every
                    file, which an author who meant to accept them needs to
                    hear before uploading one. */}
                {Object.keys(warnings).length > 0 && (
                    <div
                        role="status"
                        data-a="publish:warnings"
                        className="rounded-tile border border-status-warning/30 bg-status-warning-bg p-3"
                    >
                        <ul className="space-y-0.5">
                            {Object.entries(warnings).map(([key, message]) => (
                                <li key={key} data-a={`publish:warning:${key}`} className="text-[11.5px] text-status-warning">
                                    {message}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">
                            {t('publish.listing')}
                        </h3>

                        <Field label={t('publish.field_title')} error={problem('title')}>
                            <input
                                value={book.title ?? ''}
                                data-a="publish:title"
                                onChange={(event) => save({ title: event.target.value })}
                                className={inputClass(problem('title'))}
                            />
                        </Field>

                        <Field label={t('publish.field_author')} error={problem('author_name')}>
                            <input
                                value={book.author_name ?? ''}
                                data-a="publish:author"
                                placeholder={t('publish.author_placeholder')}
                                onChange={(event) => save({ author_name: event.target.value })}
                                className={inputClass(problem('author_name'))}
                            />
                        </Field>

                        <Field label={t('publish.field_description')} error={problem('description')}>
                            <textarea
                                value={book.description ?? ''}
                                rows={5}
                                data-a="publish:description"
                                placeholder={t('publish.description_placeholder')}
                                onChange={(event) => save({ description: event.target.value })}
                                className={inputClass(problem('description'))}
                            />
                            <span className="mt-0.5 block text-[10.5px] text-text-secondary">
                                {t('publish.description_count', { count: (book.description ?? '').length })}
                            </span>
                        </Field>

                        <Field label={t('publish.category')} error={problem('category')}>
                            <select
                                value={book.category ?? ''}
                                data-a="publish:category"
                                onChange={(event) => save({ category: event.target.value })}
                                className={inputClass(problem('category'))}
                            >
                                <option value="">{t('publish.choose_category')}</option>
                                {categories.map((category) => (
                                    <option key={category} value={category}>{category}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label={t('publish.field_keywords')}>
                            <input
                                value={book.keywords ?? ''}
                                data-a="publish:keywords"
                                placeholder={t('publish.keywords_placeholder')}
                                onChange={(event) => save({ keywords: event.target.value })}
                                className={inputClass(null)}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                            <Field label={t('publish.field_price')} error={problem('list_price')}>
                                <input
                                    value={book.list_price ?? ''}
                                    inputMode="decimal"
                                    data-a="publish:price"
                                    onChange={(event) =>
                                        save({ list_price: event.target.value || null })
                                    }
                                    className={inputClass(problem('list_price'))}
                                />
                            </Field>

                            <Field label="ISBN" error={problem('isbn')}>
                                <input
                                    value={book.isbn ?? ''}
                                    data-a="publish:isbn"
                                    placeholder="978…"
                                    onChange={(event) => save({ isbn: event.target.value || null })}
                                    className={inputClass(problem('isbn'))}
                                />
                                <span className="mt-0.5 block text-[10.5px] text-text-secondary">
                                    {t('publish.isbn_help')}
                                </span>
                            </Field>
                        </div>
                    </section>

                    <div className="space-y-4">
                        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">
                                {t('publish.stores')}
                            </h3>
                            <p className="mb-1.5 text-[11px] leading-snug text-text-secondary">
                                {t('publish.stores_help')}
                            </p>
                            {problem('channels') && (
                                <p className="mb-1.5 text-[11px] font-medium text-status-warning">
                                    {blockers.channels}
                                </p>
                            )}

                            {channels.map((channel) => (
                                <div
                                    key={channel.channel}
                                    className="flex items-center justify-between gap-3 border-b border-border-default py-2 last:border-0"
                                >
                                    <div className="min-w-0">
                                        <p className="text-[12.5px] font-medium text-text-primary">
                                            {channel.label}
                                        </p>
                                        <p className="text-[11px] text-text-secondary">
                                            {channel.format === 'print' ? t('publish.format_print') : t('publish.format_ebook')}
                                            {' · '}
                                            <ChannelStatus channel={channel} />
                                        </p>
                                        {channel.status === 'ready_to_submit' && channel.upload?.url && (
                                            <p
                                                data-a={`publish:upload:${channel.channel}`}
                                                className="mt-0.5 text-[11px] leading-snug text-text-body"
                                            >
                                                {channel.upload.steps}{' '}
                                                <a
                                                    href={channel.upload.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="font-semibold text-brand-primary hover:underline"
                                                >
                                                    {t('publish.open_store', { store: channel.label })}
                                                </a>
                                            </p>
                                        )}
                                    </div>

                                    {channel.status === 'ready_to_submit' ? (
                                        <button
                                            data-a={`publish:submitted:${channel.channel}`}
                                            disabled={busy !== null}
                                            onClick={() => markSubmitted(channel.id)}
                                            className="shrink-0 rounded-control border border-border-input px-2 py-1 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50"
                                        >
                                            {t('publish.i_uploaded')}
                                        </button>
                                    ) : channel.status === 'live' ? (
                                        <button
                                            data-a={`publish:unpublish:${channel.channel}`}
                                            disabled={busy !== null}
                                            onClick={() => unpublish(channel.label, channel.id)}
                                            className="shrink-0 rounded-control border border-border-input px-2 py-1 text-[11.5px] font-medium text-text-body transition hover:border-status-danger/40 disabled:opacity-50"
                                        >
                                            {t('publish.unpublish')}
                                        </button>
                                    ) : (
                                        <button
                                            role="switch"
                                            aria-checked={channel.enabled}
                                            aria-label={channel.label}
                                            data-a={`publish:channel:${channel.channel}`}
                                            disabled={busy === `channel:${channel.channel}`}
                                            onClick={() =>
                                                toggleChannel(channel.channel, !channel.enabled)
                                            }
                                            className={`h-[20px] w-[34px] shrink-0 rounded-full p-[2px] transition disabled:opacity-50 ${
                                                channel.enabled
                                                    ? 'bg-brand-fill'
                                                    : 'bg-border-input'
                                            }`}
                                        >
                                            <span
                                                className={`block h-4 w-4 rounded-full bg-white transition-transform ${
                                                    channel.enabled ? 'translate-x-[14px]' : ''
                                                }`}
                                            />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </section>

                        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                            <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                                {t('publish.files')}
                            </h3>

                            <ExportProfiles
                                bookId={bookId}
                                profiles={profiles}
                                choice={current}
                                onChoose={setChoice}
                                onSaved={apply}
                                onNotify={onNotify}
                            />

                            {/* The print cover is made from Print & price,
                                where its service and paper are chosen. */}
                            {formats.filter((format) => format.format !== 'cover_wrap').map((format) => {
                                const reason = formatProblem(current, format.format, profiles);

                                return (
                                <div
                                    key={format.format}
                                    className="flex items-center justify-between gap-3 border-b border-border-default py-2 last:border-0"
                                >
                                    <div className="min-w-0">
                                        <p className="text-[12.5px] font-medium text-text-primary">
                                            {format.label}
                                        </p>
                                        <p className="text-[11px] leading-snug text-text-secondary">
                                            {reason ?? format.description}
                                        </p>
                                    </div>

                                    <button
                                        data-a={`publish:generate:${format.format}`}
                                        disabled={
                                            busy === `format:${format.format}` ||
                                            rendering(format.format) ||
                                            reason !== null
                                        }
                                        title={reason ?? undefined}
                                        onClick={() => generate(format.format, undefined, profileInput(current))}
                                        className="shrink-0 rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50"
                                    >
                                        {busy === `format:${format.format}` ||
                                        rendering(format.format)
                                            ? t('publish.generating')
                                            : t('publish.generate')}
                                    </button>
                                </div>
                                );
                            })}
                        </section>
                    </div>
                </div>

                <PrintKit
                    bookId={bookId}
                    book={book}
                    refreshKey={[
                        exports.map((item) => `${item.id}:${item.status}`).join(','),
                        book.description,
                        book.category,
                        book.keywords,
                        book.isbn,
                        channels.filter((channel) => channel.enabled).map((channel) => channel.channel).join(','),
                    ].join('|')}
                    save={save}
                    generate={generate}
                    busy={busy}
                    rendering={rendering}
                    locked={!available('cover_wrap')}
                    onNotify={onNotify}
                />

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                        {t('publish.history')}
                    </h3>

                    {exports.length === 0 ? (
                        <p className="text-[11.5px] text-text-secondary">
                            {t('publish.history_empty')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[12px]">
                                <thead>
                                    <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                        <th className="py-1.5 pr-3 font-medium">{t('publish.col_file')}</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">{t('publish.col_words')}</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">{t('publish.col_size')}</th>
                                        <th className="py-1.5 pr-3 font-medium">{t('publish.col_created')}</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {exports.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-b border-border-default last:border-0"
                                        >
                                            <td className="py-1.5 pr-3 text-text-primary" data-a={`publish:label:${item.id}`}>
                                                {item.label}
                                                {!item.full_book && (
                                                    <span className="ml-1.5 whitespace-nowrap rounded-full bg-bg-subtle px-1.5 py-px text-[10.5px] font-medium text-text-secondary">
                                                        {t('publish.not_for_stores')}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right tabular-nums text-text-body">
                                                {formatNumber(item.word_count)}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right tabular-nums text-text-body">
                                                {item.size ?? '—'}
                                            </td>
                                            <td className="py-1.5 pr-3 text-text-secondary">
                                                {item.created_at}
                                            </td>
                                            <td className="py-1.5 text-right">
                                                {item.status === 'ready' ? (
                                                    <a
                                                        href={`/books/${bookId}/exports/${item.id}/download`}
                                                        data-a={`publish:download:${item.id}`}
                                                        className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                                    >
                                                        {t('publish.download')}
                                                    </a>
                                                ) : waiting(item) ? (
                                                    <span
                                                        data-a={`publish:generating:${item.id}`}
                                                        className="text-[11px] text-text-secondary"
                                                    >
                                                        {t('publish.generating')}
                                                    </span>
                                                ) : (
                                                    <span
                                                        data-a={`publish:state:${item.id}`}
                                                        className={`text-[11px] ${item.status === 'expired' ? 'text-text-secondary' : 'text-status-danger'}`}
                                                    >
                                                        {item.status === 'expired'
                                                            ? t('publish.file_gone')
                                                            : t('publish.failed')}
                                                        {available(item.format) && (
                                                            <>
                                                                {' — '}
                                                                <button
                                                                    data-a={`publish:retry:${item.id}`}
                                                                    disabled={
                                                                        busy !== null ||
                                                                        rendering(item.format)
                                                                    }
                                                                    onClick={() => generate(item.format, undefined, again(item))}
                                                                    className="font-semibold text-brand-primary hover:underline disabled:opacity-50"
                                                                >
                                                                    {item.status === 'expired'
                                                                        ? t('publish.generate_again')
                                                                        : t('publish.try_again')}
                                                                </button>
                                                            </>
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}

function ChannelStatus({ channel }) {
    if (channel.status === 'live') {
        return (
            <span className="font-medium text-status-success">
                {t('publish.status_live')}{channel.went_live_at ? ` · ${channel.went_live_at}` : ''}
            </span>
        );
    }

    if (channel.status === 'in_review') {
        return (
            <span className="font-medium text-status-warning">
                {t('publish.in_review')}{channel.submitted_at ? ` · ${t('publish.uploaded_on', { date: channel.submitted_at })}` : ''}
            </span>
        );
    }

    if (channel.status === 'ready_to_submit') {
        return <span className="font-medium text-status-warning">{t('publish.status_ready_to_submit')}</span>;
    }

    if (channel.status === 'unpublished') {
        return <span className="text-text-secondary">{t('publish.status_unpublished')}</span>;
    }

    return (
        <span className="text-text-secondary">
            {channel.enabled ? t('publish.selected_not_submitted') : t('publish.not_selected')}
        </span>
    );
}

const inputClass = (error) =>
    `w-full rounded-control border px-2 py-1.5 text-[12.5px] focus:ring-0 ${
        error
            ? 'border-status-danger focus:border-status-danger'
            : 'border-border-input focus:border-brand-primary'
    }`;

function Field({ label, error, children }) {
    return (
        <label className="mb-2.5 block">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                {label}
            </span>
            {children}
            {error && (
                <span className="mt-0.5 block text-[11px] font-medium text-status-danger">
                    {error}
                </span>
            )}
        </label>
    );
}
