import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function ConfirmPassword() {
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
            <Head title="Confirm your password" />

            <AuthHeading
                title="Confirm your password"
                subtitle="This is a protected area. Please confirm your password before continuing."
            />

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="password"
                    label="Password"
                    type="password"
                    value={data.password}
                    error={errors.password}
                    autoComplete="current-password"
                    placeholder="Enter your password"
                    required
                    isFocused
                    onChange={(value) => setData('password', value)}
                />

                <AuthSubmit processing={processing}>Confirm</AuthSubmit>
            </form>
        </GuestLayout>
    );
}
