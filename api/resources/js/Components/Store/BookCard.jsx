import { money } from '@/Components/Store/money';
import { useI18n } from '@/i18n';

/** A book on a storefront shelf. The author line only when the book has one. */
export default function BookCard({ product }) {
    const { t } = useI18n();
    const price = money(product.price_cents, product.currency);

    return (
        <a
            href={product.url}
            data-a="store:card"
            className="group block rounded-panel border border-border-default bg-bg-surface p-3 transition hover:border-brand-primary/40"
        >
            <img
                src={product.cover_url}
                alt=""
                loading="lazy"
                className="aspect-[2/3] w-full rounded-tile bg-bg-subtle object-cover"
            />
            <p className="mt-2.5 text-[14px] font-semibold leading-snug text-text-primary group-hover:underline">
                {product.title}
            </p>
            {product.series_name && (
                <p className="text-[11.5px] text-text-secondary">
                    {product.series_name}
                    {product.series_number ? ` · Book ${product.series_number}` : ''}
                </p>
            )}
            {product.author && <p className="text-[12px] text-text-secondary">{product.author}</p>}
            <p className="mt-1.5 text-[13px] font-semibold tabular-nums text-text-primary">
                {product.from ? t('store.price_from', { price }) : price}
                {product.list_cents && (
                    <s className="ml-1.5 text-[11.5px] font-normal text-text-secondary">{money(product.list_cents, product.currency)}</s>
                )}
                <span className="ml-1.5 text-[11px] font-normal text-text-secondary">
                    {product.formats.join(' + ')}
                </span>
            </p>
        </a>
    );
}
