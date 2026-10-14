import { AuthHeading } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * Accepting an invitation to a book.
 *
 * Four states, kept apart deliberately: an expired link and an unknown one
 * lead a person to different next steps, and telling them apart is the
 * difference between "ask for a new invite" and "check the address".
 */
export default function Invitation({ state, token, invitation, email_matches: emailMatches }) {
    const { auth } = usePage().props;

    if (state !== 'open') {
        return (
            <GuestLayout>
                <Head title="Invitation" />
                <Closed state={state} />
            </GuestLayout>
        );
    }

    const signedIn = Boolean(auth?.user);

    return (
        <GuestLayout>
            <Head title={`Join ${invitation.book_title}`} />

            <AuthHeading
                title="You have been invited"
                subtitle={
                    invitation.invited_by
                        ? `${invitation.invited_by} invited you to work on a book.`
                        : 'Someone invited you to work on a book.'
                }
            />

            <div className="rounded-xl border border-border-default bg-bg-subtle p-4">
                <p className="font-display text-[17px] font-bold tracking-[-0.02em] text-text-primary">
                    {invitation.book_title}
                </p>
                <p className="mt-1.5 text-[13px] text-text-secondary">
                    As a <strong className="text-text-body">{invitation.role}</strong>,
                    invited to {invitation.email}.
                </p>
                <p className="mt-2.5 text-[12px] leading-relaxed text-text-secondary">
                    {
                        {
                            editor: 'Editors can write and edit the manuscript.',
                            reviewer: 'Reviewers can read the manuscript and raise findings, but not change it.',
                            viewer: 'Viewers can read the manuscript.',
                        }[invitation.role]
                    }
                </p>
            </div>

            {!signedIn ? (
                <div className="mt-6 space-y-3">
                    <p className="text-[13px] leading-relaxed text-text-secondary">
                        Sign in as <strong>{invitation.email}</strong> to accept,
                        or create an account with that address.
                    </p>
                    <div className="flex gap-2.5">
                        <Link
                            href={route('login')}
                            data-a="invitation:signin"
                            className="flex h-[42px] flex-1 items-center justify-center rounded-xl bg-brand-primary text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover"
                        >
                            Sign in
                        </Link>
                        <Link
                            href={route('register')}
                            data-a="invitation:register"
                            className="flex h-[42px] flex-1 items-center justify-center rounded-xl border border-border-input bg-bg-surface text-[14px] font-semibold text-text-primary transition hover:border-brand-primary-border"
                        >
                            Create account
                        </Link>
                    </div>
                </div>
            ) : emailMatches ? (
                <button
                    data-a="invitation:accept"
                    onClick={() => router.post(route('invitations.accept', token))}
                    className="mt-6 h-[46px] w-full rounded-xl bg-brand-primary text-[14.5px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover"
                >
                    Accept and open the book
                </button>
            ) : (
                // Signed in as the wrong person. Accepting would attach the
                // invitation to an account the owner did not invite.
                <div className="mt-6 rounded-xl bg-status-warning-bg p-4">
                    <p className="text-[13px] leading-relaxed text-status-warning">
                        This invitation was sent to{' '}
                        <strong>{invitation.email}</strong>, but you are signed in
                        as <strong>{auth.user.email}</strong>. Sign out and sign
                        back in with the invited address.
                    </p>
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        data-a="invitation:signout"
                        className="mt-3 text-[13px] font-semibold text-brand-primary hover:underline"
                    >
                        Sign out
                    </Link>
                </div>
            )}
        </GuestLayout>
    );
}

function Closed({ state }) {
    const copy = {
        expired: {
            title: 'This invitation has expired',
            body: 'Invitation links are good for two weeks. Ask whoever invited you to send a new one — they can resend it from the book’s team page.',
        },
        accepted: {
            title: 'Already accepted',
            body: 'This invitation has been used. Sign in and the book will be on your shelf.',
        },
        unknown: {
            title: 'We do not recognise this link',
            body: 'Check that you copied the whole address from the email. If it keeps failing, ask for a fresh invitation.',
        },
    }[state];

    return (
        <>
            <AuthHeading title={copy.title} subtitle={copy.body} />

            <Link
                href={route('login')}
                data-a="invitation:closed:signin"
                className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-primary text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
            >
                Go to sign in
            </Link>
        </>
    );
}
