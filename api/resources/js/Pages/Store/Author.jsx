import BookCard from '@/Components/Store/BookCard';
import StoreLayout from '@/Layouts/StoreLayout';
import { Head } from '@inertiajs/react';

/**
 * An author's shelf: their books on sale that carry an author name. The
 * heading is those names — the books' own, never the account's.
 */
export default function Author({ names, products, report_url: reportUrl }) {
    const heading = names.join(' · ');

    return (
        <StoreLayout report={{ url: reportUrl, label: 'Report this page' }}>
            <Head title={heading} />

            <h1
                data-a="store:shelf"
                className="font-display text-[clamp(26px,4vw,36px)] font-extrabold tracking-[-0.03em] text-text-primary"
            >
                {heading}
            </h1>
            <p className="mt-1 text-[13px] text-text-secondary">
                {products.length} book{products.length === 1 ? '' : 's'} on sale
            </p>

            <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {products.map((product) => (
                    <BookCard key={product.slug} product={product} />
                ))}
            </div>
        </StoreLayout>
    );
}
