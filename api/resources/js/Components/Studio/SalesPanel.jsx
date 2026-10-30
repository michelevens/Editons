import ProductEditor from '@/Components/Store/ProductEditor';
import SalesReport from '@/Components/Store/SalesReport';
import { useI18n } from '@/i18n';
import { useCallback, useEffect, useState } from 'react';

/**
 * Sales & Distribution, and Royalties.
 *
 * There is no store reporting, so there are no sales figures. This says so
 * once, plainly, and then shows what is actually known: which stores have the
 * book, and what each would pay per copy at the current price.
 *
 * Direct sales are the exception: the author's own storefront takes real
 * orders, so its figures are real (`data.direct`, owner only). Distribution
 * carries the product editor; Royalties carries the sales report.
 *
 * `variant` picks which of the two menu items this is. They read the same data
 * and differ in emphasis — distribution is about where the book is, royalties
 * about what it earns.
 */
export default function SalesPanel({ bookId, variant = 'distribution' }) {
    const { t, formatNumber } = useI18n();
    const [data, setData] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/sales`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { channels, status, price, reporting, direct } = data;
    const royalties = variant === 'royalties';

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">
                    {royalties ? t('sales.royalties') : t('sales.sales_distribution')}
                </h2>
                <p className="text-[11.5px] text-text-secondary">
                    {status.live > 0
                        ? t('sales.live_in_stores', { count: status.live })
                        : status.in_review > 0
                          ? t('sales.stores_reviewing', { count: status.in_review })
                          : t('sales.not_on_sale_yet')}
                </p>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                <p
                    data-a="sales:reporting"
                    className="rounded-tile bg-status-info-bg px-3 py-2.5 text-[11.5px] leading-relaxed text-status-info"
                >
                    {reporting.message}
                </p>

                {!royalties && <ProductEditor bookId={bookId} onSaved={load} />}

                {royalties && direct && (
                    <section data-a="sales:direct" className="space-y-2">
                        <h3 className="text-[13px] font-semibold text-text-primary">
                            {t('sales.your_storefront')}
                        </h3>
                        <SalesReport report={direct} compact />
                    </section>
                )}

                {channels.length === 0 ? (
                    <section className="rounded-tile border border-border-default bg-bg-surface p-6 text-center">
                        <p className="text-[12.5px] text-text-secondary">
                            {t('sales.no_channels')}
                        </p>
                    </section>
                ) : (
                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                            {royalties ? t('sales.what_each_store_pays') : t('sales.where_the_book_is')}
                        </h3>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[12px]">
                                <thead>
                                    <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                        <th className="py-1.5 pr-3 font-medium">{t('sales.col_store')}</th>
                                        <th className="py-1.5 pr-3 font-medium">{t('sales.col_status')}</th>
                                        {royalties ? (
                                            <>
                                                <th className="py-1.5 pr-3 text-right font-medium">
                                                    {t('sales.col_your_share')}
                                                </th>
                                                <th className="py-1.5 pr-3 text-right font-medium">
                                                    {t('sales.col_per_copy')}
                                                </th>
                                            </>
                                        ) : (
                                            <>
                                                <th className="py-1.5 pr-3 font-medium">{t('sales.col_format')}</th>
                                                <th className="py-1.5 pr-3 text-right font-medium">
                                                    {t('sales.col_units')}
                                                </th>
                                            </>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {channels.map((channel) => (
                                        <tr
                                            key={channel.channel}
                                            className="border-b border-border-default last:border-0"
                                        >
                                            <td className="py-2 pr-3">
                                                <p className="text-text-primary">{channel.label}</p>
                                                {royalties && channel.note && (
                                                    <p className="text-[10.5px] leading-snug text-text-secondary">
                                                        {channel.note}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <Status status={channel.status} />
                                                {channel.external_id && (
                                                    <p data-a={`sales:asin:${channel.channel}`} className="text-[10.5px] text-text-secondary">
                                                        {channel.channel.startsWith('amazon') || channel.channel.startsWith('kdp') ? 'ASIN' : 'ID'} {channel.external_id}
                                                    </p>
                                                )}
                                            </td>

                                            {royalties ? (
                                                <>
                                                    <td className="py-2 pr-3 text-right tabular-nums text-text-body">
                                                        {channel.rate === null ? '—' : `${Math.round(channel.rate * 100)}%`}
                                                    </td>
                                                    <td className="py-2 pr-3 text-right tabular-nums text-text-body">
                                                        {channel.per_copy === null ? (
                                                            <span className="text-text-secondary" title={channel.note}>
                                                                {channel.channel === 'ingram_print'
                                                                    ? t('sales.see_note')
                                                                    : t('sales.set_a_price')}
                                                            </span>
                                                        ) : (
                                                            formatNumber(Number(channel.per_copy), { style: 'currency', currency: 'USD' })
                                                        )}
                                                    </td>
                                                </>
                                            ) : (
                                                <>
                                                    <td className="py-2 pr-3 text-text-body">
                                                        {channel.format === 'print'
                                                            ? t('sales.format_print')
                                                            : t('sales.format_ebook')}
                                                    </td>
                                                    <td className="py-2 pr-3 text-right tabular-nums text-text-secondary">
                                                        —
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {royalties && (
                            <p className="mt-2.5 text-[11px] leading-relaxed text-text-secondary">
                                {price
                                    ? t('sales.per_copy_explained', { price: formatNumber(Number(price), { style: 'currency', currency: 'USD' }) })
                                    : t('sales.set_list_price')}
                            </p>
                        )}
                    </section>
                )}
            </div>
        </div>
    );
}

function Status({ status }) {
    const { t } = useI18n();
    const look = {
        live: ['bg-status-success-bg', 'text-status-success', t('sales.status_live')],
        in_review: ['bg-status-warning-bg', 'text-status-warning', t('sales.status_in_review')],
        ready_to_submit: ['bg-status-warning-bg', 'text-status-warning', t('sales.status_to_upload')],
        unpublished: ['bg-bg-subtle', 'text-text-secondary', t('sales.status_off_sale')],
        not_submitted: ['bg-bg-subtle', 'text-text-secondary', t('sales.status_not_submitted')],
    }[status] ?? ['bg-bg-subtle', 'text-text-secondary', status];

    return (
        <span
            className={`inline-block rounded-badge px-1.5 py-0.5 text-[10.5px] font-semibold ${look[0]} ${look[1]}`}
        >
            {look[2]}
        </span>
    );
}
