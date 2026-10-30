import { useCallback, useEffect, useState } from 'react';
import { t, useI18n } from '../../i18n';
import Icon from './Icon';

/**
 * Accessibility.
 *
 * Every check is computed from the manuscript, so the list changes as the
 * book does. Each one says why it matters: a checklist that only says
 * "failed" teaches nobody what to do differently next time.
 */
export default function AccessibilityPanel({ bookId, onOpenSection, onNotify }) {
    useI18n();
    const [data, setData] = useState(null);
    const [drafts, setDrafts] = useState({});
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/accessibility`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const describe = async (image) => {
        const key = `${image.section_id}:${image.index}`;
        setBusy(key);

        try {
            const response = await window.axios.patch(
                `/sections/${image.section_id}/image-description`,
                {
                    index: image.index,
                    alt: drafts[key] ?? image.alt,
                    // What the client believed it was describing, so a stale
                    // view cannot write a caption onto the wrong figure.
                    src: image.src,
                },
            );

            setData(response.data);
            setDrafts((current) => ({ ...current, [key]: undefined }));
        } catch (error) {
            onNotify?.(
                error.response?.data?.message ?? t('a11y.not_saved'),
            );
        } finally {
            setBusy(null);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { checks, images } = data;
    const passed = checks.filter((check) => check.passed).length;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">{t('a11y.title')}</h2>
                <p className="text-[11.5px] text-text-secondary">
                    {t('a11y.passing', { passed, total: checks.length })}
                </p>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">{t('a11y.checks')}</h3>

                    <ul>
                        {checks.map((check) => (
                            <li
                                key={check.key}
                                data-a={`a11y:check:${check.key}`}
                                className="flex gap-2.5 border-b border-border-default py-2.5 last:border-0"
                            >
                                <span
                                    className={`mt-0.5 flex h-[17px] w-[17px] shrink-0 items-center justify-center rounded-full text-[10px] font-bold ${
                                        check.passed
                                            ? 'bg-status-success-fill text-white'
                                            : 'bg-status-warning-fill text-white'
                                    }`}
                                >
                                    {check.passed ? (
                                        <Icon name="check" size={9} strokeWidth={3.6} />
                                    ) : (
                                        '!'
                                    )}
                                </span>

                                <div className="min-w-0">
                                    <p className="text-[12.5px] font-medium text-text-primary">
                                        {check.label}
                                    </p>
                                    <p
                                        className={`text-[11.5px] ${
                                            check.passed
                                                ? 'text-text-secondary'
                                                : 'font-medium text-status-warning'
                                        }`}
                                    >
                                        {check.detail}
                                    </p>
                                    <p className="mt-0.5 text-[11px] leading-snug text-text-secondary">
                                        {check.why}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>

                    <p className="mt-3 rounded bg-bg-subtle px-2.5 py-2 text-[11px] leading-relaxed text-text-secondary">
                        {t('a11y.checks_note')}
                    </p>
                </section>

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-1 text-[13px] font-semibold text-text-primary">
                        {t('a11y.images')}
                    </h3>

                    {images.length === 0 ? (
                        <p className="text-[11.5px] text-text-secondary">
                            {t('a11y.no_images')}
                        </p>
                    ) : (
                        <>
                            <p className="mb-2.5 text-[11px] leading-relaxed text-text-secondary">
                                {t('a11y.describe_hint')}
                            </p>

                            <ul className="space-y-2.5">
                                {images.map((image) => {
                                    const key = `${image.section_id}:${image.index}`;
                                    const draft = drafts[key] ?? image.alt;

                                    return (
                                        <li
                                            key={key}
                                            className="flex gap-2.5 border-b border-border-default pb-2.5 last:border-0"
                                        >
                                            <img
                                                src={image.src}
                                                alt=""
                                                className="h-[52px] w-[52px] shrink-0 rounded border border-border-default object-cover"
                                            />

                                            <div className="min-w-0 flex-1">
                                                <button
                                                    data-a={`a11y:open:${image.section_id}`}
                                                    onClick={() => onOpenSection?.(image.section_id)}
                                                    className="text-[11px] text-brand-primary hover:underline"
                                                >
                                                    {image.section_title}
                                                </button>

                                                <input
                                                    value={draft}
                                                    data-a={`a11y:alt:${key}`}
                                                    placeholder={t('a11y.describe_placeholder')}
                                                    onChange={(event) =>
                                                        setDrafts((current) => ({
                                                            ...current,
                                                            [key]: event.target.value,
                                                        }))
                                                    }
                                                    className={`mt-1 w-full rounded-control border px-2 py-1.5 text-[12px] focus:ring-0 ${
                                                        image.alt.trim()
                                                            ? 'border-border-input focus:border-brand-primary'
                                                            : 'border-status-warning/50 focus:border-status-warning'
                                                    }`}
                                                />
                                            </div>

                                            <button
                                                data-a={`a11y:save:${key}`}
                                                disabled={busy === key || draft === image.alt}
                                                onClick={() => describe(image)}
                                                className="mt-[22px] shrink-0 self-start rounded-control border border-border-input px-2.5 py-1.5 text-[11.5px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-40"
                                            >
                                                {busy === key ? t('a11y.saving') : t('a11y.save')}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </>
                    )}
                </section>
            </div>
        </div>
    );
}
