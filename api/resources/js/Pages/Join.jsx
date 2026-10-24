import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

/**
 * Joining Écrivain from an invitation (/join/{token}).
 *
 * The address is the invitation's and cannot be changed here: the link was
 * sent to it, and it is what makes the email verified. Works while new
 * sign-ups are closed. A link that is no longer good is a page that says
 * so and how to ask for another one, never an error.
 */
export default function Join({ state, token, decline, invitation, signed_in_as: signedInAs, email_matches: emailMatches, account_exists: accountExists, contact }) {
    if (state !== 'pending') {
        return (
            <GuestLayout>
                <Head title="Invitation" />
                <Closed state={state} contact={contact} />
            </GuestLayout>
        );
    }

    if (decline) {
        return (
            <GuestLayout>
                <Head title="No more invitations" />
                <AuthHeading title="Not interested?" subtitle={`We will not invite ${invitation.email} again. You can still create an account yourself whenever sign-ups are open.`} />
                <button
                    type="button"
                    data-a="join:decline:confirm"
                    onClick={() => router.post(route('join.decline', token))}
                    className="h-[46px] w-full rounded-xl border border-border-input bg-bg-surface text-[14.5px] font-semibold text-text-primary transition hover:border-brand-primary-border"
                >
                    Don’t invite me again
                </button>
                <Link href={route('join.show', token)} className="mt-4 block text-center text-[13px] font-semibold text-brand-primary hover:underline">
                    I changed my mind: show me the invitation
                </Link>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout>
            <Head title="Join Écrivain" />

            <AuthHeading
                title={invitation.name ? `Welcome, ${invitation.name.split(' ')[0]}` : 'You are invited'}
                subtitle="The Écrivain team invited you to write with us: chapters, editorial review, and print and ebook exports in one studio."
            />

            {invitation.perks.length > 0 && (
                <div className="mb-6 rounded-xl bg-brand-primary-subtle px-3.5 py-3" data-a="join:perks">
                    <p className="text-[12.5px] font-semibold text-outline-active-text">Included with your invitation</p>
                    <ul className="mt-1 list-disc pl-5 text-[12.5px] leading-relaxed text-outline-active-text">
                        {invitation.perks.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </div>
            )}

            {signedInAs ? (
                emailMatches ? (
                    <Accept token={token} />
                ) : (
                    // Signed in as somebody else: accepting would put the
                    // invitation, and its perks, on the wrong account.
                    <div className="rounded-xl bg-status-warning-bg p-4" data-a="join:wrong-account">
                        <p className="text-[13px] leading-relaxed text-status-warning">
                            This invitation was sent to <strong>{invitation.email}</strong>, but you are signed in as <strong>{signedInAs}</strong>. Sign out, then open the link again.
                        </p>
                        <Link href={route('logout')} method="post" as="button" data-a="join:signout" className="mt-3 text-[13px] font-semibold text-brand-primary hover:underline">
                            Sign out
                        </Link>
                    </div>
                )
            ) : accountExists ? (
                <div className="space-y-3" data-a="join:has-account">
                    <p className="text-[13px] leading-relaxed text-text-secondary">
                        <strong>{invitation.email}</strong> already has an account. Sign in and you will come back here to accept.
                    </p>
                    <Link href={route('login')} data-a="join:signin" className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover">
                        Sign in
                    </Link>
                </div>
            ) : (
                <RegisterForm token={token} invitation={invitation} />
            )}

            <p className="mt-6 text-center text-[12px] text-text-secondary">
                Open until {new Date(invitation.expires_at).toLocaleDateString(undefined, { dateStyle: 'long' })}.{' '}
                <Link href={route('join.show', { token, decline: 1 })} data-a="join:decline" className="underline-offset-2 hover:underline">
                    Not interested?
                </Link>
            </p>
        </GuestLayout>
    );
}

function RegisterForm({ token, invitation }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: invitation.name ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('join.register', token), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit} className="space-y-5" data-a="join:form">
            <AuthField
                id="email"
                label="Email address"
                type="email"
                value={invitation.email}
                error={errors.email}
                hint="The invitation was sent to this address, so it is the one your account uses."
                readOnly
                aria-readonly="true"
                data-a="join:email"
                onChange={() => {}}
            />
            <AuthField id="name" label="Your name" value={data.name} error={errors.name} autoComplete="name" required isFocused onChange={(value) => setData('name', value)} />
            <AuthField
                id="password"
                label="Password"
                type="password"
                value={data.password}
                error={errors.password}
                autoComplete="new-password"
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
                required
                onChange={(value) => setData('password_confirmation', value)}
            />
            <AuthSubmit processing={processing}>Create your account</AuthSubmit>
        </form>
    );
}

function Accept({ token }) {
    const form = useForm({});

    return (
        <div>
            <button
                type="button"
                data-a="join:accept"
                disabled={form.processing}
                onClick={() => form.post(route('join.accept', token))}
                className="h-[46px] w-full rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-60"
            >
                Accept the invitation
            </button>
            {form.errors.email && <p role="alert" className="mt-2 text-[12.5px] text-status-danger">{form.errors.email}</p>}
        </div>
    );
}

function Closed({ state, contact }) {
    const copy = {
        expired: {
            title: 'This invitation has expired',
            body: 'Invitation links stay open for a few weeks, and this one has run out. Write to us and we will send you a new one.',
        },
        revoked: {
            title: 'This invitation was withdrawn',
            body: 'The link no longer works. If you think that is a mistake, write to us and we will sort it out.',
        },
        accepted: {
            title: 'Already accepted',
            body: 'This invitation has been used. Sign in to open your studio.',
        },
        declined: {
            title: 'You will not be invited again',
            body: 'We have noted it. If you change your mind, write to us, or create an account yourself whenever sign-ups are open.',
        },
        unknown: {
            title: 'We do not recognise this link',
            body: 'Check that you opened the whole address from the email. If it keeps failing, write to us for a new invitation.',
        },
    }[state] ?? { title: 'This link does not work', body: 'Write to us for a new invitation.' };

    return (
        <div data-a={`join:closed:${state}`}>
            <AuthHeading title={copy.title} subtitle={copy.body} />

            {state === 'accepted' ? (
                <Link href={route('login')} className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover">
                    Go to sign in
                </Link>
            ) : (
                <a
                    href={`mailto:${contact}?subject=${encodeURIComponent('A new invitation, please')}`}
                    data-a="join:request-new"
                    className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                >
                    Request a new invitation
                </a>
            )}
        </div>
    );
}
