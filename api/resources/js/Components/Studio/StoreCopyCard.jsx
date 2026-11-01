import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n';

const FIELDS = [
    { key: 'blurb', tKey: 'storecopy.blurb', rows: 6 },
    { key: 'short', tKey: 'storecopy.short', rows: 2 },
    { key: 'long', tKey: 'storecopy.long', rows: 8 },
    { key: 'keywords', tKey: 'storecopy.keywords' },
    { key: 'categories', tKey: 'storecopy.categories' },
];

/**
 * AI blurb and store copy (ADR — AI research, source-backed fact-checking,
 * cover templates). The AI writes a draft; the author edits and approves
 * each field, and only an approved field is saved: the blurb to the back
 * cover, the descriptions, keywords and categories to the listing.
 */
export default function StoreCopyCard({ bookId, onNotify, onChanged }) {
    const { t } = useI18n();
    const [data, setData] = useState(null);
    const [edits, setEdits] = useState({});
    const [busy, setBusy] = useState(null);

    const load = async () => {
        const res = await window.axios.get(`/books/${bookId}/store-copy`);
        setData(res.data);
    };

    useEffect(() => {
        load();
    }, [bookId]);

    const write = async () => {
        setBusy('write');

        try {
            const res = await window.axios.post(`/books/${bookId}/store-copy`);
            setData(res.data);
            setEdits({});
        } catch (err) {
            onNotify?.(err.response?.data?.message ?? err.response?.data?.errors?.credits?.[0] ?? t('storecopy.failed'));
        } finally {
            setBusy(null);
        }
    };

    const valueOf = (key) => edits[key] ?? data?.draft?.fields?.[key] ?? (key === 'keywords' || key === 'categories' ? [] : '');

    const approve = async (key) => {
        setBusy(key);

        try {
            const res = await window.axios.post(`/books/${bookId}/store-copy/approve`, { field: key, value: valueOf(key) });
            setData(res.data);
            onNotify?.(t('storecopy.saved'));
            onChanged?.();
        } catch (err) {
            const errors = err.response?.data?.errors ?? {};
            onNotify?.(Object.values(errors)[0]?.[0] ?? err.response?.data?.message ?? t('storecopy.failed'));
        } finally {
            setBusy(null);
        }
    };

    if (!data) return <p className="text-[12px] text-text-secondary">{t('common.loading')}</p>;

    const draft = data.draft;

    return (
        <div data-a="storecopy" className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    data-a="storecopy:write"
                    disabled={busy !== null}
                    onClick={write}
                    className="min-h-[34px] rounded-control bg-brand-fill px-3 text-[12px] font-semibold text-white disabled:opacity-50"
                >
                    {busy === 'write' ? t('storecopy.writing') : draft ? t('storecopy.rewrite') : t('storecopy.write')}
                </button>
                <span className="text-[11px] text-text-secondary">
                    {data.simulated ? t('storecopy.sample_note') : t('storecopy.cost_note', { credits: data.hold })}
                </span>
            </div>

            {draft?.simulated && (
                <p className="rounded bg-status-warning-bg px-2 py-1 text-[11px] font-semibold text-status-warning">{t('storecopy.sample_notice')}</p>
            )}

            {draft && FIELDS.map((field) => {
                const value = valueOf(field.key);
                const approved = draft.approved?.[field.key];

                return (
                    <div key={field.key} data-a={`storecopy:field:${field.key}`} className="rounded-tile border border-border-default p-2.5">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-[12px] font-semibold text-text-primary">{t(field.tKey)}</p>
                            {approved && <span className="text-[10.5px] font-semibold text-status-success">{t('storecopy.approved')}</span>}
                        </div>

                        {field.key === 'categories' ? (
                            <select
                                multiple
                                value={value}
                                aria-label={t(field.tKey)}
                                onChange={(e) => setEdits({ ...edits, categories: [...e.target.selectedOptions].map((o) => o.value).slice(0, 3) })}
                                className="mt-1.5 w-full rounded-control border-border-input text-[12px]"
                            >
                                {data.categories.map((c) => <option key={c} value={c}>{c}</option>)}
                            </select>
                        ) : field.key === 'keywords' ? (
                            <input
                                value={value.join(', ')}
                                aria-label={t(field.tKey)}
                                onChange={(e) => setEdits({ ...edits, keywords: e.target.value.split(',').map((k) => k.trim()).filter(Boolean) })}
                                className="mt-1.5 w-full rounded-control border-border-input text-[12px]"
                            />
                        ) : (
                            <textarea
                                value={value}
                                rows={field.rows}
                                aria-label={t(field.tKey)}
                                onChange={(e) => setEdits({ ...edits, [field.key]: e.target.value })}
                                className="mt-1.5 w-full rounded-control border-border-input text-[12px] leading-relaxed"
                            />
                        )}

                        <button
                            type="button"
                            data-a={`storecopy:approve:${field.key}`}
                            disabled={busy !== null}
                            onClick={() => approve(field.key)}
                            className="mt-1.5 text-[11.5px] font-semibold text-brand-primary hover:underline disabled:opacity-50"
                        >
                            {approved ? t('storecopy.approve_again') : t('storecopy.approve')}
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
