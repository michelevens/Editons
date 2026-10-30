import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import TwoFactorPanel from '@/Components/Platform/TwoFactorPanel';
import { ThemeSwitch } from '@/Components/Studio/UserMenu';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import { useI18n } from '@/i18n';

/**
 * The author's profile.
 *
 * Four things, in the order they matter to somebody writing a book: who the
 * cover says you are, how you like to work, what you are paying for, and the
 * account itself.
 *
 * Identity comes first because it is the part that leaves the application —
 * into eBook metadata, onto a store listing, into the About the Author page —
 * and the part nothing else can fill in for you.
 */
export default function Edit({
    mustVerifyEmail,
    status,
    author,
    book_types: bookTypes,
    plan,
    email_preferences: emailPreferences = [],
    notifications = null,
    two_factor: twoFactor = null,
}) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('profile.title')} />

            <AppHeader
                active="books"
                user={auth?.user}
                account={auth?.account}
            />

            <main id="main" tabIndex={-1} className="min-h-0 flex-1 overflow-y-auto outline-none">
                <div className="mx-auto max-w-[820px] px-4 py-6 sm:px-8 sm:py-9">
                    <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                        {t('profile.title')}
                    </h1>
                    <p className="mt-1 text-[13px] text-text-secondary">
                        {t('profile.subtitle')}
                    </p>

                    {status === 'profile-updated' && (
                        <p
                            role="status"
                            data-a="profile:saved"
                            className="mt-4 rounded-tile bg-status-success-bg px-3 py-2 text-[12.5px] text-status-success"
                        >
                            {t('profile.saved')}
                        </p>
                    )}

                    <AuthorForm
                        author={author}
                        bookTypes={bookTypes}
                        mustVerifyEmail={mustVerifyEmail}
                    />

                    <div className="mt-4 space-y-4">
                        <PlanCard plan={plan} />

                        <Box
                            title={t('profile.appearance')}
                            detail={t('profile.appearance_detail')}
                        >
                            <ThemeSwitch className="max-w-[360px]" menu={false} />
                        </Box>

                        <EmailPreferences
                            categories={emailPreferences}
                            notifications={notifications}
                            saved={status === 'email-preferences-updated'}
                        />

                        <Box
                            title={t('signin.password')}
                            detail={t('profile.password_detail')}
                        >
                            <UpdatePasswordForm className="max-w-full" />
                        </Box>

                        {twoFactor && (
                            <Box
                                title={t('profile.two_factor')}
                                detail={t('profile.two_factor_detail')}
                                data-a="profile:two-factor"
                            >
                                <TwoFactorPanel state={twoFactor} />
                            </Box>
                        )}

                        <Box
                            title={t('profile.delete_modal_label')}
                            detail={t('profile.delete_detail')}
                            danger
                        >
                            <DeleteUserForm className="max-w-full" />
                        </Box>
                    </div>
                </div>
            </main>

            <MobileNav current="account" />
        </div>
    );
}

