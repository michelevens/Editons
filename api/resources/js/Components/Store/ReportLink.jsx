import Dialog from '@/Components/Dialog';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * "Report this book" (or this page) on the public storefront. No account
 * needed. The report goes to Écrivain's moderation queue; nothing happens
 * to the page until somebody there looks.
 */
const CATEGORIES = [
    ['copyright', 'It copies somebody else’s work'],
    ['illegal', 'Illegal content'],
    ['hate', 'Hate or harassment'],
    ['sexual_minors', 'Sexual content involving minors'],
    ['misleading', 'Misleading or a scam'],
    ['other', 'Something else'],
];

const field =
    'w-full rounded-control border border-border-input bg-bg-surface px-3 py-2 text-[13.5px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20';

export default function ReportLink({ url, label = 'Report this book' }) {
    const [open, setOpen] = useState(false);
    const [sent, setSent] = useState(false);
    const form = useForm({ category: '', details: '', email: '', website: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                setSent(true);
                form.reset();
            },
        });
    };

    return (
        <>
            <button type="button" data-a="store:report" onClick={() => { setSent(false); setOpen(true); }} className="text-text-secondary underline-offset-2 hover:text-text-primary hover:underline">
                {label}
            </button>
            {open && (
                <Dialog label={label} onClose={() => setOpen(false)} className="w-[460px] rounded-panel bg-bg-surface p-5">
                    {sent ? (
                        <div data-a="store:report:sent">
                            <h2 className="text-[16px] font-bold text-text-primary">Thank you</h2>
                            <p className="mt-1 text-[13px] text-text-body">Écrivain will look at it. If you left an address, we may write back.</p>
                            <div className="mt-4 flex justify-end">
                                <button type="button" data-autofocus onClick={() => setOpen(false)} className="tap rounded-button bg-brand-primary px-3.5 py-2 text-[13px] font-semibold text-white">
                                    Close
                                </button>
                            </div>
                        </div>
                    ) : (
                        <form onSubmit={submit} data-a="store:report:form">
                            <h2 className="text-[16px] font-bold text-text-primary">{label}</h2>
                            <p className="mt-1 text-[12.5px] text-text-secondary">Tell Écrivain what is wrong. The author is not told who reported it.</p>
                            <fieldset className="mt-3">
                                <legend className="text-[12.5px] font-medium text-text-primary">What is the problem?</legend>
                                <div className="mt-1 space-y-1">
                                    {CATEGORIES.map(([value, text]) => (
                                        <label key={value} className="flex items-center gap-2 text-[13px]">
                                            <input type="radio" name="category" value={value} data-a={`store:report:${value}`} checked={form.data.category === value} onChange={() => form.setData('category', value)} required />
                                            {text}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <label className="mt-3 block text-[12.5px] font-medium text-text-primary" htmlFor="report-details">
                                Details (optional)
                            </label>
                            <textarea id="report-details" data-a="store:report:details" rows={3} maxLength={2000} value={form.data.details} onChange={(e) => form.setData('details', e.target.value)} className={`${field} mt-1`} />
                            <label className="mt-3 block text-[12.5px] font-medium text-text-primary" htmlFor="report-email">
                                Your email, if you would like a reply (optional)
                            </label>
                            <input id="report-email" type="email" data-a="store:report:email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={`${field} mt-1`} />
                            {/* Left empty by people; filled by scripts. */}
                            <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} className="hidden" />
                            {Object.keys(form.errors).length > 0 && (
                                <p role="alert" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[12.5px] text-status-danger">
                                    {Object.values(form.errors)[0]}
                                </p>
                            )}
                            <div className="mt-4 flex justify-end gap-2">
                                <button type="button" onClick={() => setOpen(false)} className="tap rounded-button px-3.5 py-2 text-[13px] text-text-secondary hover:bg-bg-subtle">
                                    Cancel
                                </button>
                                <button type="submit" data-a="store:report:send" disabled={form.processing} className="tap rounded-button bg-brand-primary px-3.5 py-2 text-[13px] font-semibold text-white disabled:opacity-60">
                                    Send report
                                </button>
                            </div>
                        </form>
                    )}
                </Dialog>
            )}
        </>
    );
}
