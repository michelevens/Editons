import BookCard from '@/Components/Store/BookCard';
import { money } from '@/Components/Store/money';
import StoreLayout from '@/Layouts/StoreLayout';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';

/**
 * A bundle's storefront page: several of one author's books at one price
 * (App\Commerce\Bundles). Public, like a book's page; buying it gives a
 * licence for every book in it.
 */
export default function Bundle({ bundle }) {
    const { t, formatDate } = useI18n();
    const { data, setData, post, processing, errors } = useForm({ email: '', code: '' });
    const saving = bundle.separately_cents - bundle.price_cents;

    const buy = (event) => {
        event.preventDefault();
        post(bundle.checkout_url);
    };

    return (
        <StoreLayout>
            <Head title={bundle.title} />

            <p className="text-[12px] font-semibold uppercase tracking-[0.12em] text-brand-primary">
                {t('bundle.eyebrow', { count: bundle.products.length })}
            </p>
            <h1
                data-a="bundle:title"
                className="mt-1 font-display text-[clamp(26px,4vw,38px)] font-extrabold leading-tight tracking-[-0.03em] text-text-primary"
            >
                {bundle.title}
            </h1>

            <form onSubmit={buy} className="mt-6 max-w-[560px] rounded-panel border border-border-default bg-bg-surface p-4">
                <p className="flex flex-wrap items-baseline gap-2">
                    <span data-a="bundle:price" className="font-display text-[28px] font-extrabold tabular-nums text-text-primary">
                        {money(bundle.price_cents, bundle.currency)}
                    </span>
                    {saving > 0 && (
                        <span data-a="bundle:saving" className="text-[12.5px] text-text-secondary">
                            {t('bundle.separately', {
                                price: money(bundle.separately_cents, bundle.currency),
                                saving: money(saving, bundle.currency),
                            })}
                        </span>
                    )}
                </p>
                <p className="mt-1 text-[12.5px] text-text-secondary">{t('bundle.licence_each')}</p>
                {bundle.preorder && <p className="mt-1 text-[12.5px] font-medium text-text-primary">{t('bundle.has_preorder')}</p>}

                <label className="mt-3 block text-[12px] text-text-secondary" htmlFor="bundle-email">
                    {t('bundle.email')}
                </label>
                <input
                    id="bundle-email"
                    type="email"
                    autoComplete="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    className="mt-1 w-full max-w-[360px] rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] text-text-primary"
                />
                {errors.email && <p className="mt-1 text-[12px] text-status-danger">{errors.email}</p>}

                <label className="mt-3 block text-[12px] text-text-secondary" htmlFor="bundle-code">
                    {t('bundle.code')}
                </label>
                <input
                    id="bundle-code"
                    data-a="bundle:code"
                    autoComplete="off"
                    maxLength={40}
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                    className="mt-1 w-full max-w-[220px] rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] uppercase text-text-primary"
                />
                {errors.code && <p className="mt-1 text-[12px] text-status-danger">{errors.code}</p>}
                {errors.checkout && <p className="mt-1 text-[12px] text-status-danger">{errors.checkout}</p>}

                <button
                    data-a="bundle:buy"
                    type="submit"
                    disabled={processing}
                    className="mt-3 block rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    {t('bundle.buy')}
                </button>
            </form>

            {bundle.description && (
                <p className="mt-6 max-w-[680px] whitespace-pre-line text-[14.5px] leading-relaxed text-text-body">{bundle.description}</p>
            )}

            <section className="mt-10">
                <h2 className="mb-3 text-[15px] font-semibold text-text-primary">{t('bundle.in_this')}</h2>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {bundle.products.map((product) => (
                        <div key={product.slug}>
                            <BookCard product={product} />
                            {product.preorder_at && (
                                <p className="mt-1 text-[11.5px] text-text-secondary">
                                    {t('bundle.out_on', { date: formatDate(product.preorder_at, { dateStyle: 'medium' }) })}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            </section>
        </StoreLayout>
    );
}
