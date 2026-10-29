import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useI18n } from '@/i18n';

/** "A, B, C and D" */
function listOf(items, t) {
    if (items.length <= 1) return items.join('');

    return t('common.list_and', { head: items.slice(0, -1).join(', '), last: items[items.length - 1] });
}

export default function Register({ offer = null, signups_open: signupsOpen = true }) {
    const { t } = useI18n();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title={t('register.head')} />

            <AuthHeading
                title={t('register.title')}
                subtitle={
                    offer
                        ? t('register.subtitle_offer', {
                              books: offer.books,
                              free: offer.agents_free.length,
                              total: offer.agents_total,
                              agents: listOf(offer.agents_free, t),
                          })
                        : t('register.subtitle')
                }
            />

            {/* Closed from the admin console (new_signups_open): say so
                before the form rather than after somebody fills it in. */}
            {!signupsOpen && (
                <p
                    role="status"
                    data-a="register:closed"
                    className="mb-6 rounded-xl bg-status-warning-bg px-3.5 py-3 text-[13px] text-status-warning"
                >
                    {t('register.closed')}
                </p>
            )}

            {/* What the free plan actually gives, stated before the form
                rather than after the signup. The numbers are the configured
                plan limits, not marketing rounding. */}
            <div className="mb-6 flex gap-3 rounded-xl bg-brand-primary-subtle px-3.5 py-3">
                <svg
                    aria-hidden="true"
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="mt-0.5 shrink-0 text-outline-active-text"
                >
                    <path d="M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
                </svg>
                <p className="text-[12.5px] leading-relaxed text-outline-active-text">
                    {t('register.free_plan', { credits: offer?.credits ?? 100 })}
                </p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="name"
                    label={t('register.name')}
                    value={data.name}
                    error={errors.name}
                    autoComplete="name"
                    placeholder={t('register.name_placeholder')}
                    required
                    isFocused
                    onChange={(value) => setData('name', value)}
                />

                <AuthField
                    id="email"
                    label={t('signin.email')}
                    type="email"
                    value={data.email}
                    error={errors.email}
                    autoComplete="username"
                    placeholder={t('signin.email_placeholder')}
                    required
                    onChange={(value) => setData('email', value)}
                />

                <AuthField
                    id="password"
                    label={t('signin.password')}
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="new-password"
                    placeholder={t('signin.new_password_placeholder')}
                    hint={t('register.password_hint')}
                    required
                    onChange={(value) => setData('password', value)}
                />

                <AuthField
                    id="password_confirmation"
                    label={t('signin.confirm_password')}
                    type="password"
                    value={data.password_confirmation}
                    error={errors.password_confirmation}
                    autoComplete="new-password"
                    placeholder={t('signin.type_again')}
                    required
                    onChange={(value) => setData('password_confirmation', value)}
                />

                <AuthSubmit processing={processing}>{t('register.submit')}</AuthSubmit>

                <p className="text-center text-[12px] leading-relaxed text-text-secondary">
                    {t('register.yours')}
                </p>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                {t('register.have_account')}{' '}
                <Link
                    href={route('login')}
                    className="font-semibold text-brand-primary hover:text-outline-active-text"
                >
                    {t('signin.sign_in')}
                </Link>
            </p>
        </GuestLayout>
    );
}
