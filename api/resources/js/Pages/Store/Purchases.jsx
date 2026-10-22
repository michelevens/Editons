import AppHeader from '@/Components/Studio/AppHeader';
import OrderSummary from '@/Components/Store/OrderSummary';
import { Head, usePage } from '@inertiajs/react';

/**
 * Books this account bought while signed in, with fresh download links.
 * Orders placed while signed out are reached through the emailed links.
 */
export default function Purchases({ orders }) {
    const { auth } = usePage().props;

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Your purchases" />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[760px] px-4 py-9 sm:px-8">
                    <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                        Your purchases
                    </h1>

                    {orders.length === 0 ? (
                        <p className="mt-4 text-[13px] text-text-secondary">
                            Nothing bought while signed in yet. Orders placed while signed out are
                            in the receipt email.
                        </p>
                    ) : (
                        <div className="mt-6 space-y-4">
                            {orders.map((order) => (
                                <OrderSummary key={order.number} order={order} />
                            ))}
                        </div>
                    )}
                </div>
            </main>
        </div>
    );
}
