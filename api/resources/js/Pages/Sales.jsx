import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import SalesReport from '@/Components/Store/SalesReport';
import { money } from '@/Components/Store/money';
import { Head, usePage } from '@inertiajs/react';

/**
 * Storefront sales across every book this author sells. Real orders only,
 * and only this account's own: the server scopes the report to the
 * signed-in seller.
 */
export default function Sales({ enabled, report, products }) {
    const { auth } = usePage().props;

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Sales" />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[1040px] px-4 py-9 sm:px-8">
                    <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                        Sales
                    </h1>
                    <p className="mt-1 text-[13px] text-text-secondary">
                        Books sold from your storefront
                        {!enabled && ' · selling turns on once card payments are connected'}
                    </p>

                    {products.length > 0 && (
                        <ul className="mt-5 flex flex-wrap gap-2">
                            {products.map((product) => (
                                <li
                                    key={product.book_id}
                                    className="rounded-tile border border-border-default bg-bg-surface px-3 py-2 text-[12px]"
                                >
                                    <span className="font-semibold text-text-primary">{product.title}</span>
                                    <span className="ml-2 text-text-secondary">
                                        {money(product.price_cents, product.currency)} ·{' '}
                                        {product.published ? (
                                            <a href={product.url} className="text-brand-primary hover:underline">
                                                on sale
                                            </a>
                                        ) : (
                                            'not on sale'
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="mt-6">
                        <SalesReport report={report} />
                    </div>
                </div>
            </main>

            <MobileNav current="store-sales" />
        </div>
    );
}
