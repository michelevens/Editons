import { useCallback, useEffect, useRef, useState } from 'react';
import Icon from './Icon';

/**
 * Export & Publish.
 *
 * The blockers list is shown before an author tries to submit, not only after
 * a refusal. Each message is keyed to its field so it appears next to the
 * input it is about: being told "description is required" and then having to
 * find the description is the failure this avoids.
 */
export default function PublishPanel({ bookId, onNotify }) {
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(null);
    const pending = useRef(null);
    // Every change since the last send. A single timer used to send only
    // the last edit, so a second field typed within the debounce dropped
    // the first, and the server's reply then put the old value back.
    const queued = useRef({});

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/publishing`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
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
                setData(response.data);
            } catch (error) {
                onNotify?.(error.response?.data?.message ?? 'That change was not saved.');
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

            setData(response.data);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That store could not be changed.');
        } finally {
            setBusy(null);
        }
    };

    const unpublish = async (channelLabel, channelId) => {
        // Confirmed because it takes a book off sale, which readers see.
        if (!window.confirm(`Take this book off sale in ${channelLabel}?`)) return;

        setBusy(`unpublish:${channelId}`);

        try {
            const response = await window.axios.post(
                `/books/${bookId}/channels/${channelId}/unpublish`,
            );

            setData(response.data);
        } finally {
            setBusy(null);
        }
    };

    const submit = async () => {
        setBusy('submit');

        try {
            const response = await window.axios.post(`/books/${bookId}/submit`);
            setData(response.data);
            onNotify?.('Submitted. Each store reviews a book before it goes on sale.');
        } catch (error) {
            // Flushed into the payload so every message lands beside its
            // field, rather than appearing only in a toast that scrolls away.
            if (error.response?.status === 422) {
                setData((current) => ({
                    ...current,
                    blockers: error.response.data.errors ?? current.blockers,
                }));
                onNotify?.(error.response.data.message ?? 'This book is not ready to submit.');
            } else {
                onNotify?.('The submission could not be sent.');
            }
        } finally {
            setBusy(null);
        }
    };

    // A PDF or EPUB renders on a worker and can take minutes: while any
    // export is still generating, check back every few seconds.
    const generating = data?.exports.some((item) => item.status === 'generating');

    useEffect(() => {
        if (!generating) return undefined;

        const timer = setInterval(load, 5000);

        return () => clearInterval(timer);
    }, [generating, load]);

    const generate = async (format) => {
        setBusy(`format:${format}`);

        try {
            const response = await window.axios.post(`/books/${bookId}/exports`, { format });
            setData(response.data.book);
            onNotify?.(
                response.data.export.status === 'generating'
                    ? `${response.data.export.label} is being generated. It will appear below when it is ready.`
                    : `${response.data.export.label} is ready.`,
            );
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That file could not be generated.');
        } finally {
            setBusy(null);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { book, channels, formats, exports, blockers, categories } = data;
    // Shown from the start rather than only after a failed attempt: an
    // author should be able to see what is left before trying.
    const problem = (key) => blockers[key];

    const live = channels.filter((channel) => channel.status === 'live').length;
    const reviewing = channels.filter((channel) => channel.status === 'in_review').length;
    const ready = Object.keys(blockers).length === 0;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">
                        Export &amp; Publish
                    </h2>
                    <p className="text-[11.5px] text-text-secondary">
                        {live > 0
                            ? `Live in ${live} store${live === 1 ? '' : 's'}${reviewing ? ` · ${reviewing} in review` : ''}`
                            : reviewing > 0
                              ? `${reviewing} store${reviewing === 1 ? '' : 's'} reviewing this book`
                              : 'Not published yet'}
                    </p>
                </div>

                <button
                    data-a="publish:submit"
                    onClick={submit}
                    disabled={busy === 'submit'}
                    className="ml-auto inline-flex items-center gap-1.5 rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    <Icon name="exp" size={13} strokeWidth={2} />
                    {busy === 'submit' ? 'Submitting…' : 'Submit for publishing'}
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
                            Before this book can be submitted:
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

                <div className="grid gap-4 xl:grid-cols-2">
                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">
                            Store listing
                        </h3>

                        <Field label="Title" error={problem('title')}>
                            <input
                                value={book.title ?? ''}
                                data-a="publish:title"
                                onChange={(event) => save({ title: event.target.value })}
                                className={inputClass(problem('title'))}
                            />
                        </Field>

                        <Field label="Author" error={problem('author_name')}>
                            <input
                                value={book.author_name ?? ''}
                                data-a="publish:author"
                                placeholder="The name as it appears on the cover"
                                onChange={(event) => save({ author_name: event.target.value })}
                                className={inputClass(problem('author_name'))}
                            />
                        </Field>

                        <Field label="Description" error={problem('description')}>
                            <textarea
                                value={book.description ?? ''}
                                rows={5}
                                data-a="publish:description"
                                placeholder="What a reader will get from this book, in two or three sentences."
                                onChange={(event) => save({ description: event.target.value })}
                                className={inputClass(problem('description'))}
                            />
                            <span className="mt-0.5 block text-[10.5px] text-text-secondary">
                                {(book.description ?? '').length} characters · most stores want
                                100 or more
                            </span>
                        </Field>

                        <Field label="Category" error={problem('category')}>
                            <select
                                value={book.category ?? ''}
                                data-a="publish:category"
                                onChange={(event) => save({ category: event.target.value })}
                                className={inputClass(problem('category'))}
                            >
                                <option value="">Choose a category…</option>
                                {categories.map((category) => (
                                    <option key={category} value={category}>{category}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Keywords">
                            <input
                                value={book.keywords ?? ''}
                                data-a="publish:keywords"
                                placeholder="Comma separated"
                                onChange={(event) => save({ keywords: event.target.value })}
                                className={inputClass(null)}
                            />
                        </Field>

                        <div className="grid gap-2.5 sm:grid-cols-2">
                            <Field label="List price (USD)" error={problem('list_price')}>
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
                                    Assign one on the ISBN &amp; Rights screen, or leave blank
                                    and let the store issue its own.
                                </span>
                            </Field>
                        </div>
                    </section>

                    <div className="space-y-4">
                        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">
                                Stores
                            </h3>
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
                                            {channel.format === 'print' ? 'Print' : 'eBook'}
                                            {' · '}
                                            <ChannelStatus channel={channel} />
                                        </p>
                                    </div>

                                    {channel.status === 'live' ? (
                                        <button
                                            data-a={`publish:unpublish:${channel.channel}`}
                                            disabled={busy !== null}
                                            onClick={() => unpublish(channel.label, channel.id)}
                                            className="shrink-0 rounded-control border border-border-input px-2 py-1 text-[11.5px] font-medium text-text-body transition hover:border-status-danger/40 disabled:opacity-50"
                                        >
                                            Unpublish
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
                                Files
                            </h3>

                            {formats.map((format) => (
                                <div
                                    key={format.format}
                                    className="flex items-center justify-between gap-3 border-b border-border-default py-2 last:border-0"
                                >
                                    <div className="min-w-0">
                                        <p className="text-[12.5px] font-medium text-text-primary">
                                            {format.label}
                                        </p>
                                        <p className="text-[11px] leading-snug text-text-secondary">
                                            {format.description}
                                        </p>
                                    </div>

                                    <button
                                        data-a={`publish:generate:${format.format}`}
                                        disabled={busy === `format:${format.format}`}
                                        onClick={() => generate(format.format)}
                                        className="shrink-0 rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50"
                                    >
                                        {busy === `format:${format.format}`
                                            ? 'Generating…'
                                            : 'Generate'}
                                    </button>
                                </div>
                            ))}
                        </section>
                    </div>
                </div>

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                        Export history
                    </h3>

                    {exports.length === 0 ? (
                        <p className="text-[11.5px] text-text-secondary">
                            Files you generate are kept here, so you can always go back to the
                            one you uploaded.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[12px]">
                                <thead>
                                    <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                        <th className="py-1.5 pr-3 font-medium">File</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">Words</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">Size</th>
                                        <th className="py-1.5 pr-3 font-medium">Created</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {exports.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-b border-border-default last:border-0"
                                        >
                                            <td className="py-1.5 pr-3 text-text-primary">
                                                {item.label}
                                            </td>
                                            <td className="py-1.5 pr-3 text-right tabular-nums text-text-body">
                                                {item.word_count.toLocaleString()}
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
                                                        Download
                                                    </a>
                                                ) : item.status === 'generating' ? (
                                                    <span className="text-[11px] text-text-secondary">
                                                        Generating…
                                                    </span>
                                                ) : (
                                                    <span className="text-[11px] text-status-danger">
                                                        {item.status}
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
                Live{channel.went_live_at ? ` · ${channel.went_live_at}` : ''}
            </span>
        );
    }

    if (channel.status === 'in_review') {
        return (
            <span className="font-medium text-status-warning">
                In review{channel.submitted_at ? ` · sent ${channel.submitted_at}` : ''}
            </span>
        );
    }

    if (channel.status === 'unpublished') {
        return <span className="text-text-secondary">Taken off sale</span>;
    }

    return (
        <span className="text-text-secondary">
            {channel.enabled ? 'Selected, not yet submitted' : 'Not selected'}
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