function AuthorForm({ author, bookTypes, mustVerifyEmail }) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: auth.user.name ?? '',
        email: auth.user.email ?? '',
        author_name: author.author_name ?? '',
        bio: author.bio ?? '',
        website: author.website ?? '',
        default_book_type: author.default_book_type ?? '',
        daily_word_goal: author.daily_word_goal ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-6 space-y-4">
            <Box
                title={t('profile.credited')}
                detail={t('profile.credited_detail')}
            >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label={t('register.name')} error={errors.name}>
                        <input
                            value={data.name}
                            data-a="profile:name"
                            required
                            onChange={(event) => setData('name', event.target.value)}
                            className={input(errors.name)}
                        />
                        <Hint>{t('profile.your_name_hint')}</Hint>
                    </Field>

                    <Field label={t('profile.author_name')} error={errors.author_name}>
                        <input
                            value={data.author_name}
                            data-a="profile:author-name"
                            placeholder={data.name || t('profile.author_name_placeholder')}
                            onChange={(event) => setData('author_name', event.target.value)}
                            className={input(errors.author_name)}
                        />
                        <Hint>{t('profile.author_name_hint')}</Hint>
                    </Field>
                </div>

                <Field label={t('profile.about_you')} error={errors.bio} className="mt-4">
                    <textarea
                        value={data.bio}
                        rows={4}
                        data-a="profile:bio"
                        placeholder={t('profile.bio_placeholder')}
                        onChange={(event) => setData('bio', event.target.value)}
                        className={input(errors.bio)}
                    />
                    <Hint>
                        {t('profile.bio_hint', { n: data.bio.length })}
                    </Hint>
                </Field>

                <Field label={t('profile.website')} error={errors.website} className="mt-4">
                    <input
                        value={data.website}
                        type="url"
                        data-a="profile:website"
                        placeholder="https://"
                        onChange={(event) => setData('website', event.target.value)}
                        className={input(errors.website)}
                    />
                </Field>
            </Box>

            <Box
                title={t('profile.work')}
                detail={t('profile.work_detail')}
            >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label={t('profile.usual_kind')} error={errors.default_book_type}>
                        <select
                            value={data.default_book_type}
                            data-a="profile:default-type"
                            onChange={(event) =>
                                setData('default_book_type', event.target.value)
                            }
                            className={input(errors.default_book_type)}
                        >
                            <option value="">{t('profile.no_default_type')}</option>
                            {bookTypes.map((type) => (
                                <option key={type.key} value={type.key}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('profile.daily_goal')} error={errors.daily_word_goal}>
                        <input
                            value={data.daily_word_goal}
                            type="number"
                            min="50"
                            max="20000"
                            data-a="profile:word-goal"
                            placeholder={t('profile.none')}
                            onChange={(event) =>
                                setData('daily_word_goal', event.target.value)
                            }
                            className={input(errors.daily_word_goal)}
                        />
                        <Hint>{t('profile.daily_goal_hint')}</Hint>
                    </Field>
                </div>
            </Box>

            <Box
                title={t('menu.account_label')}
                detail={t('profile.account_detail')}
            >
                <Field label={t('profile.email')} error={errors.email}>
                    <input
                        value={data.email}
                        type="email"
                        data-a="profile:email"
                        required
                        onChange={(event) => setData('email', event.target.value)}
                        className={input(errors.email)}
                    />
                </Field>

                {mustVerifyEmail && auth.user.email_verified_at === null && (
                    <p className="mt-2 text-[11.5px] text-status-warning">
                        {t('profile.unverified')}{' '}
                        <Link
                            href={route('verification.send')}
                            method="post"
                            as="button"
                            className="font-semibold underline"
                        >
                            {t('profile.send_link_again')}
                        </Link>
                    </p>
                )}
            </Box>

            <div className="flex items-center gap-3">
                <button
                    data-a="profile:save"
                    disabled={processing}
                    className="h-[38px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {processing ? t('editor.save_saving') : t('profile.save_changes')}
                </button>

                {recentlySuccessful && (
                    <span className="text-[12.5px] text-status-success">{t('profile.saved')}</span>
                )}
            </div>
        </form>
    );
}

/**
 * The plan, here as well as on Plans & Billing.
 *
 * Somebody checking their own details should not have to go somewhere else to
 * see what they are paying for and how much of it is left.
 */
function PlanCard({ plan }) {
    const { t, formatNumber } = useI18n();
    const pct = plan.monthly_credits
        ? Math.max(0, Math.min(100, Math.round((plan.credits / plan.monthly_credits) * 100)))
        : 0;

    return (
        <Box
            title={t('profile.your_plan')}
            detail={plan.price > 0 ? t('profile.plan_price', { name: plan.name, price: plan.price }) : t('profile.plan_free', { name: plan.name })}
        >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <p className="text-[11.5px] text-text-secondary">{t('profile.ai_credits')}</p>
                    <p className="mt-0.5 flex items-baseline gap-1.5">
                        <b className="font-display text-[22px] font-extrabold tracking-[-0.02em] text-text-primary">
                            {formatNumber(plan.credits)}
                        </b>
                        {plan.monthly_credits > 0 && (
                            <span className="text-[12px] text-text-secondary">
                                {t('profile.credits_of_month', { total: formatNumber(plan.monthly_credits) })}
                            </span>
                        )}
                    </p>

                    {plan.monthly_credits > 0 && (
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-bg-subtle">
                            <div
                                className="h-full rounded-full bg-brand-fill"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                    )}
                </div>

                <div>
                    <p className="text-[11.5px] text-text-secondary">{t('profile.books')}</p>
                    <p className="mt-0.5 font-display text-[22px] font-extrabold tracking-[-0.02em] text-text-primary">
                        {plan.books}
                        <span className="ml-1.5 text-[12px] font-normal text-text-secondary">
                            {plan.book_limit === null
                                ? t('profile.of_unlimited')
                                : t('profile.of_limit', { limit: plan.book_limit })}
                        </span>
                    </p>
                </div>
            </div>

            <button
                type="button"
                data-a="profile:billing"
                onClick={() => router.get(route('billing.show'))}
                className="mt-3 text-[12.5px] font-semibold text-brand-primary hover:underline"
            >
                {t('menu.billing')}
            </button>
        </Box>
    );
}

