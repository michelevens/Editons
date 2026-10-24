import BookCard from '@/Components/Store/BookCard';
import { money } from '@/Components/Store/money';
import StoreLayout from '@/Layouts/StoreLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * One book's storefront page. Public: no sign-in to look or to buy.
 *
 * The description is plain text rendered as text — never as HTML — so
 * whatever an author pasted cannot run in a reader's browser. The author
 * line appears only when the book carries an author name.
 */
export default function Product({ product, series }) {
    const { data, setData, post, processing, errors } = useForm({ email: '', code: '' });

    const buy = (event) => {
        event.preventDefault();
        post(product.checkout_url);
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

                    <form
                        onSubmit={buy}
                        className="mt-6 rounded-panel border border-border-default bg-bg-surface p-4"
                    >
                        <p className="flex flex-wrap items-baseline gap-2">
                            <span
                                data-a="store:price"
                                className="font-display text-[28px] font-extrabold tabular-nums text-text-primary"
                            >
                                {money(product.price_cents, product.currency)}
                            </span>
                            <span className="text-[12.5px] text-text-secondary">
                                {product.formats.join(' + ')} · {product.license.label} license
                            </span>
                        </p>

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
                            {processing ? 'Opening checkout…' : 'Buy now'}
                        </button>

                        <p className="mt-2 text-[11.5px] leading-relaxed text-text-secondary">
                            {product.license.terms}
                        </p>
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
