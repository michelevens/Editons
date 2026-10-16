import AppHeader from '@/Components/Studio/AppHeader';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';

/**
 * The author's profile.
 *
 * Four things, in the order they matter to somebody writing a book: who the
 * cover says you are, how you like to work, what you are paying for, and the
 * account itself.
 *
 * Identity comes first because it is the part that leaves the application —
 * into eBook metadata, onto a store listing, into the About the Author page —
 * and the part nothing else can fill in for you.
 */
export default function Edit({ mustVerifyEmail, status, author, book_types: bookTypes, plan }) {
    const { auth } = usePage().props;

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Your profile" />

            <AppHeader
                active="books"
                user={auth?.user}
                account={auth?.account}
            />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[820px] px-8 py-9">
                    <h1 className="font-display text-[30px] font-extrabold tracking-[-0.03em] text-text-primary">
                        Your profile
                    </h1>
                    <p className="mt-1 text-[13px] text-text-secondary">
                        How your books are credited, and how you like to work
                    </p>

                    {status === 'profile-updated' && (
                        <p
                            role="status"
                            data-a="profile:saved"
                            className="mt-4 rounded-tile bg-status-success-bg px-3 py-2 text-[12.5px] text-status-success"
                        >
                            Saved.
                        </p>
                    )}

                    <AuthorForm
                        author={author}
                        bookTypes={bookTypes}
                        mustVerifyEmail={mustVerifyEmail}
                    />

                    <div className="mt-4 space-y-4">
                        <PlanCard plan={plan} />

                        <Box
                            title="Password"
                            detail="Changing it signs out your other sessions."
                        >
                            <UpdatePasswordForm className="max-w-full" />
                        </Box>

                        <Box
                            title="Delete your account"
                            detail="Everything goes: every book, every version, every export. There is no undo, and no copy kept."
                            danger
                        >
                            <DeleteUserForm className="max-w-full" />
                        </Box>
                    </div>
                </div>
            </main>
        </div>
    );
}