/**
 * Which emails arrive, how often, and where.
 *
 * Security, billing and purchase emails are listed but locked on: they are
 * how somebody finds out their password changed or a payment failed, so
 * switching them off would switch off the warning, not the noise. The
 * server ignores those keys whatever this form sends.
 *
 * Activity about books (comments, reviews, exports, sales, partners) picks
 * a frequency — instant, a daily or weekly digest, or off — and whether it
 * also lands in the inbox. Marketing-style mail (weekly progress, news,
 * tips) keeps its on/off switch. Below the list: when digests arrive, quiet
 * hours, and the books muted from their shelf card.
 */
const FREQUENCIES = [
    { key: 'instant', tKey: 'notify.freq_instant' },
    { key: 'daily', tKey: 'notify.freq_daily' },
    { key: 'weekly', tKey: 'notify.freq_weekly' },
    { key: 'off', tKey: 'notify.freq_off' },
];

const HOURS = Array.from({ length: 24 }, (_, h) => h);
const DAYS = [1, 2, 3, 4, 5, 6, 7];

function EmailPreferences({ categories, notifications, saved }) {
    const { t, formatDate } = useI18n();
    const settings = notifications ?? {
        frequency: {},
        inbox: {},
        quiet_hours: null,
        digest_day: 1,
        digest_hour: 8,
        timezone: 'UTC',
        muted_books: [],
    };
    const { data, setData, setDefaults, patch, processing, isDirty, recentlySuccessful } = useForm({
        // Only the plain switches: activity categories go as a frequency.
        preferences: Object.fromEntries(
            categories.filter((c) => !c.mandatory && !c.digestible).map((c) => [c.key, c.enabled]),
        ),
        frequency: { ...settings.frequency },
        inbox: { ...settings.inbox },
        quiet_hours: settings.quiet_hours,
        digest_day: settings.digest_day,
        digest_hour: settings.digest_hour,
    });

    if (!categories.length) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        patch(route('profile.email-preferences'), {
            preserveScroll: true,
            onSuccess: () => setDefaults(),
        });
    };

    // A Monday, so day 1–7 are ISO weekdays; the hour on a fixed date.
    const dayName = (day) => formatDate(new Date(2026, 10, 8 + day), { weekday: 'long' });
    const hourName = (hour) => formatDate(new Date(2026, 0, 1, hour), { hour: 'numeric', minute: '2-digit' });
    const select =
        'h-[30px] rounded-control border border-border-input bg-bg-surface px-2 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0';

    return (
        <form onSubmit={submit} id="email-preferences" className="scroll-mt-6">
            <Box
                title={t('profile.email')}
                detail={t('profile.email_detail')}
            >
                <ul className="divide-y divide-border-divider rounded-tile border border-border-default">
                    {categories.map((category) => {
                        const id = `email-pref-${category.key}`;

                        return (
                            <li
                                key={category.key}
                                data-a={`profile:notify:row:${category.key}`}
                                className="flex flex-wrap items-start gap-x-3 gap-y-2 px-3.5 py-3"
                            >
                                <div className="min-w-0 flex-1 basis-[220px]">
                                    <label
                                        htmlFor={id}
                                        className="block text-[13px] font-semibold text-text-primary"
                                    >
                                        {category.label}
                                    </label>
                                    <p
                                        id={`${id}-detail`}
                                        className="mt-0.5 text-[11.5px] leading-relaxed text-text-secondary"
                                    >
                                        {category.description}
                                    </p>
                                </div>

                                {category.mandatory ? (
                                    <span className="mt-0.5 shrink-0 rounded-badge bg-bg-subtle px-2 py-0.5 text-[11px] font-medium text-text-secondary">
                                        {t('profile.always_on')}
                                    </span>
                                ) : category.digestible ? (
                                    <div className="flex shrink-0 items-center gap-3">
                                        <select
                                            id={id}
                                            aria-describedby={`${id}-detail`}
                                            data-a={`profile:notify:frequency:${category.key}`}
                                            value={data.frequency[category.key] ?? 'instant'}
                                            onChange={(e) =>
                                                setData('frequency', { ...data.frequency, [category.key]: e.target.value })
                                            }
                                            className={select}
                                        >
                                            {FREQUENCIES.map((f) => (
                                                <option key={f.key} value={f.key}>
                                                    {t(f.tKey)}
                                                </option>
                                            ))}
                                        </select>
                                        {category.key in data.inbox && (
                                            <label className="inline-flex items-center gap-1.5 text-[12px] text-text-secondary">
                                                <input
                                                    type="checkbox"
                                                    data-a={`profile:notify:inbox:${category.key}`}
                                                    checked={Boolean(data.inbox[category.key])}
                                                    onChange={(e) =>
                                                        setData('inbox', { ...data.inbox, [category.key]: e.target.checked })
                                                    }
                                                    aria-label={t('notify.inbox_for', { category: category.label })}
                                                    className="h-4 w-4 rounded border-border-input"
                                                />
                                                {t('notify.inbox')}
                                            </label>
                                        )}
                                    </div>
                                ) : (
                                    <Switch
                                        id={id}
                                        on={Boolean(data.preferences[category.key])}
                                        describedBy={`${id}-detail`}
                                        dataA={`profile:email:${category.key}`}
                                        onToggle={(on) =>
                                            setData('preferences', { ...data.preferences, [category.key]: on })
                                        }
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <fieldset data-a="profile:notify:schedule">
                        <legend className="text-[12.5px] font-semibold text-text-primary">
                            {t('notify.schedule_title')}
                        </legend>
                        <p className="mt-0.5 text-[11px] leading-relaxed text-text-secondary">
                            {t('notify.schedule_detail', { timezone: settings.timezone })}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <select
                                aria-label={t('notify.day')}
                                data-a="profile:notify:day"
                                value={data.digest_day}
                                onChange={(e) => setData('digest_day', Number(e.target.value))}
                                className={select}
                            >
                                {DAYS.map((d) => (
                                    <option key={d} value={d}>
                                        {dayName(d)}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label={t('notify.time')}
                                data-a="profile:notify:hour"
                                value={data.digest_hour}
                                onChange={(e) => setData('digest_hour', Number(e.target.value))}
                                className={select}
                            >
                                {HOURS.map((h) => (
                                    <option key={h} value={h}>
                                        {hourName(h)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </fieldset>

                    <fieldset data-a="profile:notify:quiet">
                        <legend className="text-[12.5px] font-semibold text-text-primary">
                            {t('notify.quiet_title')}
                        </legend>
                        <p className="mt-0.5 text-[11px] leading-relaxed text-text-secondary">
                            {t('notify.quiet_detail')}
                        </p>
                        <label className="mt-2 inline-flex items-center gap-1.5 text-[12.5px] text-text-body">
                            <input
                                type="checkbox"
                                data-a="profile:notify:quiet-on"
                                checked={Boolean(data.quiet_hours)}
                                onChange={(e) =>
                                    setData('quiet_hours', e.target.checked ? { start: 22, end: 7 } : null)
                                }
                                className="h-4 w-4 rounded border-border-input"
                            />
                            {t('notify.quiet_on')}
                        </label>
                        {data.quiet_hours && (
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-[12px] text-text-secondary">
                                <span>{t('notify.from')}</span>
                                <select
                                    aria-label={t('notify.quiet_start')}
                                    data-a="profile:notify:quiet-start"
                                    value={data.quiet_hours.start}
                                    onChange={(e) =>
                                        setData('quiet_hours', { ...data.quiet_hours, start: Number(e.target.value) })
                                    }
                                    className={select}
                                >
                                    {HOURS.map((h) => (
                                        <option key={h} value={h}>
                                            {hourName(h)}
                                        </option>
                                    ))}
                                </select>
                                <span>{t('notify.to')}</span>
                                <select
                                    aria-label={t('notify.quiet_end')}
                                    data-a="profile:notify:quiet-end"
                                    value={data.quiet_hours.end}
                                    onChange={(e) =>
                                        setData('quiet_hours', { ...data.quiet_hours, end: Number(e.target.value) })
                                    }
                                    className={select}
                                >
                                    {HOURS.map((h) => (
                                        <option key={h} value={h}>
                                            {hourName(h)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </fieldset>
                </div>

                <MutedBooks books={settings.muted_books} />

                <div className="mt-4 flex items-center gap-3">
                    <button
                        data-a="profile:email:save"
                        disabled={processing || !isDirty}
                        className="h-[34px] rounded-control bg-brand-fill px-3.5 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        {processing ? t('editor.save_saving') : t('profile.save_email_prefs')}
                    </button>
                    {(recentlySuccessful || (saved && !isDirty)) && (
                        <span role="status" className="text-[12.5px] text-status-success">
                            {t('profile.saved')}
                        </span>
                    )}
                </div>
            </Box>
        </form>
    );
}

function Switch({ id, on, describedBy, dataA, onToggle }) {
    const { t } = useI18n();

    return (
        <button
            id={id}
            type="button"
            role="switch"
            aria-checked={on}
            aria-describedby={describedBy}
            data-a={dataA}
            onClick={() => onToggle(!on)}
            className={`relative mt-0.5 h-[22px] w-[38px] shrink-0 rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 ${
                on ? 'bg-brand-fill' : 'bg-border-input'
            }`}
        >
            <span className="sr-only">{on ? t('profile.on') : t('profile.off')}</span>
            <span
                aria-hidden="true"
                className={`absolute top-[3px] h-4 w-4 rounded-full bg-white shadow transition-all ${
                    on ? 'left-[19px]' : 'left-[3px]'
                }`}
            />
        </button>
    );
}

/** Books muted from their shelf card; unmuting is immediate, not part of Save. */
function MutedBooks({ books }) {
    const { t } = useI18n();

    return (
        <div data-a="profile:notify:muted" className="mt-4">
            <h3 className="text-[12.5px] font-semibold text-text-primary">{t('notify.muted_title')}</h3>
            <p className="mt-0.5 text-[11px] leading-relaxed text-text-secondary">
                {books.length ? t('notify.muted_detail') : t('notify.muted_none')}
            </p>
            {books.length > 0 && (
                <ul className="mt-2 divide-y divide-border-divider rounded-tile border border-border-default">
                    {books.map((book) => (
                        <li key={book.id} className="flex items-center gap-3 px-3.5 py-2">
                            <span className="min-w-0 flex-1 truncate text-[12.5px] text-text-body">{book.title}</span>
                            <button
                                type="button"
                                data-a={`profile:notify:unmute:${book.id}`}
                                aria-label={t('notify.unmute_label', { title: book.title })}
                                onClick={() =>
                                    router.put(route('books.mute', book.id), { muted: false }, { preserveScroll: true })
                                }
                                className="shrink-0 text-[12px] font-semibold text-brand-primary hover:underline"
                            >
                                {t('notify.unmute')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Box({ title, detail, children, danger = false }) {
    return (
        <section
            className={`rounded-panel border bg-bg-surface p-5 ${
                danger ? 'border-status-danger/25' : 'border-border-default'
            }`}
        >
            <h2
                className={`text-[14px] font-semibold ${
                    danger ? 'text-status-danger' : 'text-text-primary'
                }`}
            >
                {title}
            </h2>
            {detail && (
                <p className="mt-1 mb-3 text-[11.5px] leading-relaxed text-text-secondary">
                    {detail}
                </p>
            )}
            {children}
        </section>
    );
}

function Field({ label, error, children, className = '' }) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-[12px] font-medium text-text-secondary">
                {label}
            </span>
            {children}
            {error && (
                <span
                    role="alert"
                    className="mt-1 block text-[11px] font-medium text-status-danger"
                >
                    {error}
                </span>
            )}
        </label>
    );
}

function Hint({ children }) {
    return <span className="mt-1 block text-[10.5px] text-text-secondary">{children}</span>;
}

const input = (error) =>
    `w-full rounded-control border px-2.5 py-1.5 text-[13px] focus:ring-0 ${
        error
            ? 'border-status-danger focus:border-status-danger'
            : 'border-border-input focus:border-brand-primary'
    }`;
