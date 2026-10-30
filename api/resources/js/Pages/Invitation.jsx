import { AuthHeading } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import { useI18n } from '@/i18n';

/**
 * Accepting an invitation to a book.
 *
 * Four states, kept apart deliberately: an expired link and an unknown one
 * lead a person to different next steps, and telling them apart is the
 * difference between "ask for a new invite" and "check the address".
 */
export default function Invitation({ state, token, invitation, email_matches: emailMatches }) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    if (state !== 'open') {
        return (
            <GuestLayout>
                <Head title={t('invite.head')} />
                <Closed state={state} />
            </GuestLayout>
        );
    }

    const signedIn = Boolean(auth?.user);

    return (
        <GuestLayout>
            <Head title={t('invite.head_join', { title: invitation.book_title })} />

            <AuthHeading
                title={t('invite.title')}
                subtitle={
                    invitation.invited_by
                        ? t('invite.invited_by', { name: invitation.invited_by })
                        : t('invite.invited_someone')
                }
            />

            <div className="rounded-xl border border-border-default bg-bg-subtle p-4">
                <p className="font-display text-[17px] font-bold tracking-[-0.02em] text-text-primary">
                    {invitation.book_title}
                </p>
                <p className="mt-1.5 text-[13px] text-text-secondary">
                    {rich(t('invite.as_role', { role: '\u0001', email: invitation.email }), {
                        '\u0001': <strong className="text-text-body">{invitation.role}</strong>,
                    })}
                </p>
                <p className="mt-2.5 text-[12px] leading-relaxed text-text-secondary">
                    {
                        {
                            editor: t('invite.role_editor'),
                            reviewer: t('invite.role_reviewer'),
                            viewer: t('invite.role_viewer'),
                        }[invitation.role]
                    }
                </p>
            </div>

            {!signedIn ? (
                <div className="mt-6 space-y-3">
                    <p className="text-[13px] leading-relaxed text-text-secondary">
                        {rich(t('invite.sign_in_as', { email: '\u0001' }), { '\u0001': <strong>{invitation.email}</strong> })}
                    </p>
                    <div className="flex gap-2.5">
                        <Link
                            href={route('login')}
                            data-a="invitation:signin"
                            className="flex h-[42px] flex-1 items-center justify-center rounded-xl bg-brand-fill text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover"
                        >
                            {t('signin.sign_in')}
                        </Link>
                        <Link
                            href={route('register')}
                            data-a="invitation:register"
                            className="flex h-[42px] flex-1 items-center justify-center rounded-xl border border-border-input bg-bg-surface text-[14px] font-semibold text-text-primary transition hover:border-brand-primary-border"
                        >
                            {t('register.submit')}
                        </Link>
                    </div>
                </div>
            ) : emailMatches ? (
                <button
                    data-a="invitation:accept"
                    onClick={() => router.post(route('invitations.accept', token))}
                    className="mt-6 h-[46px] w-full rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover"
                >
                    {t('invite.accept_open')}
                </button>
            ) : (
                // Signed in as the wrong person. Accepting would attach the
                // invitation to an account the owner did not invite.
                <div className="mt-6 rounded-xl bg-status-warning-bg p-4">
                    <p className="text-[13px] leading-relaxed text-status-warning">
                        {rich(t('invite.wrong_account', { email: '\u0001', me: '\u0002' }), {
                            '\u0001': <strong>{invitation.email}</strong>,
                            '\u0002': <strong>{auth.user.email}</strong>,
                        })}
                    </p>
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        data-a="invitation:signout"
                        className="mt-3 text-[13px] font-semibold text-brand-primary hover:underline"
                    >
                        {t('signin.sign_out')}
                    </Link>
                </div>
            )}
        </GuestLayout>
    );
}

function Closed({ state }) {
    const { t } = useI18n();
    const copy = {
        expired: {
            title: t('invite.expired_title'),
            body: t('invite.expired_body'),
        },
        accepted: {
            title: t('invite.accepted_title'),
            body: t('invite.accepted_body'),
        },
        unknown: {
            title: t('invite.unknown_title'),
            body: t('invite.unknown_body'),
        },
    }[state];

    return (
        <>
            <AuthHeading title={copy.title} subtitle={copy.body} />

            <Link
                href={route('login')}
                data-a="invitation:closed:signin"
                className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
            >
                {t('invite.go_sign_in')}
            </Link>
        </>
    );
}

/** A translated sentence with elements in it: each \u0001.. marker param becomes its element. */
function rich(text, elements) {
    return text.split(/([\u0001-\u0008])/).map((part, i) =>
        elements[part] !== undefined ? <Fragment key={i}>{elements[part]}</Fragment> : part,
    );
}
