import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useI18n } from '@/i18n';

export default function ConfirmPassword() {
    const { t } = useI18n();
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title={t('confirm.title')} />

            <AuthHeading
                title={t('confirm.title')}
                subtitle={t('confirm.subtitle')}
            />

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="password"
                    label={t('signin.password')}
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="current-password"
                    placeholder={t('signin.password_placeholder')}
                    required
                    isFocused
                    onChange={(value) => setData('password', value)}
                />

                <AuthSubmit processing={processing}>{t('confirm.submit')}</AuthSubmit>
            </form>
        </GuestLayout>
    );
}
