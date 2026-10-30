import { AuthField, AuthHeading, AuthSubmit } from '@/Components/Auth/AuthForm';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Fragment } from 'react';
import { useI18n } from '@/i18n';

/**
 * Joining Écrivain from an invitation (/join/{token}).
 *
 * The address is the invitation's and cannot be changed here: the link was
 * sent to it, and it is what makes the email verified. Works while new
 * sign-ups are closed. A link that is no longer good is a page that says
 * so and how to ask for another one, never an error.
 */
export default function Join({ state, token, decline, invitation, signed_in_as: signedInAs, email_matches: emailMatches, account_exists: accountExists, contact }) {
    const { t, formatDate } = useI18n();

    if (state !== 'pending') {
        return (
            <GuestLayout>
                <Head title={t('invite.head')} />
                <Closed state={state} contact={contact} />
            </GuestLayout>
        );
    }

    if (decline) {
        return (
            <GuestLayout>
                <Head title={t('join.decline_head')} />
                <AuthHeading title={t('join.not_interested')} subtitle={t('join.decline_subtitle', { email: invitation.email })} />
                <button
                    type="button"
                    data-a="join:decline:confirm"
                    onClick={() => router.post(route('join.decline', token))}
                    className="h-[46px] w-full rounded-xl border border-border-input bg-bg-surface text-[14.5px] font-semibold text-text-primary transition hover:border-brand-primary-border"
                >
                    {t('join.dont_invite')}
                </button>
                <Link href={route('join.show', token)} className="mt-4 block text-center text-[13px] font-semibold text-brand-primary hover:underline">
                    {t('join.changed_mind')}
                </Link>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout>
            <Head title={t('join.head')} />

            <AuthHeading
                title={invitation.name ? t('join.welcome', { name: invitation.name.split(' ')[0] }) : t('join.invited')}
                subtitle={t('join.subtitle')}
            />

            {invitation.perks.length > 0 && (
                <div className="mb-6 rounded-xl bg-brand-primary-subtle px-3.5 py-3" data-a="join:perks">
                    <p className="text-[12.5px] font-semibold text-outline-active-text">{t('join.included')}</p>
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
                            {rich(t('join.wrong_account', { email: '\u0001', me: '\u0002' }), {
                                '\u0001': <strong>{invitation.email}</strong>,
                                '\u0002': <strong>{signedInAs}</strong>,
                            })}
                        </p>
                        <Link href={route('logout')} method="post" as="button" data-a="join:signout" className="mt-3 text-[13px] font-semibold text-brand-primary hover:underline">
                            {t('signin.sign_out')}
                        </Link>
                    </div>
                )
            ) : accountExists ? (
                <div className="space-y-3" data-a="join:has-account">
                    <p className="text-[13px] leading-relaxed text-text-secondary">
                        {rich(t('join.has_account', { email: '\u0001' }), { '\u0001': <strong>{invitation.email}</strong> })}
                    </p>
                    <Link href={route('login')} data-a="join:signin" className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover">
                        {t('signin.sign_in')}
                    </Link>
                </div>
            ) : (
                <RegisterForm token={token} invitation={invitation} />
            )}

            <p className="mt-6 text-center text-[12px] text-text-secondary">
                {t('join.open_until', { date: formatDate(invitation.expires_at, { dateStyle: 'long' }) })}{' '}
                <Link href={route('join.show', { token, decline: 1 })} data-a="join:decline" className="underline-offset-2 hover:underline">
                    {t('join.not_interested')}
                </Link>
            </p>
        </GuestLayout>
    );
}

function RegisterForm({ token, invitation }) {
    const { t } = useI18n();
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
                label={t('signin.email')}
                type="email"
                value={invitation.email}
                error={errors.email}
                hint={t('join.email_hint')}
                readOnly
                aria-readonly="true"
                data-a="join:email"
                onChange={() => {}}
            />
            <AuthField id="name" label={t('register.name')} value={data.name} error={errors.name} autoComplete="name" required isFocused onChange={(value) => setData('name', value)} />
            <AuthField
                id="password"
                label={t('signin.password')}
                type="password"
                value={data.password}
                error={errors.password}
                autoComplete="new-password"
                hint={t('register.password_hint')}
                required
                onChange={(value) => setData('password', value)}
            />
            <AuthField
                id="password_confirmation"
                label={t('signin.confirm_password')}
                type="password"
                value={data.password_confirmation}
                error={errors.password_confirmation}
                autoComplete="new-password"
                required
                onChange={(value) => setData('password_confirmation', value)}
            />
            <AuthSubmit processing={processing}>{t('register.head')}</AuthSubmit>
        </form>
    );
}

function Accept({ token }) {
    const { t } = useI18n();
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
                {t('join.accept')}
            </button>
            {form.errors.email && <p role="alert" className="mt-2 text-[12.5px] text-status-danger">{form.errors.email}</p>}
        </div>
    );
}

function Closed({ state, contact }) {
    const { t } = useI18n();
    const copy = {
        expired: {
            title: t('invite.expired_title'),
            body: t('join.expired_body'),
        },
        revoked: {
            title: t('join.revoked_title'),
            body: t('join.revoked_body'),
        },
        accepted: {
            title: t('invite.accepted_title'),
            body: t('join.accepted_body'),
        },
        declined: {
            title: t('join.declined_title'),
            body: t('join.declined_body'),
        },
        unknown: {
            title: t('invite.unknown_title'),
            body: t('join.unknown_body'),
        },
    }[state] ?? { title: t('join.broken_title'), body: t('join.broken_body') };

    return (
        <div data-a={`join:closed:${state}`}>
            <AuthHeading title={copy.title} subtitle={copy.body} />

            {state === 'accepted' ? (
                <Link href={route('login')} className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover">
                    {t('invite.go_sign_in')}
                </Link>
            ) : (
                <a
                    href={`mailto:${contact}?subject=${encodeURIComponent(t('join.mail_subject'))}`}
                    data-a="join:request-new"
                    className="flex h-[46px] w-full items-center justify-center rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white transition hover:bg-brand-primary-hover"
                >
                    {t('join.request_new')}
                </a>
            )}
        </div>
    );
}

/** A translated sentence with elements in it: each \u0001.. marker param becomes its element. */
function rich(text, elements) {
    return text.split(/([\u0001-\u0008])/).map((part, i) =>
        elements[part] !== undefined ? <Fragment key={i}>{elements[part]}</Fragment> : part,
    );
}
