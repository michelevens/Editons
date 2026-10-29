import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useI18n } from '@/i18n';

export default function ForgotPassword({ status }) {
    const { t } = useI18n();
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title={t('forgot.title')} />

            <AuthHeading
                title={t('forgot.title')}
                subtitle={t('forgot.subtitle')}
            />

            {status && (
                <div className="mb-5 rounded-xl bg-status-success-bg px-3.5 py-3 text-[13px] font-medium text-status-success">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="email"
                    label={t('signin.email')}
                    type="email"
                    value={data.email}
                    error={errors.email}
                    autoComplete="username"
                    placeholder={t('signin.email_placeholder')}
                    required
                    isFocused
                    onChange={(value) => setData('email', value)}
                />

                <AuthSubmit processing={processing}>{t('forgot.submit')}</AuthSubmit>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                {t('forgot.remembered')}{' '}
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
