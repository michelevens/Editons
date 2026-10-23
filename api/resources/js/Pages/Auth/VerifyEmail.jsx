import { AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (event) => {
        event.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Verify your email" />

            <AuthHeading
                title="Check your email"
                subtitle="We sent a verification link to the address you signed up with. Open it to finish setting up your account."
            />

            {status === 'verification-link-sent' && (
                <div className="mb-5 rounded-xl bg-status-success-bg px-3.5 py-3 text-[13px] font-medium text-status-success">
                    A fresh link is on its way to your inbox.
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <AuthSubmit processing={processing}>Resend the link</AuthSubmit>
            </form>

            <p className="mt-6 text-center text-[13.5px] text-text-secondary">
                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="font-semibold text-brand-primary hover:text-outline-active-text"
                >
                    Sign out
                </Link>
            </p>
        </GuestLayout>
    );
}
