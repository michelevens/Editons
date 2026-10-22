import OrderSummary from '@/Components/Store/OrderSummary';
import { money } from '@/Components/Store/money';
import StoreLayout from '@/Layouts/StoreLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * The pages around Stripe's hosted checkout.
 *
 *   success    Stripe sent the buyer back. The order shows as paid once the
 *              signed webhook has arrived — usually before the page loads,
 *              sometimes a moment after, so a pending order says so.
 *   cancelled  The buyer backed out; nothing was charged.
 *   test       The fake gateway's stand-in for Stripe (local development
 *              and tests only — the server refuses it in production).
 */
export default function Checkout({ state, order = null, product = null, session = null, email = '' }) {
    if (state === 'cancelled') {
        return (
            <StoreLayout>
                <Head title="Checkout cancelled" />
                <h1 className="font-display text-[28px] font-extrabold tracking-[-0.03em] text-text-primary">
                    Checkout cancelled
                </h1>
                <p className="mt-2 text-[14px] text-text-body">Nothing was charged.</p>
                <a
                    href={product.url}
                    className="mt-5 inline-block rounded-button border border-border-default px-4 py-2 text-[13px] font-semibold text-text-body hover:bg-bg-subtle"
                >
                    Back to {product.title}
                </a>
            </StoreLayout>
        );
    }

    if (state === 'test') {
        return <TestCheckout order={order} session={session} email={email} />;
    }

    const paid = order.status === 'paid' || order.status === 'partially_refunded';

    return (
        <StoreLayout>
            <Head title={paid ? 'Thank you' : 'Payment received'} />

            <h1
                data-a="store:thanks"
                className="font-display text-[28px] font-extrabold tracking-[-0.03em] text-text-primary"
            >
                {paid ? 'Thank you — your book is ready' : order.status === 'refunded' ? 'This order was refunded' : 'Confirming your payment…'}
            </h1>
            <p className="mt-2 max-w-[60ch] text-[14px] text-text-body">
                {paid
                    ? `A receipt with your download links is on its way${order.email ? ` to ${order.email}` : ''}.`
                    : order.status === 'refunded'
                      ? 'The download links no longer work.'
                      : 'Stripe has your payment and is confirming it with us. Refresh in a moment; your receipt and download links will also arrive by email.'}
            </p>

            <div className="mt-6 max-w-[640px]">
                <OrderSummary order={order} />
            </div>
        </StoreLayout>
    );
}

function TestCheckout({ order, session, email }) {
    const { data, setData, post, processing, errors } = useForm({ email: email ?? '', name: '' });

    const pay = (event) => {
        event.preventDefault();
        post(route('store.checkout.fake.pay', { session }));
    };

    return (
        <StoreLayout>
            <Head title="Test checkout" />

            <p
                data-a="store:test-checkout"
                className="mb-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning"
            >
                Test checkout. Card payments are not connected here, so no card is asked for and
                nothing is charged. In production this page is Stripe&rsquo;s.
            </p>

            <h1 className="font-display text-[26px] font-extrabold tracking-[-0.03em] text-text-primary">
                Pay {money(order.total_cents, order.currency)}
            </h1>

            <form onSubmit={pay} className="mt-5 max-w-[420px] space-y-3">
                <div>
                    <label htmlFor="test-email" className="block text-[12px] text-text-secondary">
                        Email
                    </label>
                    <input
                        id="test-email"
                        type="email"
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="mt-1 w-full rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] text-text-primary"
                    />
                    {errors.email && <p className="mt-1 text-[12px] text-status-danger">{errors.email}</p>}
                </div>
                <div>
                    <label htmlFor="test-name" className="block text-[12px] text-text-secondary">
                        Name (optional)
                    </label>
                    <input
                        id="test-name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="mt-1 w-full rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] text-text-primary"
                    />
                </div>
                <button
                    data-a="store:test-pay"
                    type="submit"
                    disabled={processing}
                    className="rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    Complete test payment
                </button>
            </form>

            <div className="mt-8 max-w-[640px]">
                <OrderSummary order={order} />
            </div>
        </StoreLayout>
    );
}
