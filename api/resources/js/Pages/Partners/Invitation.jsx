import { Agreement } from '@/Components/Partners/PartnerKit';
import StoreLayout from '@/Layouts/StoreLayout';
import { useI18n } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * An author's invitation to partner for their books. Opens signed out, so
 * somebody without an account can read it; accepting needs an account
 * under the invited address.
 */
export default function Invitation({ token, open, seller, titles, email, signed_in_as: signedInAs, agreement }) {
    const { t } = useI18n();
    const form = useForm({ agreement_version: agreement.version, accept: false });
    const matches = signedInAs && signedInAs.toLowerCase() === email.toLowerCase();

    return (
        <StoreLayout>
            <Head title={t('partner.invitation_title')} />
            <div className="mx-auto max-w-[600px] rounded-panel border border-border-default bg-bg-surface p-5" data-a="partners:invitation">
                <h1 className="font-display text-[24px] font-extrabold tracking-[-0.02em] text-text-primary">{t('partner.invitation_heading', { seller })}</h1>
                <p className="mt-2 text-[14px] text-text-body">
                    {t('partner.invitation_intro')}
                </p>
                <ul className="mt-3 list-disc pl-5 text-[13.5px] text-text-body">
                    {titles.map((title) => (
                        <li key={title}>{title}</li>
                    ))}
                </ul>

                {!open ? (
                    <p className="mt-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">{t('partner.invitation_expired')}</p>
                ) : !signedInAs ? (
                    <p className="mt-4 text-[13.5px]">
                        {t('partner.invitation_sent_to')} <strong>{email}</strong>.{' '}
                        <Link href={route('register')} className="font-semibold text-brand-primary hover:underline">{t('partner.invitation_create_account')}</Link> {t('partner.invitation_or')}{' '}
                        <Link href={route('login')} className="font-semibold text-brand-primary hover:underline">{t('partner.invitation_sign_in')}</Link> {t('partner.invitation_then_open')}
                    </p>
                ) : !matches ? (
                    <p className="mt-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                        {t('partner.invitation_wrong_account', { signed_in_as: signedInAs, email })}
                    </p>
                ) : (
                    <form
                        className="mt-4 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('partners.invitation.accept', token));
                        }}
                    >
                        <Agreement agreement={agreement} />
                        <label className="flex items-start gap-2 text-[13.5px]">
                            <input type="checkbox" className="mt-1" required checked={form.data.accept} onChange={(e) => form.setData('accept', e.target.checked)} data-a="partners:invitation:accept" />
                            {t('partner.invitation_accept', { version: agreement.version })}
                        </label>
                        {Object.values(form.errors).map((m) => (
                            <p key={m} className="text-[12.5px] text-status-danger">{m}</p>
                        ))}
                        <button type="submit" disabled={form.processing} data-a="partners:invitation:send" className="tap min-h-[44px] rounded-button bg-brand-primary px-5 text-[14px] font-semibold text-white disabled:opacity-60">
                            {t('partner.invitation_submit')}
                        </button>
                    </form>
                )}
            </div>
        </StoreLayout>
    );
}
