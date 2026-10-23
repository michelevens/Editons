import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
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
            <Head title="Create your account" />

            <AuthHeading
                title="Start writing"
                subtitle="Your first book, all seven editorial agents, and a research library — free."
            />

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
                    The free plan includes 100 AI credits a month and needs no
                    card. Nothing is charged unless you choose a paid plan.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="name"
                    label="Your name"
                    value={data.name}
                    error={errors.name}
                    autoComplete="name"
                    placeholder="Jordan Lee"
                    required
                    isFocused
                    onChange={(value) => setData('name', value)}
                />

                <AuthField
                    id="email"
                    label="Email address"
                    type="email"
                    value={data.email}
                    error={errors.email}
                    autoComplete="username"
                    placeholder="you@example.com"
                    required
                    onChange={(value) => setData('email', value)}
                />

                <AuthField
                    id="password"
                    label="Password"
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="new-password"
                    placeholder="At least 8 characters"
                    hint="Eight characters minimum. A passphrase beats a short complicated one."
                    required
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

                <AuthSubmit processing={processing}>Create account</AuthSubmit>

                <p className="text-center text-[12px] leading-relaxed text-text-secondary">
                    Your manuscript stays yours. Export it as Markdown at any
                    time, and nothing you write is used to train a model.
                </p>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                Already have an account?{' '}
                <Link
                    href={route('login')}
                    className="font-semibold text-brand-primary hover:text-outline-active-text"
                >
                    Sign in
                </Link>
            </p>
        </GuestLayout>
    );
}
