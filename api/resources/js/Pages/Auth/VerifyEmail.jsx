import { AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useI18n } from '@/i18n';

export default function VerifyEmail({ status }) {
    const { t } = useI18n();
    const { post, processing } = useForm({});

    const submit = (event) => {
        event.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title={t('verify.head')} />

            <AuthHeading
                title={t('verify.title')}
                subtitle={t('verify.subtitle')}
            />

            {status === 'verification-link-sent' && (
                <div className="mb-5 rounded-xl bg-status-success-bg px-3.5 py-3 text-[13px] font-medium text-status-success">
                    {t('verify.sent')}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <AuthSubmit processing={processing}>{t('verify.resend')}</AuthSubmit>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="font-semibold text-brand-primary hover:text-outline-active-text"
                >
                    {t('signin.sign_out')}
                </Link>
            </p>
        </GuestLayout>
    );
}
