import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import SalesReport from '@/Components/Store/SalesReport';
import { money } from '@/Components/Store/money';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * Storefront sales across every book this author sells. Real orders only,
 * and only this account's own: the server scopes the report to the
 * signed-in seller.
 */
export default function Sales({ enabled, report, products, partners = null }) {
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

                    {partners && <Partners partners={partners} />}
                </div>
            </main>

            <MobileNav current="store-sales" />
        </div>
    );
}

/**
 * Écrivain Partners selling this author's books: their public name and
 * page, sales, and a switch to block one from every book. Invite somebody
 * to partner (they skip the application queue, for these books only).
 */
function Partners({ partners }) {
    const invite = useForm({ email: '' });

    return (
        <section data-a="sales:partners" className="mt-8 rounded-tile border border-border-default bg-bg-surface p-4">
            <h2 className="text-[15px] font-semibold text-text-primary">Partners selling your books</h2>
            <p className="mt-1 text-[12.5px] text-text-secondary">
                {partners.opted_in > 0
                    ? `${partners.opted_in} of your books ${partners.opted_in === 1 ? 'is' : 'are'} open to partners. Their commission comes out of your royalty on the sales they bring.`
                    : 'Let partners sell a book from its storefront settings, then invite people who could share it.'}
            </p>

            {partners.partners.length > 0 && (
                <ul className="mt-3 divide-y divide-border-divider">
                    {partners.partners.map((p) => (
                        <li key={p.id} data-a="sales:partner" className="flex flex-wrap items-center justify-between gap-2 py-2 text-[13px]">
                            <span className="min-w-0">
                                {p.url ? (
                                    <a href={p.url} target="_blank" rel="noreferrer" className="font-semibold text-brand-primary hover:underline">
                                        {p.name}
                                    </a>
                                ) : (
                                    <span className="font-semibold text-text-primary">{p.name}</span>
                                )}
                                <span className="ml-2 text-[11.5px] text-text-secondary">
                                    {p.sales} sale{p.sales === 1 ? '' : 's'}
                                    {p.commissions.map((c) => ` · ${money(c.cents, c.currency)} commission`).join('')}
                                    {p.on_page && ' · on their page'}
                                    {p.status !== 'active' && ' · inactive'}
                                </span>
                            </span>
                            <button
                                type="button"
                                data-a={`sales:partner:${p.blocked ? 'unblock' : 'block'}`}
                                onClick={() =>
                                    p.blocked
                                        ? router.delete(route('sales.partners.unblock', p.id), { preserveScroll: true })
                                        : window.confirm(`Stop ${p.name} selling your books? Commissions already earned are kept.`) &&
                                          router.post(route('sales.partners.block', p.id), {}, { preserveScroll: true })
                                }
                                className={`tap min-h-[36px] rounded-button border px-3 text-[12px] font-semibold ${p.blocked ? 'border-border-default text-text-primary' : 'border-status-danger/40 text-status-danger'}`}
                            >
                                {p.blocked ? 'Unblock' : 'Block'}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <form
                className="mt-4 flex flex-wrap items-start gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    invite.post(route('sales.partners.invite'), { preserveScroll: true, onSuccess: () => invite.reset() });
                }}
            >
                <label htmlFor="invite-partner" className="sr-only">
                    Invite a partner by email
                </label>
                <input
                    id="invite-partner"
                    type="email"
                    required
                    placeholder="Invite a partner by email"
                    value={invite.data.email}
                    onChange={(e) => invite.setData('email', e.target.value)}
                    className="min-w-0 flex-1 rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[13.5px] text-text-primary sm:max-w-[320px]"
                />
                <button type="submit" disabled={invite.processing} className="tap min-h-[40px] rounded-button bg-brand-primary px-4 text-[13px] font-semibold text-white disabled:opacity-60">
                    Send invitation
                </button>
                {invite.errors.email && <p className="w-full text-[12px] text-status-danger">{invite.errors.email}</p>}
            </form>
            {partners.invitations.length > 0 && (
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    Invited: {partners.invitations.map((i) => `${i.email} (${i.status})`).join(', ')}
                </p>
            )}
        </section>
    );
}
