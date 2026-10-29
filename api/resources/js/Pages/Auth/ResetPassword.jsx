import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useI18n } from '@/i18n';

export default function ResetPassword({ token, email }) {
    const { t } = useI18n();
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title={t('reset.title')} />

            <AuthHeading
                title={t('reset.title')}
                subtitle={t('reset.subtitle')}
            />

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="email"
                    label={t('signin.email')}
                    type="email"
                    value={data.email}
                    error={errors.email}
                    autoComplete="username"
                    onChange={(value) => setData('email', value)}
                />

                <AuthField
                    id="password"
                    label={t('reset.new_password')}
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="new-password"
                    placeholder={t('signin.new_password_placeholder')}
                    required
                    isFocused
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

                <AuthSubmit processing={processing}>{t('reset.submit')}</AuthSubmit>
            </form>
        </GuestLayout>
    );
}
