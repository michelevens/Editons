import { useCallback, useEffect, useState } from 'react';
import { t, useI18n } from '../../i18n';
import Icon from './Icon';
import PublishingDeclarations from './PublishingDeclarations';

/**
 * ISBN & Rights.
 *
 * Two kinds of checklist item sit side by side: ones computed from the
 * manuscript, which update themselves, and ones only the author knows, which
 * are attestations they tick. The screen does not distinguish them visually,
 * because to the author they are all just things to get done — but deleting
 * the copyright page does put its check back to unmet.
 *
 * Nothing here is legal advice, and the checklist says so by linking to the
 * Copyright Office rather than explaining the law.
 */
export default function RightsPanel({ bookId, onNotify }) {
    useI18n();
    const [data, setData] = useState(null);
    const [entering, setEntering] = useState(null);
    const [entry, setEntry] = useState('');
    const [poolDraft, setPoolDraft] = useState('');
    const [showPoolForm, setShowPoolForm] = useState(false);
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/rights`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const assign = async (format, body) => {
        setBusy(`assign:${format}`);

        try {
            const response = await window.axios.post(`/books/${bookId}/isbns`, {
                edition_format: format,
                ...body,
            });

            setData(response.data);
            setEntering(null);
            setEntry('');
        } catch (error) {
            onNotify?.(
                error.response?.data?.errors?.isbn?.[0]
                    ?? error.response?.data?.errors?.edition_format?.[0]
                    ?? t('rights.not_assigned'),
            );
        } finally {
            setBusy(null);
        }
    };

    const unassign = async (format, isbnId) => {
        setBusy(`unassign:${format}`);

        try {
            const response = await window.axios.delete(`/books/${bookId}/isbns/${isbnId}`);
            setData(response.data);
        } finally {
            setBusy(null);
        }
    };

    const addToPool = async (event) => {
        event.preventDefault();

        // Split on anything that is not part of an ISBN, so a pasted column
        // from a spreadsheet works as well as a comma-separated line.
        const numbers = poolDraft.split(/[^0-9Xx-]+/).filter(Boolean);

        if (numbers.length === 0) return;

        setBusy('pool');

        try {
            const response = await window.axios.post('/isbns', { isbns: numbers });

            setData((current) => ({ ...current, pool: response.data.pool }));
            setPoolDraft('');
            setShowPoolForm(false);

            if (response.data.rejected.length > 0) {
                // Partial success is reported rather than hidden: the author
                // needs to know which numbers did not go in.
                onNotify?.(
                    t('rights.added_some', { added: response.data.added, rejected: response.data.rejected.join('; ') }),
                );
            } else {
                onNotify?.(t('rights.added', { count: response.data.added }));
            }
        } finally {
            setBusy(null);
        }
    };

    const act = async (item) => {
        if (item.action === 'insert_copyright') {
            setBusy(`check:${item.key}`);

            try {
                const response = await window.axios.post(`/books/${bookId}/copyright-page`);
                setData(response.data);
                onNotify?.(t('rights.copyright_added'));
            } finally {
                setBusy(null);
            }

            return;
        }

        setBusy(`check:${item.key}`);

        try {
            const response = await window.axios.patch(`/books/${bookId}/rights`, {
                key: item.key,
                value: !item.done,
            });

            setData(response.data);
        } finally {
            setBusy(null);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { editions, pool, checklist, registration_url: registrationUrl } = data;
    const assigned = editions.filter((edition) => edition.isbn).length;
    const met = checklist.filter((item) => item.done).length;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">
                        {t('rights.title')}
                    </h2>
                    <p className="text-[11.5px] text-text-secondary">
                        {t('rights.summary', { assigned, total: editions.length, pool: pool.length })}
                    </p>
                </div>

                <button
                    data-a="rights:pool:open"
                    onClick={() => setShowPoolForm((open) => !open)}
                    className="ml-auto rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                >
                    {t('rights.add_isbns')}
                </button>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                {showPoolForm && (
                    <form
                        onSubmit={addToPool}
                        className="rounded-tile border border-border-default bg-bg-surface p-3.5"
                    >
                        <label className="block">
                            <span className="mb-1 block text-[12px] font-medium text-text-primary">
                                {t('rights.pool_label')}
                            </span>
                            <textarea
                                value={poolDraft}
                                rows={3}
                                autoFocus
                                data-a="rights:pool:input"
                                placeholder={t('rights.pool_placeholder')}
                                onChange={(event) => setPoolDraft(event.target.value)}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            />
                        </label>

                        <p className="mt-1 text-[11px] text-text-secondary">
                            {t('rights.pool_help')}
                        </p>

                        <div className="mt-2 flex gap-2">
                            <button
                                type="submit"
                                data-a="rights:pool:save"
                                disabled={busy === 'pool' || !poolDraft.trim()}
                                className="rounded-control bg-brand-fill px-2.5 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                            >
                                {busy === 'pool' ? t('rights.adding') : t('rights.add_to_pool')}
                            </button>
                            <button
                                type="button"
                                data-a="rights:pool:cancel"
                                onClick={() => setShowPoolForm(false)}
                                className="text-[12px] text-text-secondary"
                            >
                                {t('common.cancel')}
                            </button>
                        </div>
                    </form>
                )}

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                        {t('rights.by_edition')}
                    </h3>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-[12px]">
                            <thead>
                                <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                    <th className="py-1.5 pr-3 font-medium">{t('rights.edition')}</th>
                                    <th className="py-1.5 pr-3 font-medium">ISBN</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {editions.map((edition) => (
                                    <tr
                                        key={edition.format}
                                        className="border-b border-border-default last:border-0"
                                    >
                                        <td className="py-2 pr-3 text-text-primary">
                                            {edition.label}
                                        </td>
                                        <td className="py-2 pr-3">
                                            {edition.isbn ? (
                                                <code className="font-mono text-[11.5px] text-text-body">
                                                    {edition.isbn}
                                                </code>
                                            ) : (
                                                <span className="text-text-secondary">{t('rights.none')}</span>
                                            )}
                                        </td>
                                        <td className="py-2 text-right">
                                            {edition.isbn ? (
                                                <button
                                                    data-a={`rights:unassign:${edition.format}`}
                                                    disabled={busy !== null}
                                                    onClick={() =>
                                                        unassign(edition.format, edition.isbn_id)
                                                    }
                                                    className="rounded-control border border-border-input px-2 py-1 text-[11.5px] font-medium text-text-body transition hover:border-border-input/80 disabled:opacity-50"
                                                >
                                                    {t('rights.unassign')}
                                                </button>
                                            ) : (
                                                <span className="inline-flex flex-wrap justify-end gap-1.5">
                                                    {pool.length > 0 && (
                                                        <button
                                                            data-a={`rights:assign:${edition.format}`}
                                                            disabled={busy !== null}
                                                            onClick={() =>
                                                                assign(edition.format, {
                                                                    isbn_id: pool[0].id,
                                                                })
                                                            }
                                                            className="rounded-control bg-brand-fill px-2 py-1 text-[11.5px] font-semibold text-white disabled:opacity-50"
                                                        >
                                                            {t('rights.assign_from_pool')}
                                                        </button>
                                                    )}
                                                    <button
                                                        data-a={`rights:own:${edition.format}`}
                                                        onClick={() => {
                                                            setEntering(edition.format);
                                                            setEntry('');
                                                        }}
                                                        className="rounded-control border border-border-input px-2 py-1 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40"
                                                    >
                                                        {t('rights.enter_own')}
                                                    </button>
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {entering && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                assign(entering, { isbn: entry });
                            }}
                            className="mt-3 flex flex-wrap items-end gap-2 border-t border-border-default pt-3"
                        >
                            <label className="min-w-[200px] flex-1">
                                <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                                    {t('rights.isbn_for', { edition: editions.find((edition) => edition.format === entering)?.label ?? '' })}
                                </span>
                                <input
                                    value={entry}
                                    autoFocus
                                    required
                                    data-a="rights:own:input"
                                    placeholder="978…"
                                    onChange={(event) => setEntry(event.target.value)}
                                    className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                                />
                            </label>

                            <button
                                type="submit"
                                data-a="rights:own:save"
                                disabled={busy !== null}
                                className="rounded-control bg-brand-fill px-2.5 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                            >
                                {t('rights.save_isbn')}
                            </button>
                            <button
                                type="button"
                                data-a="rights:own:cancel"
                                onClick={() => setEntering(null)}
                                className="px-1 py-1.5 text-[12px] text-text-secondary"
                            >
                                {t('common.cancel')}
                            </button>
                        </form>
                    )}

                    <p className="mt-2.5 text-[11px] leading-relaxed text-text-secondary">
                        {t('rights.own_isbn_help')}
                    </p>
                </section>

                <PublishingDeclarations bookId={bookId} data={data} setData={setData} onNotify={onNotify} />

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                        {t('rights.checklist')}{' '}
                        <span className="font-normal text-text-secondary">
                            {t('rights.met_of', { met, total: checklist.length })}
                        </span>
                    </h3>

                    <ul>
                        {checklist.map((item) => (
                            <li
                                key={item.key}
                                className="flex items-center gap-2.5 border-b border-border-default py-2 last:border-0"
                            >
                                <span
                                    className={`flex h-[17px] w-[17px] shrink-0 items-center justify-center rounded-full ${
                                        item.done
                                            ? 'bg-status-success-fill text-white'
                                            : 'border border-border-input text-text-secondary'
                                    }`}
                                >
                                    {item.done && <Icon name="check" size={9} strokeWidth={3.6} />}
                                </span>

                                <span className="min-w-0 flex-1 text-[12.5px] leading-snug text-text-body">
                                    {item.label}
                                </span>

                                {item.action && (
                                    <button
                                        data-a={`rights:check:${item.key}`}
                                        disabled={busy === `check:${item.key}`}
                                        onClick={() => act(item)}
                                        className="shrink-0 rounded-control border border-border-input px-2 py-1 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50"
                                    >
                                        {item.done ? t('rights.undo') : item.action_label}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>

                    <p className="mt-2.5 text-[11.5px]">
                        <a
                            href={registrationUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-brand-primary hover:underline"
                        >
                            {t('rights.register')}
                        </a>
                        <span className="text-text-secondary">
                            {' '}{t('rights.register_help')}
                        </span>
                    </p>
                </section>
            </div>
        </div>
    );
}