function AuthorForm({ author, bookTypes, mustVerifyEmail }) {
    const { auth } = usePage().props;

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: auth.user.name ?? '',
        email: auth.user.email ?? '',
        author_name: author.author_name ?? '',
        bio: author.bio ?? '',
        website: author.website ?? '',
        default_book_type: author.default_book_type ?? '',
        daily_word_goal: author.daily_word_goal ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-6 space-y-4">
            <Box
                title="How your books are credited"
                detail="The author name goes on the cover, into the eBook's metadata and onto store listings. It is often not the name on your account — a pen name, or a name with your credentials after it."
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Your name" error={errors.name}>
                        <input
                            value={data.name}
                            data-a="profile:name"
                            required
                            onChange={(event) => setData('name', event.target.value)}
                            className={input(errors.name)}
                        />
                        <Hint>What we call you in the application.</Hint>
                    </Field>

                    <Field label="Author name" error={errors.author_name}>
                        <input
                            value={data.author_name}
                            data-a="profile:author-name"
                            placeholder={data.name || 'As it appears on the cover'}
                            onChange={(event) => setData('author_name', event.target.value)}
                            className={input(errors.author_name)}
                        />
                        <Hint>Left blank, your own name is used.</Hint>
                    </Field>
                </div>

                <Field label="About you" error={errors.bio} className="mt-4">
                    <textarea
                        value={data.bio}
                        rows={4}
                        data-a="profile:bio"
                        placeholder="A short biography, in the third person. It appears on the About the Author page and on your book's listing."
                        onChange={(event) => setData('bio', event.target.value)}
                        className={input(errors.bio)}
                    />
                    <Hint>
                        {data.bio.length} characters. Two or three sentences is usual.
                    </Hint>
                </Field>

                <Field label="Website" error={errors.website} className="mt-4">
                    <input
                        value={data.website}
                        type="url"
                        data-a="profile:website"
                        placeholder="https://"
                        onChange={(event) => setData('website', event.target.value)}
                        className={input(errors.website)}
                    />
                </Field>
            </Box>

            <Box
                title="How you like to work"
                detail="Defaults for the next book you start. Nothing here changes a book you have already written."
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Usual kind of book" error={errors.default_book_type}>
                        <select
                            value={data.default_book_type}
                            data-a="profile:default-type"
                            onChange={(event) =>
                                setData('default_book_type', event.target.value)
                            }
                            className={input(errors.default_book_type)}
                        >
                            <option value="">No default — ask me each time</option>
                            {bookTypes.map((type) => (
                                <option key={type.key} value={type.key}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Daily word goal" error={errors.daily_word_goal}>
                        <input
                            value={data.daily_word_goal}
                            type="number"
                            min="50"
                            max="20000"
                            data-a="profile:word-goal"
                            placeholder="None"
                            onChange={(event) =>
                                setData('daily_word_goal', event.target.value)
                            }
                            className={input(errors.daily_word_goal)}
                        />
                        <Hint>A goal, not a quota. Nothing nags you about it.</Hint>
                    </Field>
                </div>
            </Box>

            <Box
                title="Account"
                detail="Used for signing in, and for anything we need to tell you."
            >
                <Field label="Email" error={errors.email}>
                    <input
                        value={data.email}
                        type="email"
                        data-a="profile:email"
                        required
                        onChange={(event) => setData('email', event.target.value)}
                        className={input(errors.email)}
                    />
                </Field>

                {mustVerifyEmail && auth.user.email_verified_at === null && (
                    <p className="mt-2 text-[11.5px] text-status-warning">
                        This address is unverified.{' '}
                        <Link
                            href={route('verification.send')}
                            method="post"
                            as="button"
                            className="font-semibold underline"
                        >
                            Send the link again
                        </Link>
                    </p>
                )}
            </Box>

            <div className="flex items-center gap-3">
                <button
                    data-a="profile:save"
                    disabled={processing}
                    className="h-[38px] rounded-control bg-brand-primary px-4 text-[13px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {processing ? 'Saving…' : 'Save changes'}
                </button>

                {recentlySuccessful && (
                    <span className="text-[12.5px] text-status-success">Saved.</span>
                )}
            </div>
        </form>
    );
}

/**
 * The plan, here as well as on Plans & Billing.
 *
 * Somebody checking their own details should not have to go somewhere else to
 * see what they are paying for and how much of it is left.
 */
function PlanCard({ plan }) {
    const pct = plan.monthly_credits
        ? Math.max(0, Math.min(100, Math.round((plan.credits / plan.monthly_credits) * 100)))
        : 0;

    return (
        <Box
            title="Your plan"
            detail={`${plan.name}${plan.price > 0 ? ` · $${plan.price} a month` : ' · free'}`}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <p className="text-[11.5px] text-text-secondary">AI credits</p>
                    <p className="mt-0.5 flex items-baseline gap-1.5">
                        <b className="font-display text-[22px] font-extrabold tracking-[-0.02em] text-text-primary">
                            {plan.credits.toLocaleString()}
                        </b>
                        {plan.monthly_credits > 0 && (
                            <span className="text-[12px] text-text-secondary">
                                of {plan.monthly_credits.toLocaleString()} this month
                            </span>
                        )}
                    </p>

                    {plan.monthly_credits > 0 && (
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-bg-subtle">
                            <div
                                className="h-full rounded-full bg-brand-primary"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                    )}
                </div>

                <div>
                    <p className="text-[11.5px] text-text-secondary">Books</p>
                    <p className="mt-0.5 font-display text-[22px] font-extrabold tracking-[-0.02em] text-text-primary">
                        {plan.books}
                        <span className="ml-1.5 text-[12px] font-normal text-text-secondary">
                            {plan.book_limit === null
                                ? 'of unlimited'
                                : `of ${plan.book_limit}`}
                        </span>
                    </p>
                </div>
            </div>

            <button
                type="button"
                data-a="profile:billing"
                onClick={() => router.get(route('billing.show'))}
                className="mt-3 text-[12.5px] font-semibold text-brand-primary hover:underline"
            >
                Plans &amp; billing
            </button>
        </Box>
    );
}

function Box({ title, detail, children, danger = false }) {
    return (
        <section
            className={`rounded-panel border bg-bg-surface p-5 ${
                danger ? 'border-status-danger/25' : 'border-border-default'
            }`}
        >
            <h2
                className={`text-[14px] font-semibold ${
                    danger ? 'text-status-danger' : 'text-text-primary'
                }`}
            >
                {title}
            </h2>
            {detail && (
                <p className="mt-1 mb-3 text-[11.5px] leading-relaxed text-text-secondary">
                    {detail}
                </p>
            )}
            {children}
        </section>
    );
}

function Field({ label, error, children, className = '' }) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-[12px] font-medium text-text-secondary">
                {label}
            </span>
            {children}
            {error && (
                <span
                    role="alert"
                    className="mt-1 block text-[11px] font-medium text-status-danger"
                >
                    {error}
                </span>
            )}
        </label>
    );
}

function Hint({ children }) {
    return <span className="mt-1 block text-[10.5px] text-text-secondary">{children}</span>;
}

const input = (error) =>
    `w-full rounded-control border px-2.5 py-1.5 text-[13px] focus:ring-0 ${
        error
            ? 'border-status-danger focus:border-status-danger'
            : 'border-border-input focus:border-brand-primary'
    }`;
