import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * Quick Start.
 *
 * One screen, one question, straight into writing. The directive asks for
 * "minimal onboarding, simplified controls, and an immediate writing action",
 * and the test of that is whether somebody who has never seen the product can
 * be typing in under a minute.
 *
 * It writes to the same books, the same sections and the same version history
 * as the studio — this is a different door into one building, not a second
 * building. An author who starts here can open the same book in Professional
 * Studio an hour later and find everything where they left it.
 *
 * Deliberately offers no genre, no chapter count, no assistance level. Those
 * are good questions and the guided flow asks them; asking them here would
 * make this the guided flow with fewer words.
 */
export default function QuickStart({ recent }) {
    const { auth } = usePage().props;
    const { t, formatNumber } = useI18n();
    const [showing, setShowing] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        title: '',
        started_with: 'blank',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('books.store'));
    };

    return (
        <div className="min-h-screen bg-bg-app font-ui text-text-body">
            <Head title={t('register.title')} />

            <div className="mx-auto flex min-h-screen max-w-[560px] flex-col justify-center px-6 py-12">
                <div className="flex items-center gap-2.5">
                    <img src="/brand/symbol.svg" alt="" width={28} height={28} />
                    <span className="font-display text-[21px] font-bold tracking-[-0.01em] text-text-primary">
                        Ecrivain
                    </span>
                </div>

                <h1 className="mt-7 font-display text-[32px] font-extrabold leading-[1.15] tracking-[-0.03em] text-text-primary">
                    {t('quickstart.heading')}
                </h1>
                <p className="mt-2 text-[14px] leading-relaxed text-text-secondary">
                    {t('quickstart.intro')}
                </p>

                <form onSubmit={submit} className="mt-6">
                    <label>
                        <span className="sr-only">{t('settings.field_title')}</span>
                        <input
                            value={data.title}
                            data-a="quick:title"
                            required
                            autoFocus
                            placeholder={t('quickstart.title_placeholder')}
                            onChange={(event) => setData('title', event.target.value)}
                            className="h-[52px] w-full rounded-control border border-border-input px-4 text-[16px] placeholder:text-text-secondary focus:border-brand-primary focus:ring-0"
                        />
                    </label>

                    {errors.title && (
                        <p role="alert" className="mt-1.5 text-[12px] text-status-danger">
                            {errors.title}
                        </p>
                    )}

                    <button
                        data-a="quick:start"
                        disabled={processing || !data.title.trim()}
                        className="mt-3 h-[48px] w-full rounded-control bg-brand-fill text-[15px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        {processing ? t('quickstart.opening') : t('register.title')}
                    </button>
                </form>

                {recent.length > 0 && (
                    <div className="mt-8">
                        <p className="mb-2 text-[12px] font-semibold text-text-secondary">
                            {t('quickstart.carry_on')}
                        </p>

                        <ul className="space-y-1.5">
                            {recent.map((book) => (
                                <li key={book.id}>
                                    <button
                                        data-a={`quick:resume:${book.id}`}
                                        onClick={() => router.get(route('books.edit', book.id))}
                                        className="flex w-full items-baseline justify-between gap-3 rounded-tile border border-border-default bg-bg-surface px-3.5 py-2.5 text-left transition hover:border-brand-primary/40"
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate text-[13.5px] font-medium text-text-primary">
                                                {book.title}
                                            </span>
                                            <span className="text-[11.5px] text-text-secondary">
                                                {book.resume
                                                    ? t('quickstart.back_to', { section: book.resume })
                                                    : t('quickstart.not_started')}
                                            </span>
                                        </span>

                                        <span className="shrink-0 text-[11.5px] text-text-secondary">
                                            {t('quickstart.words', { words: formatNumber(book.word_count) })}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="mt-8 border-t border-border-divider pt-4">
                    <button
                        data-a="quick:full"
                        onClick={() => router.get(route('books.index'))}
                        className="text-[12.5px] font-semibold text-brand-primary hover:underline"
                    >
                        {t('quickstart.full_studio')}
                    </button>
                    <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                        {t('quickstart.full_studio_detail')}
                    </p>

                    {!showing ? (
                        <button
                            data-a="quick:why"
                            onClick={() => setShowing(true)}
                            className="mt-3 text-[11.5px] text-text-secondary underline"
                        >
                            {t('quickstart.why')}
                        </button>
                    ) : (
                        <p className="mt-3 text-[11.5px] leading-relaxed text-text-secondary">
                            {t('quickstart.why_answer')}
                        </p>
                    )}
                </div>

                {auth?.user && (
                    <p className="mt-6 flex flex-wrap items-center gap-1.5 text-[11px] text-text-secondary">
                        <span>{t('quickstart.signed_in_as', { email: auth.user.email })}</span>

                        {/* This screen has no studio header, so without this
                            a newly registered author — who lands here — had
                            no way to sign out at all. */}
                        <Link
                            as="button"
                            method="post"
                            href={route('logout')}
                            data-a="quick:signout"
                            className="font-semibold text-brand-primary hover:underline"
                        >
                            {t('signin.sign_out')}
                        </Link>
                    </p>
                )}
            </div>
        </div>
    );
}
