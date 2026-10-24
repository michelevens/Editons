import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

/** The second step of signing in: a code from the app, or a recovery code. */
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', recovery_code: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('two-factor.challenge'), { onFinish: () => reset('code', 'recovery_code') });
    };

    return (
        <GuestLayout>
            <Head title="Two-factor sign-in" />

            <AuthHeading
                title="Two-factor sign-in"
                subtitle={
                    recovery
                        ? 'Enter one of the recovery codes you saved when you turned two-factor on. Each works once.'
                        : 'Enter the six-digit code from your authenticator app.'
                }
            />

            <form onSubmit={submit} className="space-y-5">
                {recovery ? (
                    <AuthField
                        id="recovery_code"
                        label="Recovery code"
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
                        label="Code"
                        value={data.code}
                        error={errors.code}
                        autoComplete="one-time-code"
                        inputMode="numeric"
                        required
                        isFocused
                        onChange={(value) => setData('code', value)}
                    />
                )}

                <AuthSubmit processing={processing}>Sign in</AuthSubmit>
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
                    {recovery ? 'Use a code from the app' : 'Use a recovery code'}
                </button>
                <Link href={route('login')} className="text-text-secondary hover:underline">
                    Start again
                </Link>
            </div>
        </GuestLayout>
    );
}
