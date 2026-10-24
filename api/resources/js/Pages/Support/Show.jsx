import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import StatusLabel from '@/Components/Support/StatusLabel';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

/** One support request: the conversation with Ecrivain support, and a reply box. */
export default function SupportShow({ ticket }) {
    const { auth } = usePage().props;
    const form = useForm({ body: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('support.reply', ticket.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={ticket.subject} />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main id="main" className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[760px] px-4 py-7 sm:px-8 sm:py-9">
                    <Link href={route('support.index')} className="text-[13px] text-brand-primary hover:underline">
                        ← Help
                    </Link>
                    <div className="mt-2 flex flex-wrap items-start gap-2">
                        <h1 className="min-w-0 flex-1 break-words font-display text-[24px] font-extrabold tracking-[-0.02em] text-text-primary">{ticket.subject}</h1>
                        <StatusLabel status={ticket.status} />
                    </div>
                    {ticket.book && <p className="mt-1 text-[13px] text-text-secondary">About “{ticket.book.title}”</p>}

                    <ol className="mt-5 space-y-3" data-a="support:messages">
                        {ticket.messages.map((m) => (
                            <li key={m.id} className={`rounded-panel border px-4 py-3 text-[14px] ${m.from_staff ? 'border-brand-primary/30 bg-brand-primary-subtle' : 'border-border-default bg-bg-surface'}`}>
                                <p className="text-[12px] text-text-secondary">
                                    <span className="font-semibold text-text-primary">{m.author}</span> · {new Date(m.at).toLocaleString()}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap break-words">{m.body}</p>
                            </li>
                        ))}
                    </ol>

                    <form onSubmit={submit} className="mt-5 rounded-panel border border-border-default bg-bg-surface p-4">
                        <label htmlFor="support-reply" className="text-[13px] font-medium text-text-primary">
                            {ticket.status === 'closed' ? 'Write again (this reopens it)' : 'Reply'}
                        </label>
                        <textarea
                            id="support-reply"
                            data-a="support:reply"
                            required
                            rows={4}
                            maxLength={10000}
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                            className="mt-1 w-full rounded-control border border-border-input bg-bg-surface px-3 py-2.5 text-[15px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20"
                        />
                        {form.errors.body && <p className="mt-1 text-[12.5px] text-status-danger">{form.errors.body}</p>}
                        <button
                            type="submit"
                            data-a="support:reply:send"
                            disabled={form.processing || !form.data.body.trim()}
                            className="tap mt-2 inline-flex min-h-[44px] items-center justify-center rounded-button bg-brand-fill px-5 text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            Send
                        </button>
                    </form>
                </div>
            </main>

            <MobileNav current="account" />
        </div>
    );
}
