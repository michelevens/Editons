import { money } from '@/Components/Store/money';

/**
 * An order as its buyer sees it: items, total, and — when the server judged
 * this viewer to be the buyer — license keys and signed download links.
 */
export default function OrderSummary({ order }) {
    return (
        <section
            data-a="store:order"
            className="rounded-panel border border-border-default bg-bg-surface p-4"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-[13px] font-semibold text-text-primary">Order {order.number}</p>
                <p className="text-[12px] text-text-secondary">
                    {order.paid_at ?? 'Awaiting payment'} · {money(order.total_cents, order.currency)}
                    {order.shipping_cents > 0 && ` incl. ${money(order.shipping_cents, order.currency)} shipping`}
                    {order.refunded_cents > 0 && ` · ${money(order.refunded_cents, order.currency)} refunded`}
                </p>
            </div>

            <ul className="mt-3 space-y-3">
                {order.items.map((item) => (
                    <li key={item.title} className="border-t border-border-default pt-3">
                        <p className="text-[14px] font-semibold text-text-primary">{item.title}</p>
                        <p className="text-[12px] text-text-secondary">
                            {item.formats.join(' + ')} · {money(item.price_cents, order.currency)}
                        </p>

                        {item.shipment && (
                            <p data-a="store:shipment" className="mt-1 text-[12px] text-text-body">
                                {SHIPMENT[item.shipment.status] ?? 'Being printed'}
                                {item.shipment.tracking_url && (
                                    <>
                                        {' · '}
                                        <a href={item.shipment.tracking_url} className="font-semibold text-brand-primary hover:underline" target="_blank" rel="noopener noreferrer">
                                            Track it
                                        </a>
                                    </>
                                )}
                            </p>
                        )}

                        {item.downloads.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {item.downloads.map((file) => (
                                    <a
                                        key={file.format}
                                        data-a={`store:download:${file.format}`}
                                        href={file.url}
                                        className="rounded-button bg-brand-primary px-3 py-1.5 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover"
                                    >
                                        Download {file.label}
                                    </a>
                                ))}
                            </div>
                        )}

                        {item.license_key && (
                            <p className="mt-2 text-[11.5px] text-text-secondary">
                                License <span className="font-mono text-text-body">{item.license_key}</span>
                                {item.license_terms ? ` — ${item.license_terms}` : ''}
                            </p>
                        )}
                    </li>
                ))}
            </ul>

            {order.links_expire_at && (
                <p className="mt-3 text-[11.5px] text-text-secondary">
                    These links work until {order.links_expire_at}.
                </p>
            )}
        </section>
    );
}

/** A paperback's print job, in a buyer's words (Lulu's status names). */
const SHIPMENT = {
    pending: 'Sent to the printer soon',
    failed: 'Waiting for the printer',
    CREATED: 'At the printer',
    UNPAID: 'At the printer',
    PAYMENT_IN_PROGRESS: 'At the printer',
    PRODUCTION_DELAYED: 'At the printer',
    PRODUCTION_READY: 'Being printed',
    IN_PRODUCTION: 'Being printed',
    SHIPPED: 'Shipped',
    DELIVERED: 'Delivered',
    REJECTED: 'The printer could not print it: we will be in touch',
    CANCELED: 'Cancelled at the printer: we will be in touch',
    ERROR: 'The printer could not print it: we will be in touch',
};
