import { useCallback, useEffect, useState } from 'react';
import { t, useI18n } from '../../i18n';
import Icon from './Icon';

/**
 * The Marketing Kit.
 *
 * The copy is assembled from the author's own sentences rather than written
 * fresh — this goes out under their name to people deciding whether to buy,
 * and an invented claim in a store listing is their problem, not ours. The
 * screen says so, because an author should know what they are about to send.
 */
export default function MarketingPanel({ bookId, onNotify }) {
    useI18n();
    const [data, setData] = useState(null);
    const [audience, setAudience] = useState('');
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/marketing-kit`);
        setData(response.data);
        setAudience(response.data.kit?.audience ?? '');
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const generate = async () => {
        setBusy(true);

        try {
            const response = await window.axios.post(`/books/${bookId}/marketing-kit`, {
                audience: audience || null,
            });

            setData(response.data);
            setAudience(response.data.kit?.audience ?? '');
        } catch (error) {
            onNotify?.(
                error.response?.data?.errors?.credits?.[0]
                    ?? error.response?.data?.message
                    ?? t('marketing.not_generated'),
            );
        } finally {
            setBusy(false);
        }
    };

    const copy = async (key, text) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(key);
            setTimeout(() => setCopied(null), 1600);
        } catch {
            onNotify?.(t('marketing.clipboard'));
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { kit, cost, has_chapters: hasChapters } = data;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">{t('marketing.title')}</h2>
                    <p className="text-[11.5px] text-text-secondary">
                        {t('marketing.subtitle')}
                    </p>
                </div>

                <button
                    data-a="kit:generate"
                    disabled={busy || !hasChapters}
                    onClick={generate}
                    className="ml-auto rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy ? t('marketing.building') : kit ? t('marketing.rebuild_cost', { cost }) : t('marketing.build_cost', { cost })}
                </button>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                <label className="block rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <span className="mb-1 block text-[12px] font-medium text-text-primary">
                        {t('marketing.audience')}
                    </span>
                    <input
                        value={audience}
                        data-a="kit:audience"
                        placeholder={t('marketing.audience_placeholder')}
                        onChange={(event) => setAudience(event.target.value)}
                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                    />
                </label>

                {!hasChapters && (
                    <p className="rounded-tile bg-status-warning-bg px-3 py-2 text-[11.5px] text-status-warning">
                        {t('marketing.no_chapters')}
                    </p>
                )}

                {kit ? (
                    <>
                        <Block
                            title={t('marketing.description')}
                            dataA="kit:description"
                            copied={copied === 'description'}
                            onCopy={() => copy('description', kit.description)}
                        >
                            <p className="whitespace-pre-wrap font-manuscript text-[13px] leading-relaxed text-text-body">
                                {kit.description}
                            </p>
                        </Block>

                        <Block
                            title={t('marketing.email')}
                            dataA="kit:email"
                            copied={copied === 'email'}
                            onCopy={() => copy('email', `${kit.email.subject}\n\n${kit.email.body}`)}
                        >
                            <p className="text-[12px] text-text-primary">
                                <b>{t('marketing.subject')}</b> {kit.email.subject}
                            </p>
                            <p className="mt-1.5 whitespace-pre-wrap font-manuscript text-[13px] leading-relaxed text-text-body">
                                {kit.email.body}
                            </p>
                        </Block>

                        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                            <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                                {t('marketing.posts')}
                            </h3>

                            <ul className="space-y-2">
                                {kit.posts.map((post, index) => (
                                    <li
                                        key={index}
                                        className="rounded-tile border border-border-default p-2.5"
                                    >
                                        <p className="whitespace-pre-wrap text-[12.5px] leading-relaxed text-text-body">
                                            {post}
                                        </p>
                                        <div className="mt-1.5 flex items-center gap-3">
                                            <span className="text-[10.5px] text-text-secondary">
                                                {t('marketing.characters', { count: post.length })}
                                            </span>
                                            <button
                                                data-a={`kit:copy-post:${index}`}
                                                onClick={() => copy(`post:${index}`, post)}
                                                className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                            >
                                                {copied === `post:${index}` ? t('marketing.copied') : t('marketing.copy')}
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <p className="rounded-tile bg-bg-subtle px-3 py-2.5 text-[11px] leading-relaxed text-text-secondary">
                            {t('marketing.drawn_from_you')}
                        </p>
                    </>
                ) : (
                    hasChapters && (
                        <div className="rounded-tile border border-border-default bg-bg-surface p-8 text-center">
                            <span className="inline-flex text-text-secondary">
                                <Icon name="mega" size={30} strokeWidth={1.5} />
                            </span>
                            <p className="mt-2 text-[13px] font-medium text-text-primary">
                                {t('marketing.empty_title')}
                            </p>
                            <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                                {t('marketing.empty_body')}
                            </p>
                        </div>
                    )
                )}
            </div>
        </div>
    );
}

function Block({ title, children, onCopy, copied, dataA }) {
    return (
        <section
            data-a={dataA}
            className="rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">{title}</h3>
                <button
                    onClick={onCopy}
                    className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                >
                    {copied ? t('marketing.copied') : t('marketing.copy')}
                </button>
            </div>
            {children}
        </section>
    );
}
