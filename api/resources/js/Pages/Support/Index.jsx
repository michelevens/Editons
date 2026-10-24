import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import { lastClientError } from '@/errorReporter';
import StatusLabel from '@/Components/Support/StatusLabel';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

/**
 * Help: write to Écrivain support, and the requests already sent.
 *
 * What support would ask first is attached without asking: the plan and
 * browser (by the server), the screen size, the page you came from and the
 * last error this browser hit. Nothing from the manuscript.
 */
export default function SupportIndex({ tickets, books, book_id: bookId }) {
    const { auth } = usePage().props;
    const lastError = lastClientError();
    const from = typeof document !== 'undefined' && document.referrer ? safePath(document.referrer) : null;

    const form = useForm({
        subject: '',
        body: '',
        book_id: bookId ?? '',
        context: {
            viewport: typeof window !== 'undefined' ? `${window.innerWidth}x${window.innerHeight}` : null,
            page: from,
            last_error: lastError,
        },
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('support.store'));
    };

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Help" />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main id="main" className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[760px] px-4 py-7 sm:px-8 sm:py-9">
                    <h1 className="font-display text-[28px] font-extrabold tracking-[-0.03em] text-text-primary">Help</h1>
                    <p className="mt-1 text-[13px] text-text-secondary">Write to Ecrivain support. The answer arrives here, in your inbox and by email.</p>

                    <form onSubmit={submit} className="mt-5 space-y-3 rounded-panel border border-border-default bg-bg-surface p-4 sm:p-5" data-a="support:form">
                        <div>
                            <label htmlFor="support-subject" className="text-[13px] font-medium text-text-primary">
                                What is it about?
                            </label>
                            <input
                                id="support-subject"
                                data-a="support:subject"
                                required
                                minLength={3}
                                maxLength={200}
                                value={form.data.subject}
                                onChange={(event) => form.setData('subject', event.target.value)}
                                className="mt-1 w-full rounded-control border border-border-input bg-bg-surface px-3 py-2.5 text-[15px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20"
                            />
                            {form.errors.subject && <p className="mt-1 text-[12.5px] text-status-danger">{form.errors.subject}</p>}
                        </div>

                        <div>
                            <label htmlFor="support-body" className="text-[13px] font-medium text-text-primary">
                                What happened, and what did you expect?
                            </label>
                            <textarea
                                id="support-body"
                                data-a="support:body"
                                required
                                minLength={10}
                                maxLength={10000}
                                rows={6}
                                value={form.data.body}
                                onChange={(event) => form.setData('body', event.target.value)}
                                className="mt-1 w-full rounded-control border border-border-input bg-bg-surface px-3 py-2.5 text-[15px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20"
                            />
                            {form.errors.body && <p className="mt-1 text-[12.5px] text-status-danger">{form.errors.body}</p>}
                        </div>

                        {books.length > 0 && (
                            <div>
                                <label htmlFor="support-book" className="text-[13px] font-medium text-text-primary">
                                    Which book? (optional)
                                </label>
                                <select
                                    id="support-book"
                                    data-a="support:book"
                                    value={form.data.book_id ?? ''}
                                    onChange={(event) => form.setData('book_id', event.target.value === '' ? '' : Number(event.target.value))}
                                    className="mt-1 w-full rounded-control border border-border-input bg-bg-surface px-3 py-2.5 text-[15px] text-text-primary"
                                >
                                    <option value="">Not about one book</option>
                                    {books.map((book) => (
                                        <option key={book.id} value={book.id}>
                                            {book.title}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <p className="text-[12px] text-text-muted">
                            Sent with it: your plan, browser and screen size{from ? ', the page you came from' : ''}
                            {lastError ? `, and the last error this browser saw (“${lastError.slice(0, 80)}”)` : ''}. Support reads your words, not your manuscript.
                        </p>

                        <button
                            type="submit"
                            data-a="support:send"
                            disabled={form.processing}
                            className="tap inline-flex min-h-[44px] items-center justify-center rounded-button bg-brand-fill px-5 text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            Send to support
                        </button>
                    </form>

                    {tickets.length > 0 && (
                        <section className="mt-7">
                            <h2 className="text-[15px] font-bold text-text-primary">Your requests</h2>
                            <ul className="mt-2 divide-y divide-border-divider rounded-panel border border-border-default bg-bg-surface" data-a="support:tickets">
                                {tickets.map((t) => (
                                    <li key={t.id}>
                                        <Link href={route('support.show', t.id)} className="flex min-h-[52px] items-center gap-3 px-4 py-2 text-[14px] hover:bg-bg-subtle">
                                            <span className="min-w-0 flex-1 truncate text-text-primary">{t.subject}</span>
                                            <StatusLabel status={t.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </main>

            <MobileNav current="account" />
        </div>
    );
}

function safePath(url) {
    try {
        const parsed = new URL(url);

        return parsed.origin === window.location.origin ? parsed.pathname : null;
    } catch {
        return null;
    }
}
