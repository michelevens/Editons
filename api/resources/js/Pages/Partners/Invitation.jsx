import { Agreement } from '@/Components/Partners/PartnerKit';
import StoreLayout from '@/Layouts/StoreLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * An author's invitation to partner for their books. Opens signed out, so
 * somebody without an account can read it; accepting needs an account
 * under the invited address.
 */
export default function Invitation({ token, open, seller, titles, email, signed_in_as: signedInAs, agreement }) {
    const form = useForm({ agreement_version: agreement.version, accept: false });
    const matches = signedInAs && signedInAs.toLowerCase() === email.toLowerCase();

    return (
        <StoreLayout>
            <Head title="Partner invitation" />
            <div className="mx-auto max-w-[600px] rounded-panel border border-border-default bg-bg-surface p-5" data-a="partners:invitation">
                <h1 className="font-display text-[24px] font-extrabold tracking-[-0.02em] text-text-primary">{seller} invited you to sell their books</h1>
                <p className="mt-2 text-[14px] text-text-body">
                    As an Écrivain Partner you share these books from a page of your own and earn a commission on each copy sold through it.
                </p>
                <ul className="mt-3 list-disc pl-5 text-[13.5px] text-text-body">
                    {titles.map((t) => (
                        <li key={t}>{t}</li>
                    ))}
                </ul>

                {!open ? (
                    <p className="mt-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">This invitation has expired or was already used.</p>
                ) : !signedInAs ? (
                    <p className="mt-4 text-[13.5px]">
                        It was sent to <strong>{email}</strong>.{' '}
                        <Link href={route('register')} className="font-semibold text-brand-primary hover:underline">Create a free account</Link> or{' '}
                        <Link href={route('login')} className="font-semibold text-brand-primary hover:underline">sign in</Link> with that address, then open this link again.
                    </p>
                ) : !matches ? (
                    <p className="mt-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                        You are signed in as {signedInAs}, but the invitation was sent to {email}. Sign in with that address to accept it.
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
                            I accept the Partner Agreement (version {agreement.version}).
                        </label>
                        {Object.values(form.errors).map((m) => (
                            <p key={m} className="text-[12.5px] text-status-danger">{m}</p>
                        ))}
                        <button type="submit" disabled={form.processing} data-a="partners:invitation:send" className="tap min-h-[44px] rounded-button bg-brand-primary px-5 text-[14px] font-semibold text-white disabled:opacity-60">
                            Accept and set up my page
                        </button>
                    </form>
                )}
            </div>
        </StoreLayout>
    );
}
