import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Reset your password" />

            <AuthHeading
                title="Reset your password"
                subtitle="Tell us the address on your account and we'll email a link to choose a new password."
            />

            {status && (
                <div className="mb-5 rounded-xl bg-status-success-bg px-3.5 py-3 text-[13px] font-medium text-status-success">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="email"
                    label="Email address"
                    type="email"
                    value={data.email}
                    error={errors.email}
                    autoComplete="username"
                    placeholder="you@example.com"
                    required
                    isFocused
                    onChange={(value) => setData('email', value)}
                />

                <AuthSubmit processing={processing}>Email reset link</AuthSubmit>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                Remembered it?{' '}
                <Link
                    href={route('login')}
                    className="font-semibold text-brand-primary hover:text-brand-primary-hover"
                >
                    Sign in
                </Link>
            </p>
        </GuestLayout>
    );
}
