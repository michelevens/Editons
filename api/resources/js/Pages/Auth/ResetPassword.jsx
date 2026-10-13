import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function ResetPassword({ token, email }) {
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
            <Head title="Choose a new password" />

            <AuthHeading
                title="Choose a new password"
                subtitle="Signing in again afterwards will use this password."
            />

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="email"
                    label="Email address"
                    type="email"
                    value={data.email}
                    error={errors.email}
                    autoComplete="username"
                    onChange={(value) => setData('email', value)}
                />

                <AuthField
                    id="password"
                    label="New password"
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="new-password"
                    placeholder="At least 8 characters"
                    required
                    isFocused
                    onChange={(value) => setData('password', value)}
                />

                <AuthField
                    id="password_confirmation"
                    label="Confirm password"
                    type="password"
                    value={data.password_confirmation}
                    error={errors.password_confirmation}
                    autoComplete="new-password"
                    placeholder="Type it again"
                    required
                    onChange={(value) => setData('password_confirmation', value)}
                />

                <AuthSubmit processing={processing}>Save new password</AuthSubmit>
            </form>
        </GuestLayout>
    );
}
