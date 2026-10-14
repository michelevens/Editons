import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Editions.
 *
 * An edition is a real, separate book that remembers its source. It is not a
 * view: a translation gets edited independently, and a workbook diverges
 * immediately, so sharing content would mean an edit to one silently changing
 * the other.
 *
 * The translation card is honest about what it does. It copies the structure
 * and the text and sets the language; it does not claim the prose has been
 * translated, because it has not been.
 */
export default function EditionsPanel({ bookId, onNotify }) {
    const [data, setData] = useState(null);
    const [language, setLanguage] = useState('Spanish');
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/editions`);
        setData(response.data);
        setLanguage(response.data.languages[0]);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const create = async (type) => {
        setBusy(type);

        try {
            const response = await window.axios.post(`/books/${bookId}/editions`, {
                type,
                language: type === 'translation' ? language : null,
            });

            setData((current) => ({ ...current, editions: response.data.editions }));
            onNotify?.(`${response.data.edition.title} was created. It is on your shelf now.`);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? 'That edition could not be created.');
        } finally {
            setBusy(null);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { editions, languages, source, derived_from: derivedFrom } = data;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">Editions</h2>
                <p className="text-[11.5px] text-text-secondary">
                    New versions of this book, built from the same manuscript
                </p>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                {derivedFrom && (
                    <p
                        data-a="editions:derived"
                        className="rounded-tile bg-status-info-bg px-3 py-2 text-[11.5px] text-status-info"
                    >
                        This book is itself a {derivedFrom.type?.replace('_', ' ')} of{' '}
                        <button
                            onClick={() => router.get(`/books/${derivedFrom.id}/edit`)}
                            className="font-semibold underline"
                        >
                            {derivedFrom.title}
                        </button>
                        .
                    </p>
                )}

                <div className="grid gap-3 lg:grid-cols-3">
                    <Card
                        title="Workbook edition"
                        body="Builds a companion workbook from this book's headings, with a reflection prompt under each one for the reader to fill in."
                        action="Create workbook"
                        busy={busy === 'workbook'}
                        disabled={busy !== null || source.chapters === 0}
                        dataA="editions:workbook"
                        onAction={() => create('workbook')}
                    />

                    <Card
                        title="Translation"
                        body="Starts a translated edition with the same structure and your text in place, ready to be translated chapter by chapter."
                        action="Start translation"
                        busy={busy === 'translation'}
                        disabled={busy !== null || source.chapters === 0}
                        dataA="editions:translation"
                        onAction={() => create('translation')}
                    >
                        <label className="mb-2.5 block">
                            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                                Language
                            </span>
                            <select
                                value={language}
                                data-a="editions:language"
                                onChange={(event) => setLanguage(event.target.value)}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            >
                                {languages.map((option) => (
                                    <option key={option} value={option}>{option}</option>
                                ))}
                            </select>
                        </label>
                    </Card>

                    <Card
                        title="Large-print edition"
                        body="The same text set at 14 point with wide margins on a 7 × 10 inch page, for readers with low vision. It needs its own ISBN."
                        action="Create large print"
                        busy={busy === 'large_print'}
                        disabled={busy !== null || source.chapters === 0}
                        dataA="editions:large_print"
                        onAction={() => create('large_print')}
                    />
                </div>

                {source.chapters === 0 && (
                    <p className="text-[11.5px] text-status-warning">
                        Write a chapter before making an edition of this book.
                    </p>
                )}

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                        Editions of this book
                    </h3>

                    {editions.length === 0 ? (
                        <p className="text-[11.5px] text-text-secondary">
                            Editions you create appear here, and on your shelf alongside this
                            book.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[12px]">
                                <thead>
                                    <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                        <th className="py-1.5 pr-3 font-medium">Edition</th>
                                        <th className="py-1.5 pr-3 font-medium">Type</th>
                                        <th className="py-1.5 pr-3 text-right font-medium">
                                            Chapters
                                        </th>
                                        <th className="py-1.5 pr-3 text-right font-medium">Words</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {editions.map((edition) => (
                                        <tr
                                            key={edition.id}
                                            className="border-b border-border-default last:border-0"
                                        >
                                            <td className="py-2 pr-3 text-text-primary">
                                                {edition.title}
                                            </td>
                                            <td className="py-2 pr-3 text-text-body">
                                                {edition.type?.replace('_', ' ')}
                                                {edition.language ? ` · ${edition.language}` : ''}
                                            </td>
                                            <td className="py-2 pr-3 text-right tabular-nums text-text-body">
                                                {edition.sections}
                                            </td>
                                            <td className="py-2 pr-3 text-right tabular-nums text-text-body">
                                                {edition.word_count.toLocaleString()}
                                            </td>
                                            <td className="py-2 text-right">
                                                <button
                                                    data-a={`editions:open:${edition.id}`}
                                                    onClick={() =>
                                                        router.get(`/books/${edition.id}/edit`)
                                                    }
                                                    className="rounded-control bg-brand-primary px-2 py-1 text-[11.5px] font-semibold text-white"
                                                >
                                                    Open
                                                </button>
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

function Card({ title, body, action, busy, disabled, dataA, onAction, children }) {
    return (
        <article className="flex flex-col rounded-tile border border-border-default bg-bg-surface p-3.5">
            <h3 className="text-[13px] font-semibold text-text-primary">{title}</h3>
            <p className="mt-1 mb-3 text-[11.5px] leading-relaxed text-text-secondary">{body}</p>

            {children}

            <button
                data-a={dataA}
                onClick={onAction}
                disabled={disabled}
                className="mt-auto rounded-control bg-brand-primary px-2.5 py-2 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
            >
                {busy ? 'Creating…' : action}
            </button>
        </article>
    );
}
