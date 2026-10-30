import BookCard from '@/Components/Store/BookCard';
import { money } from '@/Components/Store/money';
import StoreLayout from '@/Layouts/StoreLayout';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';

/**
 * One book's storefront page. Public: no sign-in to look or to buy.
 *
 * The description is plain text rendered as text — never as HTML — so
 * whatever an author pasted cannot run in a reader's browser. The author
 * line appears only when the book carries an author name.
 */
export default function Product({ product, series, partner = null }) {
    const { t, formatDate } = useI18n();
    const offers = product.offers ?? [];
    const { data, setData, post, transform, processing, errors } = useForm({
        email: '',
        code: '',
        edition: 'digital',
        offer: offers.find((o) => o.offer === 'all')?.offer ?? offers[0]?.offer ?? 'all',
    });
    const chosen = offers.find((o) => o.offer === data.offer) ?? offers[0];
    const offerLabel = (offer) =>
        offer.offer === 'all'
            ? t('store.offer_all', { formats: product.formats.join(' + ') })
            : offer.formats.map((f) => f.toUpperCase()).join(' + ');

    const buy = (event) => {
        event.preventDefault();
        post(product.checkout_url);
    };

    // The paperback (printed and posted by Lulu): same email, no code.
    const buyPaperback = () => {
        transform((form) => ({ email: form.email, edition: 'paperback' }));
        post(product.checkout_url, { onFinish: () => transform((form) => form) });
    };

    return (
        <StoreLayout report={{ url: product.report_url, label: 'Report this book' }}>
            <Head title={product.title} />

            <div className="grid grid-cols-1 gap-8 md:grid-cols-[minmax(0,300px)_1fr]">
                <img
                    src={product.cover_url}
                    alt={`Cover of ${product.title}`}
                    className="aspect-[2/3] w-full max-w-[300px] rounded-panel bg-bg-subtle object-cover shadow-[0_24px_50px_-32px_rgba(15,23,42,0.45)]"
                />

                <div className="min-w-0">
                    {product.series_name && (
                        <p className="text-[12px] font-semibold uppercase tracking-[0.12em] text-brand-primary">
                            {product.series_name}
                            {product.series_number ? ` · Book ${product.series_number}` : ''}
                        </p>
                    )}

                    <h1
                        data-a="store:title"
                        className="mt-1 font-display text-[clamp(26px,4vw,38px)] font-extrabold leading-tight tracking-[-0.03em] text-text-primary"
                    >
                        {product.title}
                    </h1>

                    {product.subtitle && (
                        <p className="mt-1 text-[16px] text-text-secondary">{product.subtitle}</p>
                    )}

                    {product.author && (
                        <p data-a="store:author" className="mt-3 text-[14px] text-text-body">
                            by{' '}
                            {product.author_url ? (
                                <a href={product.author_url} className="font-semibold hover:underline">
                                    {product.author}
                                </a>
                            ) : (
                                <span className="font-semibold">{product.author}</span>
                            )}
                        </p>
                    )}

                    {partner && (
                        <p data-a="store:partner" className="mt-4 rounded-tile border border-border-default bg-bg-surface px-3 py-2 text-[12.5px] text-text-body">
                            Recommended by{' '}
                            <a href={partner.url} className="font-semibold text-brand-primary hover:underline">
                                {partner.name}
                            </a>
                            . <span className="text-text-secondary">{partner.disclosure}</span>
                        </p>
                    )}

                    <form
                        onSubmit={buy}
                        className="mt-6 rounded-panel border border-border-default bg-bg-surface p-4"
                    >
                        {/* Each format at its own price when the author set
                            them (App\Commerce\Pricing); a sale shows the usual
                            price struck through. */}
                        {offers.length > 1 ? (
                            <fieldset data-a="store:offers" className="space-y-2">
                                <legend className="mb-1 text-[12px] text-text-secondary">{t('store.choose_format')}</legend>
                                {offers.map((offer) => (
                                    <label
                                        key={offer.offer}
                                        className={`flex cursor-pointer flex-wrap items-baseline gap-x-2 rounded-input border px-3 py-2 ${data.offer === offer.offer ? 'border-brand-primary bg-brand-primary/5' : 'border-border-default'}`}
                                    >
                                        <input
                                            type="radio"
                                            name="offer"
                                            data-a={`store:offer:${offer.offer}`}
                                            value={offer.offer}
                                            checked={data.offer === offer.offer}
                                            onChange={() => setData('offer', offer.offer)}
                                            className="self-center"
                                        />
                                        <span className="text-[13.5px] font-semibold text-text-primary">{offerLabel(offer)}</span>
                                        <Price offer={offer} currency={product.currency} t={t} formatDate={formatDate} />
                                    </label>
                                ))}
                                <p className="text-[12.5px] text-text-secondary">
                                    {t('store.license_line', { license: product.license.label })}
                                </p>
                            </fieldset>
                        ) : (
                            <p className="flex flex-wrap items-baseline gap-2">
                                <span
                                    data-a="store:price"
                                    className="font-display text-[28px] font-extrabold tabular-nums text-text-primary"
                                >
                                    {money(chosen?.price_cents ?? product.price_cents, product.currency)}
                                </span>
                                {chosen?.list_cents && (
                                    <SaleNote offer={chosen} currency={product.currency} t={t} formatDate={formatDate} />
                                )}
                                <span className="text-[12.5px] text-text-secondary">
                                    {product.formats.join(' + ')} · {product.license.label} license
                                </span>
                            </p>
                        )}
                        {product.preorder && (
                            <p data-a="store:preorder" className="mt-2 rounded-input bg-bg-subtle px-2.5 py-1.5 text-[12.5px] font-medium text-text-primary">
                                {t('store.preorder_note', { date: formatDate(product.preorder.release_at, { dateStyle: 'long' }) })}
                            </p>
                        )}

                        <label className="mt-3 block text-[12px] text-text-secondary" htmlFor="buyer-email">
                            Email for your receipt and downloads (optional — Stripe asks if you skip it)
                        </label>
                        <input
                            id="buyer-email"
                            type="email"
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 w-full max-w-[360px] rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] text-text-primary"
                        />
                        {errors.email && <p className="mt-1 text-[12px] text-status-danger">{errors.email}</p>}

                        <label className="mt-3 block text-[12px] text-text-secondary" htmlFor="discount-code">
                            Discount code (optional)
                        </label>
                        <input
                            id="discount-code"
                            data-a="store:code"
                            autoComplete="off"
                            autoCapitalize="characters"
                            maxLength={40}
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            aria-invalid={errors.code ? 'true' : undefined}
                            aria-describedby={errors.code ? 'discount-code-error' : undefined}
                            className="mt-1 w-full max-w-[220px] rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] uppercase text-text-primary"
                        />
                        {errors.code && (
                            <p id="discount-code-error" data-a="store:code-error" className="mt-1 text-[12px] text-status-danger">
                                {errors.code}
                            </p>
                        )}
                        {errors.checkout && (
                            <p className="mt-1 text-[12px] text-status-danger">{errors.checkout}</p>
                        )}

                        <button
                            data-a="store:buy"
                            type="submit"
                            disabled={processing}
                            className="mt-3 block rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                        >
                            {processing ? 'Opening checkout…' : product.preorder ? t('store.preorder_buy') : 'Buy now'}
                        </button>

                        <p className="mt-2 text-[11.5px] leading-relaxed text-text-secondary">
                            {product.license.terms}
                        </p>

                        {/* "Read a sample": the author's Sample file, by a signed,
                            expiring link (App\Commerce\Sample). */}
                        {product.samples && Object.keys(product.samples).length > 0 && (
                            <p data-a="store:sample" className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
                                <span className="font-semibold text-text-primary">Read a sample</span>
                                {Object.entries(product.samples).map(([format, sample]) => (
                                    <a
                                        key={format}
                                        href={sample.url}
                                        data-a={`store:sample-${format}`}
                                        rel="nofollow"
                                        className="font-medium text-brand-primary underline-offset-2 hover:underline"
                                    >
                                        {sample.label}
                                    </a>
                                ))}
                            </p>
                        )}

                        {product.paperback && (
                            <div data-a="store:paperback" className="mt-4 border-t border-border-default pt-4">
                                <p className="flex flex-wrap items-baseline gap-2">
                                    <span className="font-display text-[22px] font-extrabold tabular-nums text-text-primary">
                                        {money(product.paperback.price_cents, product.currency)}
                                    </span>
                                    {product.paperback.list_cents && (
                                        <s className="text-[14px] tabular-nums text-text-secondary">
                                            {money(product.paperback.list_cents, product.currency)}
                                        </s>
                                    )}
                                    <span className="text-[12.5px] text-text-secondary">
                                        Paperback · + {money(product.paperback.shipping_cents, product.currency)} shipping
                                        {' '}to {product.paperback.countries.join(', ')}
                                    </span>
                                </p>
                                <button
                                    type="button"
                                    data-a="store:buy-paperback"
                                    disabled={processing}
                                    onClick={buyPaperback}
                                    className="mt-2 rounded-button border border-brand-primary px-5 py-2.5 text-[14px] font-semibold text-brand-primary hover:bg-brand-primary/5 disabled:opacity-60"
                                >
                                    Buy the paperback
                                </button>
                                <p className="mt-2 text-[11.5px] leading-relaxed text-text-secondary">
                                    Printed on demand and posted to you. You give your address and phone on the payment page.
                                </p>
                            </div>
                        )}
                    </form>

                    {product.description && (
                        <section className="mt-7">
                            <h2 className="mb-2 text-[15px] font-semibold text-text-primary">About this book</h2>
                            {/* Text, not HTML: React escapes it, and line breaks are kept by CSS. */}
                            <p
                                data-a="store:description"
                                className="whitespace-pre-line text-[14.5px] leading-relaxed text-text-body"
                            >
                                {product.description}
                            </p>
                        </section>
                    )}
                </div>
            </div>

            {series && series.others.length > 0 && (
                <section className="mt-12">
                    <h2 className="mb-3 text-[15px] font-semibold text-text-primary">
                        More in {series.name}
                    </h2>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {series.others.map((other) => (
                            <BookCard key={other.slug} product={other} />
                        ))}
                    </div>
                </section>
            )}
        </StoreLayout>
    );
}

/** An offer's price, with the usual one struck through during a sale. */
function Price({ offer, currency, t, formatDate }) {
    return (
        <span className="ml-auto flex flex-wrap items-baseline gap-x-2">
            <span data-a={`store:offer-price:${offer.offer}`} className="text-[16px] font-extrabold tabular-nums text-text-primary">
                {money(offer.price_cents, currency)}
            </span>
            {offer.list_cents && <SaleNote offer={offer} currency={currency} t={t} formatDate={formatDate} />}
        </span>
    );
}

function SaleNote({ offer, currency, t, formatDate }) {
    return (
        <>
            <s data-a="store:list-price" className="text-[13px] tabular-nums text-text-secondary">
                <span className="sr-only">{t('store.usual_price')} </span>
                {money(offer.list_cents, currency)}
            </s>
            {offer.sale_ends_at && (
                <span className="text-[11.5px] font-semibold text-status-success">
                    {t('store.sale_ends', { date: formatDate(offer.sale_ends_at, { dateStyle: 'medium', timeStyle: 'short' }) })}
                </span>
            )}
        </>
    );
}
