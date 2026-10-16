import AppHeader from '@/Components/Studio/AppHeader';
import Icon from '@/Components/Studio/Icon';
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

    const pct = current.monthly_credits
        ? Math.max(0, Math.min(100, Math.round((current.credits / current.monthly_credits) * 100)))
        : 0;

    const low = current.monthly_credits > 0 && current.credits < current.monthly_credits * 0.15;

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Plans & Billing" />

            <AppHeader
                active="books"
                user={auth?.user}
                account={auth?.account}
            />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[1040px] px-8 py-9">
                    <header className="flex flex-wrap items-start justify-between gap-5">
                        <div className="min-w-0">
                            <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                                Plans &amp; Billing
                            </h1>
                            <p className="mt-1 text-[13px] text-text-secondary">
                                {current.name} plan
                                {current.price > 0 ? ` · $${current.price} a month` : ' · free'}
                                {current.renews_at ? ` · renews ${current.renews_at}` : ''}
                            </p>
                        </div>
                    </header>

                    <div className="mt-7 grid gap-4 lg:grid-cols-2">
                        <Box title="AI credits">
                            <p className="flex items-baseline gap-2">
                                <b className="font-display text-[30px] font-extrabold tracking-[-0.02em] text-text-primary">
                                    {current.credits.toLocaleString()}
                                </b>
                                <span className="text-[12.5px] text-text-secondary">
                                    {current.monthly_credits > 0
                                        ? `of ${current.monthly_credits.toLocaleString()} this month`
                                        : 'in your account'}
                                </span>
                            </p>

                            {current.monthly_credits > 0 && (
                                <div className="mt-2.5 h-2 overflow-hidden rounded-full bg-bg-subtle">
                                    <div
                                        className={`h-full rounded-full transition-[width] ${
                                            low ? 'bg-status-danger' : 'bg-brand-primary'
                                        }`}
                                        style={{ width: `${pct}%` }}
                                    />
                                </div>
                            )}

                            <p className="mt-2.5 text-[11.5px] text-text-secondary">
                                {current.used_this_month.toLocaleString()} used this month
                                {current.renews_at ? ` · resets ${current.renews_at}` : ''}
                            </p>

                            <h3 className="mt-5 mb-1.5 text-[12px] font-semibold text-text-primary">
                                What things cost
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

                        <Box title="Credits used per day">
                            <DailyChart days={daily} />
                        </Box>
                    </div>

                    <Box title="Plans" className="mt-4">
                        <div className="overflow-x-auto">
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
                                                    {plan.price > 0 ? `$${plan.price}/mo` : 'Free'}
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    <Row label="AI credits each month" plans={plans} value={(p) => p.credits.toLocaleString()} />
                                    <Row label="Active books" plans={plans} value={(p) => (p.books === null ? 'Unlimited' : p.books)} />
                                    <Row label="Team seats" plans={plans} value={(p) => (p.seats === null ? 'Unlimited' : p.seats)} />
                                    <Row
                                        label="Export formats"
                                        plans={plans}
                                        value={(p) => p.formats.map((f) => f.toUpperCase()).join(', ')}
                                    />
                                    <Row
                                        label="Specialist reviewers"
                                        plans={plans}
                                        value={(p) =>
                                            ['General editors only', '+ Fact check, Consistency', '+ Subject matter'][p.tier]
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
                                                        Current plan
                                                    </span>
                                                ) : (
                                                    <button
                                                        data-a={`billing:plan:${plan.key}`}
                                                        disabled={!checkoutAvailable}
                                                        title={
                                                            checkoutAvailable
                                                                ? undefined
                                                                : 'Card payments are not connected yet'
                                                        }
                                                        onClick={() => {}}
                                                        className={`rounded-control px-2.5 py-1 text-[11.5px] font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                                            plan.tier > current.tier
                                                                ? 'bg-brand-primary text-white hover:bg-brand-primary-hover'
                                                                : 'border border-border-input text-text-body'
                                                        }`}
                                                    >
                                                        {plan.tier > current.tier ? 'Upgrade' : 'Downgrade'}
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
                                Card payments are not connected on this environment yet, so plans
                                cannot be changed here. The limits above are enforced — this is
                                not a mock-up of a paywall, it is the real one with no till
                                attached.
                            </p>
                        )}
                    </Box>

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <Box title="Credit packs">
                            {packs.map((pack) => (
                                <div
                                    key={pack.key}
                                    className="flex items-center justify-between gap-3 border-b border-border-default py-2 last:border-0"
                                >
                                    <div>
                                        <p className="text-[12.5px] font-medium text-text-primary">
                                            {pack.credits.toLocaleString()} credits
                                        </p>
                                        <p className="text-[11px] text-text-secondary">
                                            ${pack.price} one off · they do not expire
                                        </p>
                                    </div>
                                    <button
                                        data-a={`billing:pack:${pack.key}`}
                                        disabled={!checkoutAvailable}
                                        title={
                                            checkoutAvailable
                                                ? undefined
                                                : 'Card payments are not connected yet'
                                        }
                                        className="rounded-control border border-border-input px-2.5 py-1 text-[11.5px] font-medium text-text-body disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Buy
                                    </button>
                                </div>
                            ))}
                        </Box>

                        <Box title="Payment method">
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
                                                Next charge {current.renews_at}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <p className="text-[11.5px] leading-relaxed text-text-secondary">
                                    No card on file. You do not need one on the {current.name}{' '}
                                    plan.
                                </p>
                            )}
                        </Box>
                    </div>

                    <Box title="Recent credit use" className="mt-4 mb-4">
                        {activity.length === 0 ? (
                            <p className="text-[11.5px] leading-relaxed text-text-secondary">
                                Reviews and generators you run will appear here with what they
                                cost.
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
    const peak = Math.max(1, ...days.map((day) => day.credits));
    const anything = days.some((day) => day.credits > 0);

    return (
        <>
            <div
                className="flex h-[130px] items-end gap-1"
                role="img"
                aria-label={
                    anything
                        ? `Credits used per day over two weeks, highest ${peak}`
                        : 'No credits used in the last two weeks'
                }
            >
                {days.map((day) => (
                    <div key={day.date} className="group relative flex-1">
                        <div
                            title={`${day.label}: ${day.credits}`}
                            className={`w-full rounded-t transition-[height] ${
                                day.today ? 'bg-brand-primary' : 'bg-brand-primary/35'
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
                <span>Today</span>
            </div>

            {!anything && (
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    Nothing spent in the last two weeks.
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
