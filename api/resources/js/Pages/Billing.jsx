import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import Icon from '@/Components/Studio/Icon';
import { useI18n } from '@/i18n';
import { Head, usePage } from '@inertiajs/react';

/**
 * Plans & Billing.
 *
 * Every figure comes from the ledger. Where there is no history the chart and
 * the activity list are empty rather than filled with plausible-looking
 * numbers — the prototype generates a sample history and tags it as such,
 * which is honest there but would be a lie on a real billing screen.
 */
export default function Billing({
    current,
    plans,
    costs,
    packs,
    activity,
    daily,
    checkout_available: checkoutAvailable,
}) {
    const { auth } = usePage().props;
    const { t, formatNumber } = useI18n();
    // Plan and pack prices are whole dollars (or dollars and cents) from the
    // catalogue; shown as before, "$12" rather than "$12.00".
    const usd = (dollars) => formatNumber(dollars, { style: 'currency', currency: 'USD', minimumFractionDigits: 0 });

    const pct = current.monthly_credits
        ? Math.max(0, Math.min(100, Math.round((current.credits / current.monthly_credits) * 100)))
        : 0;

    const low = current.monthly_credits > 0 && current.credits < current.monthly_credits * 0.15;

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('billing.title')} />

            <AppHeader
                active="books"
                user={auth?.user}
                account={auth?.account}
            />

            <main id="main" tabIndex={0} aria-label={t('billing.main_label')} className="min-h-0 flex-1 overflow-y-auto outline-none">
                <div className="mx-auto max-w-[1040px] px-8 py-9">
                    <header className="flex flex-wrap items-start justify-between gap-5">
                        <div className="min-w-0">
                            <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                                {t('billing.title')}
                            </h1>
                            <p className="mt-1 text-[13px] text-text-secondary">
                                {t('billing.plan_name', { plan: current.name })}
                                {current.price > 0 ? ` · ${t('billing.price_a_month', { price: usd(current.price) })}` : ` · ${t('billing.free_lower')}`}
                                {current.renews_at ? ` · ${t('billing.renews', { date: current.renews_at })}` : ''}
                            </p>
                        </div>
                    </header>

                    <div className="mt-7 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Box title={t('billing.ai_credits')}>
                            <p className="flex items-baseline gap-2">
                                <b className="font-display text-[30px] font-extrabold tracking-[-0.02em] text-text-primary">
                                    {formatNumber(current.credits)}
                                </b>
                                <span className="text-[12.5px] text-text-secondary">
                                    {current.monthly_credits > 0
                                        ? t('billing.of_this_month', { total: formatNumber(current.monthly_credits) })
                                        : t('billing.in_your_account')}
                                </span>
                            </p>

                            {current.monthly_credits > 0 && (
                                <div className="mt-2.5 h-2 overflow-hidden rounded-full bg-bg-subtle">
                                    <div
                                        className={`h-full rounded-full transition-[width] ${
                                            low ? 'bg-status-danger' : 'bg-brand-fill'
                                        }`}
                                        style={{ width: `${pct}%` }}
                                    />
                                </div>
                            )}

                            <p className="mt-2.5 text-[11.5px] text-text-secondary">
                                {t('billing.used_this_month', { used: formatNumber(current.used_this_month) })}
                                {current.renews_at ? ` · ${t('billing.resets', { date: current.renews_at })}` : ''}
                            </p>

                            <h3 className="mt-5 mb-1.5 text-[12px] font-semibold text-text-primary">
                                {t('billing.what_things_cost')}
                            </h3>
                            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                                {costs.map((cost) => (
                                    <div key={cost.key} className="col-span-2 flex justify-between border-b border-border-default py-1 last:border-0">
                                        <dt className="text-[12px] text-text-body">{cost.label}</dt>
                                        <dd className="text-[12px] font-semibold tabular-nums text-text-primary">
                                            {cost.cost}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </Box>

                        <Box title={t('billing.credits_per_day')}>
                            <DailyChart days={daily} />
                        </Box>
                    </div>

                    <Box title={t('billing.plans')} className="mt-4">
                        <div tabIndex={0} role="region" aria-label={t('billing.plans')} className="overflow-x-auto">
                            <table className="w-full min-w-[520px] text-left text-[12.5px]">
                                <thead>
                                    <tr>
                                        <th />
                                        {plans.map((plan) => (
                                            <th
                                                key={plan.key}
                                                className={`border-b p-2.5 align-top ${
                                                    plan.current
                                                        ? 'border-brand-primary bg-brand-primary/5'
                                                        : 'border-border-default'
                                                }`}
                                            >
                                                <b className="block text-[14px] font-bold text-text-primary">
                                                    {plan.name}
                                                </b>
                                                <span className="text-[12px] text-text-secondary">
                                                    {plan.price > 0 ? t('billing.price_per_month', { price: usd(plan.price) }) : t('billing.free')}
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    <Row label={t('billing.row_credits')} plans={plans} value={(p) => formatNumber(p.credits)} />
                                    <Row label={t('billing.row_books')} plans={plans} value={(p) => (p.books === null ? t('billing.unlimited') : p.books)} />
                                    <Row label={t('billing.row_seats')} plans={plans} value={(p) => (p.seats === null ? t('billing.unlimited') : p.seats)} />
                                    <Row
                                        label={t('billing.row_formats')}
                                        plans={plans}
                                        value={(p) => p.formats.map((f) => f.toUpperCase()).join(', ')}
                                    />
                                    <Row
                                        label={t('billing.row_reviewers')}
                                        plans={plans}
                                        value={(p) =>
                                            [t('billing.tier_general'), t('billing.tier_fact_check'), t('billing.tier_subject')][p.tier]
                                        }
                                    />

                                    <tr>
                                        <td />
                                        {plans.map((plan) => (
                                            <td
                                                key={plan.key}
                                                className={`p-2.5 align-top ${
                                                    plan.current ? 'bg-brand-primary/5' : ''
                                                }`}
                                            >
                                                {plan.current ? (
                                                    <span className="inline-block rounded-badge bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">
                                                        {t('billing.current_plan')}
                                                    </span>
                                                ) : (
                                                    <button
                                                        data-a={`billing:plan:${plan.key}`}
                                                        disabled={!checkoutAvailable}
                                                        title={
                                                            checkoutAvailable
                                                                ? undefined
                                                                : t('billing.card_not_connected')
                                                        }
                                                        onClick={() => {}}
                                                        className={`rounded-control px-2.5 py-1 text-[11.5px] font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                                            plan.tier > current.tier
                                                                ? 'bg-brand-fill text-white hover:bg-brand-primary-hover'
                                                                : 'border border-border-input text-text-body'
                                                        }`}
                                                    >
                                                        {plan.tier > current.tier ? t('billing.upgrade') : t('billing.downgrade')}
                                                    </button>
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {!checkoutAvailable && (
                            <p
                                data-a="billing:checkout-unavailable"
                                className="mt-3 rounded-tile bg-status-info-bg px-3 py-2 text-[11.5px] leading-relaxed text-status-info"
                            >
                                {t('billing.checkout_unavailable')}
                            </p>
                        )}
                    </Box>

                    <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Box title={t('billing.credit_packs')}>
                            {packs.map((pack) => (
                                <div
                                    key={pack.key}
                                    className="flex items-center justify-between gap-3 border-b border-border-default py-2 last:border-0"
                                >
                                    <div>
                                        <p className="text-[12.5px] font-medium text-text-primary">
                                            {t('billing.pack_credits', { credits: formatNumber(pack.credits) })}
                                        </p>
                                        <p className="text-[11px] text-text-secondary">
                                            {t('billing.pack_price', { price: usd(pack.price) })}
                                        </p>
                                    </div>
                                    <button
                                        data-a={`billing:pack:${pack.key}`}
                                        disabled={!checkoutAvailable}
                                        title={
                                            checkoutAvailable
                                                ? undefined
                                                : t('billing.card_not_connected')
                                        }
                                        className="rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {t('billing.buy')}
                                    </button>
                                </div>
                            ))}
                        </Box>

                        <Box title={t('billing.payment_method')}>
                            {current.card ? (
                                <div className="flex items-center gap-2.5">
                                    <span className="text-text-secondary">
                                        <Icon name="cite" size={18} strokeWidth={1.8} />
                                    </span>
                                    <div>
                                        <b className="block text-[12.5px] text-text-primary">
                                            {current.card}
                                        </b>
                                        {current.renews_at && (
                                            <span className="text-[11px] text-text-secondary">
                                                {t('billing.next_charge', { date: current.renews_at })}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <p className="text-[11.5px] leading-relaxed text-text-secondary">
                                    {t('billing.no_card', { plan: current.name })}
                                </p>
                            )}
                        </Box>
                    </div>

                    <Box title={t('billing.recent_use')} className="mt-4 mb-4">
                        {activity.length === 0 ? (
                            <p className="text-[11.5px] leading-relaxed text-text-secondary">
                                {t('billing.recent_use_empty')}
                            </p>
                        ) : (
                            <ul>
                                {activity.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex items-baseline justify-between gap-3 border-b border-border-default py-1.5 last:border-0"
                                    >
                                        <span className="text-[12.5px] text-text-body">
                                            {row.label}
                                        </span>
                                        <span className="shrink-0 text-[11.5px] text-text-secondary">
                                            <b
                                                className={`tabular-nums ${
                                                    row.amount < 0
                                                        ? 'text-text-primary'
                                                        : 'text-status-success'
                                                }`}
                                            >
                                                {row.amount > 0 ? '+' : '−'}
                                                {Math.abs(row.amount)}
                                            </b>{' '}
                                            · {row.at}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Box>
                </div>
            </main>

            <MobileNav current="billing" />
        </div>
    );
}

/**
 * Credits spent per day.
 *
 * Bars only, no axis labels beyond the ends: this answers "am I burning
 * credits faster than usual", and a precise reading belongs in the list below.
 */
function DailyChart({ days }) {
    const { t } = useI18n();
    const peak = Math.max(1, ...days.map((day) => day.credits));
    const anything = days.some((day) => day.credits > 0);

    return (
        <>
            <div
                className="flex h-[130px] items-end gap-1"
                role="img"
                aria-label={
                    anything
                        ? t('billing.chart_label', { peak })
                        : t('billing.chart_label_empty')
                }
            >
                {days.map((day) => (
                    <div key={day.date} className="group relative flex-1">
                        <div
                            title={`${day.label}: ${day.credits}`}
                            className={`w-full rounded-t transition-[height] ${
                                day.today ? 'bg-brand-fill' : 'bg-brand-primary/35'
                            }`}
                            style={{
                                // A floor of 2px so a zero day still reads as a
                                // day rather than a gap in the axis.
                                height: day.credits
                                    ? `${Math.max(3, (day.credits / peak) * 130)}px`
                                    : '2px',
                            }}
                        />
                    </div>
                ))}
            </div>

            <div className="mt-1.5 flex justify-between text-[10.5px] text-text-secondary">
                <span>{days[0]?.label}</span>
                <span>{t('billing.today')}</span>
            </div>

            {!anything && (
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    {t('billing.chart_nothing')}
                </p>
            )}
        </>
    );
}

function Row({ label, plans, value }) {
    return (
        <tr>
            <td className="border-b border-border-default py-2 pr-4 text-[12px] text-text-secondary">
                {label}
            </td>
            {plans.map((plan) => (
                <td
                    key={plan.key}
                    className={`border-b border-border-default px-2.5 py-2 text-[12px] text-text-body ${
                        plan.current ? 'bg-brand-primary/5' : ''
                    }`}
                >
                    {value(plan)}
                </td>
            ))}
        </tr>
    );
}

function Box({ title, children, className = '' }) {
    return (
        <section
            className={`rounded-panel border border-border-default bg-bg-surface p-4 ${className}`}
        >
            <h2 className="mb-2.5 text-[14px] font-semibold text-text-primary">{title}</h2>
            {children}
        </section>
    );
}
