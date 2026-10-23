import { useCallback, useEffect, useState } from 'react';

/**
 * Sales & Distribution, and Royalties.
 *
 * There is no store reporting, so there are no sales figures. This says so
 * once, plainly, and then shows what is actually known: which stores have the
 * book, and what each would pay per copy at the current price.
 *
 * `variant` picks which of the two menu items this is. They read the same data
 * and differ in emphasis — distribution is about where the book is, royalties
 * about what it earns.
 */
export default function SalesPanel({ bookId, variant = 'distribution' }) {
    const [data, setData] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/sales`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { channels, status, price, reporting } = data;
    const royalties = variant === 'royalties';

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="border-b border-border-default px-4 py-3">
                <h2 className="text-[15px] font-semibold text-text-primary">
                    {royalties ? 'Royalties' : 'Sales & Distribution'}
                </h2>
                <p className="text-[11.5px] text-text-secondary">
                    {status.live > 0
                        ? `Live in ${status.live} store${status.live === 1 ? '' : 's'}`
                        : status.in_review > 0
                          ? `${status.in_review} store${status.in_review === 1 ? '' : 's'} reviewing`
                          : 'Not on sale yet'}
                </p>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                <p
                    data-a="sales:reporting"
                    className="rounded-tile bg-status-info-bg px-3 py-2.5 text-[11.5px] leading-relaxed text-status-info"
                >
                    {reporting.message}
                </p>

                {channels.length === 0 ? (
                    <section className="rounded-tile border border-border-default bg-bg-surface p-6 text-center">
                        <p className="text-[12.5px] text-text-secondary">
                            Choose stores on the Export &amp; Publish screen, and they will appear
                            here with their terms.
                        </p>
                    </section>
                ) : (
                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                            {royalties ? 'What each store pays' : 'Where the book is'}
                        </h3>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[12px]">
                                <thead>
                                    <tr className="border-b border-border-default text-[11px] uppercase tracking-wide text-text-secondary">
                                        <th className="py-1.5 pr-3 font-medium">Store</th>
                                        <th className="py-1.5 pr-3 font-medium">Status</th>
                                        {royalties ? (
                                            <>
                                                <th className="py-1.5 pr-3 text-right font-medium">
                                                    Your share
                                                </th>
                                                <th className="py-1.5 pr-3 text-right font-medium">
                                                    Per copy
                                                </th>
                                            </>
                                        ) : (
                                            <>
                                                <th className="py-1.5 pr-3 font-medium">Format</th>
                                                <th className="py-1.5 pr-3 text-right font-medium">
                                                    Units
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
                                            </td>

                                            {royalties ? (
                                                <>
                                                    <td className="py-2 pr-3 text-right tabular-nums text-text-body">
                                                        {Math.round(channel.rate * 100)}%
                                                    </td>
                                                    <td className="py-2 pr-3 text-right tabular-nums text-text-body">
                                                        {channel.per_copy === null ? (
                                                            <span className="text-text-secondary">
                                                                set a price
                                                            </span>
                                                        ) : (
                                                            `$${Number(channel.per_copy).toFixed(2)}`
                                                        )}
                                                    </td>
                                                </>
                                            ) : (
                                                <>
                                                    <td className="py-2 pr-3 text-text-body">
                                                        {channel.format === 'print'
                                                            ? 'Print'
                                                            : 'eBook'}
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
                                    ? `Per-copy figures are the store's published rate applied to your $${price} list price. Delivery and print costs come off the top at some stores.`
                                    : 'Set a list price on Export & Publish to see what each store would pay.'}
                            </p>
                        )}
                    </section>
                )}
            </div>
        </div>
    );
}

function Status({ status }) {
    const look = {
        live: ['bg-status-success-bg', 'text-status-success', 'Live'],
        in_review: ['bg-status-warning-bg', 'text-status-warning', 'In review'],
        ready_to_submit: ['bg-status-warning-bg', 'text-status-warning', 'To upload'],
        unpublished: ['bg-bg-subtle', 'text-text-secondary', 'Off sale'],
        not_submitted: ['bg-bg-subtle', 'text-text-secondary', 'Not submitted'],
    }[status] ?? ['bg-bg-subtle', 'text-text-secondary', status];

    return (
        <span
            className={`inline-block rounded-badge px-1.5 py-0.5 text-[10.5px] font-semibold ${look[0]} ${look[1]}`}
        >
            {look[2]}
        </span>
    );
}
