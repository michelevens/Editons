import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useI18n } from '@/i18n';

/** The second step of signing in: a code from the app, or a recovery code. */
export default function TwoFactorChallenge() {
    const { t } = useI18n();
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', recovery_code: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('two-factor.challenge'), { onFinish: () => reset('code', 'recovery_code') });
    };

    return (
        <GuestLayout>
            <Head title={t('twofactor.title')} />

            <AuthHeading
                title={t('twofactor.title')}
                subtitle={recovery ? t('twofactor.subtitle_recovery') : t('twofactor.subtitle')}
            />

            <form onSubmit={submit} className="space-y-5">
                {recovery ? (
                    <AuthField
                        id="recovery_code"
                        label={t('twofactor.recovery_code')}
                        value={data.recovery_code}
                        error={errors.recovery_code}
                        autoComplete="off"
                        required
                        isFocused
                        onChange={(value) => setData('recovery_code', value)}
                    />
                ) : (
                    <AuthField
                        id="code"
                        label={t('twofactor.code')}
                        value={data.code}
                        error={errors.code}
                        autoComplete="one-time-code"
                        inputMode="numeric"
                        required
                        isFocused
                        onChange={(value) => setData('code', value)}
                    />
                )}

                <AuthSubmit processing={processing}>{t('signin.sign_in')}</AuthSubmit>
            </form>

            <div className="mt-5 flex justify-between text-[13px]">
                <button
                    type="button"
                    data-a="2fa:toggle-recovery"
                    onClick={() => {
                        setRecovery((value) => !value);
                        reset();
                    }}
                    className="text-brand-primary hover:underline"
                >
                    {recovery ? t('twofactor.use_app') : t('twofactor.use_recovery')}
                </button>
                <Link href={route('login')} className="text-text-secondary hover:underline">
                    {t('twofactor.start_again')}
                </Link>
            </div>
        </GuestLayout>
    );
}
