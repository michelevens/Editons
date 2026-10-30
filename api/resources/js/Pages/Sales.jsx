import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import SalesExplorer from '@/Components/Store/SalesExplorer';
import { useI18n } from '@/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

/**
 * Storefront sales across every book this author sells, over a date range.
 * Real orders only, and only this account's own: the server scopes the
 * report to the signed-in seller — or, for staff, to the house imprints
 * (scope=house).
 */
export default function Sales({ enabled, report, products, partners = null, query, options, statements, timezone, scope, can_house: canHouse }) {
    const { auth } = usePage().props;
    const { t, formatMoney } = useI18n();

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('sales.title')} />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[1040px] px-4 py-9 sm:px-8">
                    <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                        {t('sales.title')}
                    </h1>
                    <div className="mt-1 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-[13px] text-text-secondary">
                            {scope === 'house' ? t('sales.subtitle_house') : t('sales.subtitle_own')}
                            {!enabled && scope !== 'house' && ` · ${t('sales.selling_turns_on')}`}
                        </p>
                        {canHouse && (
                            <div className="flex gap-1" role="group" aria-label={t('sales.whose_sales')}>
                                {[
                                    [null, t('sales.scope_mine')],
                                    ['house', t('sales.scope_house')],
                                ].map(([key, label]) => {
                                    const on = (scope === 'house') === (key === 'house');

                                    return (
                                        <Link
                                            key={key ?? 'own'}
                                            href={route('sales.overview', key ? { scope: key } : {})}
                                            data-a={`sales:scope:${key ?? 'own'}`}
                                            aria-current={on ? 'page' : undefined}
                                            className={`tap inline-flex min-h-[32px] items-center rounded-control px-2.5 text-[12.5px] font-semibold ${
                                                on ? 'bg-brand-primary/10 text-brand-primary' : 'text-text-secondary hover:text-text-primary'
                                            }`}
                                        >
                                            {label}
                                        </Link>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {products.length > 0 && (
                        <ul className="mt-5 flex flex-wrap gap-2">
                            {products.map((product) => (
                                <li
                                    key={product.book_id}
                                    className="rounded-tile border border-border-default bg-bg-surface px-3 py-2 text-[12px]"
                                >
                                    <span className="font-semibold text-text-primary">{product.title}</span>
                                    <span className="ml-2 text-text-secondary">
                                        {formatMoney(product.price_cents, product.currency)} ·{' '}
                                        {product.published ? (
                                            <a href={product.url} className="text-brand-primary hover:underline">
                                                {t('sales.on_sale')}
                                            </a>
                                        ) : (
                                            t('sales.not_on_sale')
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="mt-6">
                        <SalesExplorer report={report} query={query} options={options} statements={statements} timezone={timezone} scope={scope} />
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
    const { t, formatMoney } = useI18n();
    const invite = useForm({ email: '' });

    return (
        <section data-a="sales:partners" className="mt-8 rounded-tile border border-border-default bg-bg-surface p-4">
            <h2 className="text-[15px] font-semibold text-text-primary">{t('sales.partners_title')}</h2>
            <p className="mt-1 text-[12.5px] text-text-secondary">
                {partners.opted_in > 0
                    ? t('sales.partners_opted_in', { count: partners.opted_in })
                    : t('sales.partners_none_opted_in')}
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
                                    {t('sales.partner_sales', { count: p.sales })}
                                    {p.commissions.map((c) => ` · ${t('sales.partner_commission', { amount: formatMoney(c.cents, c.currency) })}`).join('')}
                                    {p.on_page && ` · ${t('sales.partner_on_page')}`}
                                    {p.status !== 'active' && ` · ${t('sales.partner_inactive')}`}
                                </span>
                            </span>
                            <button
                                type="button"
                                data-a={`sales:partner:${p.blocked ? 'unblock' : 'block'}`}
                                onClick={() =>
                                    p.blocked
                                        ? router.delete(route('sales.partners.unblock', p.id), { preserveScroll: true })
                                        : window.confirm(t('sales.partner_block_confirm', { name: p.name })) &&
                                          router.post(route('sales.partners.block', p.id), {}, { preserveScroll: true })
                                }
                                className={`tap min-h-[36px] rounded-button border px-3 text-[12px] font-semibold ${p.blocked ? 'border-border-default text-text-primary' : 'border-status-danger/40 text-status-danger'}`}
                            >
                                {p.blocked ? t('sales.partner_unblock') : t('sales.partner_block')}
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
                    {t('sales.invite_by_email')}
                </label>
                <input
                    id="invite-partner"
                    type="email"
                    required
                    placeholder={t('sales.invite_by_email')}
                    value={invite.data.email}
                    onChange={(e) => invite.setData('email', e.target.value)}
                    className="min-w-0 flex-1 rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[13.5px] text-text-primary sm:max-w-[320px]"
                />
                <button type="submit" disabled={invite.processing} className="tap min-h-[40px] rounded-button bg-brand-primary px-4 text-[13px] font-semibold text-white disabled:opacity-60">
                    {t('sales.send_invitation')}
                </button>
                {invite.errors.email && <p className="w-full text-[12px] text-status-danger">{invite.errors.email}</p>}
            </form>
            {partners.invitations.length > 0 && (
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    {t('sales.invited', { list: partners.invitations.map((i) => `${i.email} (${i.status})`).join(', ') })}
                </p>
            )}
        </section>
    );
}
