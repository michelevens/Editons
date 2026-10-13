import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import Checkbox from '@/Components/Checkbox';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Login({ status, canResetPassword, demo }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    // One click to sign in while the product is being built. The server only
    // shares `demo` when APP_ENV is local and the seeded account exists, so
    // this block cannot appear in production — see HandleInertiaRequests.
    const fillDemo = () => {
        setData({ email: demo.email, password: demo.password, remember: false });
    };

    const submit = (event) => {
        event.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Sign in" />

            <AuthHeading
                title="Welcome back"
                subtitle="Sign in to pick up where your manuscript left off."
            />

            {demo && (
                <div className="mb-5 rounded-xl border border-status-warning/30 bg-status-warning-bg p-3.5">
                    <p className="text-[12.5px] font-semibold text-status-warning">
                        Demo account
                    </p>
                    <p className="mt-1 font-mono text-[11.5px] text-status-warning/90">
                        {demo.email} / {demo.password}
                    </p>
                    <button
                        type="button"
                        onClick={fillDemo}
                        className="mt-2.5 rounded-lg bg-status-warning px-3 py-1.5 text-[11.5px] font-semibold text-white transition hover:opacity-90"
                    >
                        Fill demo credentials
                    </button>
                </div>
            )}

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
                    isFocused
                    onChange={(value) => setData('email', value)}
                />

                <AuthField
                    id="password"
                    label="Password"
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="current-password"
                    placeholder="Enter your password"
                    onChange={(value) => setData('password', value)}
                    action={
                        canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-[12.5px] font-medium text-brand-primary hover:text-brand-primary-hover"
                            >
                                Forgot password?
                            </Link>
                        )
                    }
                />

                <label className="flex cursor-pointer items-center gap-2.5">
                    <Checkbox
                        name="remember"
                        checked={data.remember}
                        onChange={(event) => setData('remember', event.target.checked)}
                    />
                    <span className="text-[13.5px] text-text-body">Remember me</span>
                </label>

                <AuthSubmit processing={processing}>Sign in</AuthSubmit>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                No account?{' '}
                <Link
                    href={route('register')}
                    className="font-semibold text-brand-primary hover:text-brand-primary-hover"
                >
                    Create one free
                </Link>
            </p>
        </GuestLayout>
    );
}
