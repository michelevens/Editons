import Icon from '@/Components/Studio/Icon';
import { goTo } from '@/Components/Studio/navigation';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * The Guided Journey: the third interface.
 *
 * Between Quick Start, which asks one question, and Professional Studio,
 * which offers everything at once. This one asks the questions the book needs
 * answered, in the order they stop being premature, and answers "what should
 * I do next" with one thing rather than a menu.
 *
 * Every figure comes from JourneyController's payload — the same stages and
 * the same next action the studio computes. Nothing here is estimated, and
 * nothing is stored that the manuscript does not already prove: a stage is
 * done because the work exists, not because somebody ticked it.
 *
 * Same books, same sections, same version history as the other two. An author
 * can leave here mid-stage and open the studio to find everything in place.
 */
export default function Guided({ book, journey: initial }) {
    const { auth } = usePage().props;

    const [journey, setJourney] = useState(initial);

    /*
     * Follow the server when it sends a new journey.
     *
     * useState captures its argument once. Inertia reuses the component
     * across visits rather than remounting it, so after a reload — or a
     * back-navigation, or any visit that re-renders this page — the screen
     * kept showing the journey from the first render and ignored everything
     * the server sent afterwards.
     *
     * Found on a real book: the payload carried a coaching note, the page
     * showed none, and the server was right.
     */
    useEffect(() => setJourney(initial), [initial]);
    const [busy, setBusy] = useState(null);
    const [note, setNote] = useState(null);

    const say = (message) => {
        setNote(message);
        window.setTimeout(() => setNote(null), 4000);
    };

    /** Every journey call returns the whole payload, so the page re-renders from the server's view. */
    const send = async (method, url, body, label) => {
        setBusy(label);

        try {
            const { data } = await window.axios[method](url, body);
            setJourney(data);
            return data;
        } catch (error) {
            say(error.response?.data?.message ?? 'That did not work.');
            return null;
        } finally {
            setBusy(null);
        }
    };

    /**
     * Mark the stage, then take the author to where its work is done.
     *
     * This used to only move the "Here" badge, so "Go there" went nowhere.
     * Discover is answered on this page; every other stage has a studio
     * screen.
     */
    const openStage = async (stage) => {
        const data = await send('patch', `/books/${book.id}/stage`, { stage }, `stage:${stage}`);

        if (!data) return;

        if (STAGE_SCREEN[stage]) {
            goTo(book.id, STAGE_SCREEN[stage]);
        } else {
            document.getElementById('the-idea')?.scrollIntoView({ behavior: 'smooth' });
            document.getElementById('premise')?.focus({ preventScroll: true });
        }
    };

    const { progress, next } = journey;
    const pct = progress.chapters
        ? Math.round((progress.written / progress.chapters) * 100)
        : 0;

    return (
        <div className="min-h-screen bg-bg-app font-ui text-text-body">
            <Head title={`${book.title} — Journey`} />

            <header className="border-b border-border-divider bg-bg-surface">
                <div className="mx-auto flex h-[58px] max-w-[880px] items-center gap-3 px-6">
                    <button
                        data-a="guided:books"
                        onClick={() => router.get(route('books.index'))}
                        className="flex items-center gap-2.5"
                    >
                        <img src="/brand/symbol.svg" alt="" width={24} height={24} />
                        <span className="font-display text-[17px] font-bold tracking-[-0.01em] text-text-primary">
                            Ecrivain
                        </span>
                    </button>

                    <span className="min-w-0 flex-1 truncate text-[13px] text-text-secondary">
                        {book.title}
                    </span>

                    <button
                        data-a="guided:studio"
                        onClick={() => router.get(route('books.edit', book.id))}
                        className="shrink-0 text-[12.5px] font-semibold text-brand-primary hover:underline"
                    >
                        Open the studio
                    </button>
                </div>
            </header>

            <main className="mx-auto max-w-[880px] px-6 py-9">
                {/*
                 * One thing to do next.
                 *
                 * The first thing on the screen, because an author arriving
                 * here is asking exactly this. A list of nine options would
                 * not have answered it.
                 */}
                {next ? (
                    <section className="rounded-panel border border-brand-primary/25 bg-brand-primary/[0.04] p-6">
                        <p className="text-[11px] font-semibold uppercase tracking-[1.4px] text-brand-primary">
                            Next
                        </p>
                        <h1 className="mt-2 font-display text-[26px] font-extrabold leading-tight tracking-[-0.025em] text-text-primary">
                            {next.label}
                        </h1>
                        <p className="mt-1.5 max-w-[56ch] text-[14px] leading-relaxed text-text-secondary">
                            {next.detail}
                        </p>

                        <div className="mt-4 flex flex-wrap gap-2.5">
                            {next.action === 'outline' ? (
                                <button
                                    data-a="guided:next"
                                    disabled={busy === 'outline'}
                                    onClick={async () => {
                                        const data = await send(
                                            'post',
                                            `/books/${book.id}/outline`,
                                            {},
                                            'outline',
                                        );

                                        if (data) {
                                            say(`Named ${data.renamed} chapters. Change any of them.`);
                                        }
                                    }}
                                    className="h-[42px] rounded-control bg-brand-primary px-5 text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                                >
                                    {busy === 'outline' ? 'Naming…' : 'Name the chapters'}
                                </button>
                            ) : next.stage === 'create' ? (
                                <button
                                    data-a="guided:next"
                                    onClick={() => router.get(route('books.edit', book.id))}
                                    className="h-[42px] rounded-control bg-brand-primary px-5 text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover"
                                >
                                    Open the manuscript
                                </button>
                            ) : (
                                <button
                                    data-a="guided:next"
                                    onClick={() => openStage(next.stage)}
                                    className="h-[42px] rounded-control bg-brand-primary px-5 text-[14px] font-semibold text-white transition hover:bg-brand-primary-hover"
                                >
                                    Go there
                                </button>
                            )}
                        </div>
                    </section>
                ) : (
                    <section className="rounded-panel border border-border-default bg-bg-surface p-6">
                        <h1 className="font-display text-[24px] font-extrabold tracking-[-0.025em] text-text-primary">
                            Nothing is waiting on you
                        </h1>
                        <p className="mt-1.5 text-[14px] text-text-secondary">
                            Every stage has work behind it. Keep writing, or open the studio
                            for the parts this screen does not cover.
                        </p>
                    </section>
                )}

                {/*
                 * Coaching, when the author asked for it.
                 *
                 * Empty unless the assistance level is "Coach me", which is
                 * what makes that choice mean something. Every note names
                 * the number it noticed, so none of them is encouragement.
                 */}
                {journey.coaching.length > 0 && (
                    <section className="mt-6 rounded-panel border border-border-default bg-bg-surface p-5">
                        <h2 className="text-[15px] font-semibold text-text-primary">
                            What I notice
                        </h2>
                        <p className="mt-0.5 text-[11.5px] text-text-secondary">
                            From the manuscript as it stands. Nothing here reads your prose —
                            the editorial agents do that, when you ask them to.
                        </p>

                        <ul className="mt-3.5 divide-y divide-border-divider">
                            {journey.coaching.map((note) => (
                                <li
                                    key={note.key}
                                    data-a={`guided:coach:${note.key}`}
                                    className="py-2.5"
                                >
                                    <p className="text-[13.5px] font-medium text-text-primary">
                                        {note.note}
                                    </p>
                                    <p className="mt-0.5 text-[12px] leading-relaxed text-text-secondary">
                                        {note.detail}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* What exists, counted from the manuscript. */}
                <section className="mt-6 grid gap-3 sm:grid-cols-3">
                    <Figure label="Words" value={progress.words.toLocaleString()} />
                    <Figure
                        label="Chapters written"
                        value={`${progress.written} of ${progress.chapters}`}
                        sub={progress.chapters ? `${pct}%` : null}
                    />
                    <Figure
                        label="Target"
                        value={progress.target ? progress.target.toLocaleString() : '—'}
                        sub={progress.target ? 'words' : 'not set'}
                    />
                </section>

                {/* The six stages. Any of them, any time. */}
                <section className="mt-9">
                    <h2 className="font-display text-[17px] font-bold tracking-[-0.015em] text-text-primary">
                        The journey
                    </h2>
                    <p className="mt-1 text-[12.5px] text-text-secondary">
                        Open any of these at any point. Nothing is locked, and going back to
                        an earlier one costs you nothing.
                    </p>

                    <ol className="mt-4 space-y-2">
                        {journey.stages.map((stage, index) => (
                            <li key={stage.key}>
                                <button
                                    data-a={`guided:stage:${stage.key}`}
                                    disabled={busy === `stage:${stage.key}`}
                                    onClick={() => openStage(stage.key)}
                                    aria-current={stage.current ? 'step' : undefined}
                                    className={`flex w-full items-start gap-3.5 rounded-tile border px-4 py-3 text-left transition ${
                                        stage.current
                                            ? 'border-brand-primary bg-bg-surface'
                                            : 'border-border-default bg-bg-surface hover:border-brand-primary/40'
                                    }`}
                                >
                                    <span
                                        className={`mt-px grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full text-[11px] font-bold ${
                                            stage.done
                                                ? 'bg-status-success text-white'
                                                : 'bg-bg-app text-text-secondary'
                                        }`}
                                    >
                                        {stage.done ? (
                                            <Icon name="check" size={13} strokeWidth={3} />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="text-[14px] font-semibold text-text-primary">
                                                {stage.name}
                                            </span>
                                            {stage.current && (
                                                <span className="rounded-badge bg-brand-primary/10 px-1.5 py-px text-[10px] font-semibold uppercase tracking-[0.8px] text-brand-primary">
                                                    Here
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-0.5 block text-[12.5px] leading-relaxed text-text-secondary">
                                            {stage.detail}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </section>

                <Idea book={book} journey={journey} send={send} busy={busy} say={say} />

                <Assistance book={book} journey={journey} send={send} busy={busy} />

                <ImportOutline book={book} send={send} busy={busy} say={say} />

                <div className="mt-10 border-t border-border-divider pt-5">
                    <button
                        data-a="guided:full"
                        onClick={() => router.get(route('books.edit', book.id))}
                        className="text-[12.5px] font-semibold text-brand-primary hover:underline"
                    >
                        Open the full studio
                    </button>
                    <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                        Research, editorial agents, design and publishing. Same book — this
                        screen is a narrower view of it, not a different copy.
                    </p>

                    {auth?.user && (
                        <p className="mt-5 text-[11px] text-text-secondary">
                            Signed in as {auth.user.email}
                        </p>
                    )}
                </div>
            </main>

            {note && (
                <div
                    role="status"
                    className="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-control bg-text-primary px-4 py-2.5 text-[13px] text-white shadow-lg"
                >
                    {note}
                </div>
            )}
        </div>
    );
}

function Figure({ label, value, sub }) {
    return (
        <div className="rounded-tile border border-border-default bg-bg-surface px-4 py-3">
            <p className="text-[11px] font-medium uppercase tracking-[1.2px] text-text-secondary">
                {label}
            </p>
            <p className="mt-1 font-display text-[21px] font-bold tracking-[-0.02em] text-text-primary">
                {value}
            </p>
            {sub && <p className="text-[11.5px] text-text-secondary">{sub}</p>}
        </div>
    );
}

/**
 * Discover: what the book is, who it is for, what it promises.
 *
 * Three questions, saved together. The premise is the one that unblocks
 * everything else — chapter naming reads it — so it is first and the others
 * are plainly optional.
 */
function Idea({ book, journey, send, busy, say }) {
    const [idea, setIdea] = useState(journey.idea);
    const [dirty, setDirty] = useState(false);

    const set = (field) => (event) => {
        setIdea((current) => ({ ...current, [field]: event.target.value }));
        setDirty(true);
    };

    return (
        <section id="the-idea" className="mt-9">
            <h2 className="font-display text-[17px] font-bold tracking-[-0.015em] text-text-primary">
                What the book is
            </h2>
            <p className="mt-1 text-[12.5px] text-text-secondary">
                The first one is what the chapter naming reads. The other two are worth
                answering eventually and can wait.
            </p>

            {/*
             * The questions are phrased for the kind of book: a novelist and
             * a cookbook author are both answering "what is this", but
             * "What happens, and to whom?" and "What ties these recipes
             * together?" are not the same question, and the generic version
             * gets a generic answer. The server always sends all three, so
             * this never has to decide what to ask.
             */}
            <div className="mt-4 space-y-3.5">
                <Field
                    name="premise"
                    label={journey.questions.premise}
                    hint="A sentence is enough."
                    value={idea.premise ?? ''}
                    onChange={set('premise')}
                />
                <Field
                    name="audience"
                    label={journey.questions.audience}
                    hint="Optional."
                    value={idea.audience ?? ''}
                    onChange={set('audience')}
                />
                <Field
                    name="promise"
                    label={journey.questions.promise}
                    hint="Optional."
                    value={idea.promise ?? ''}
                    onChange={set('promise')}
                />
            </div>

            <button
                data-a="guided:idea:save"
                disabled={!dirty || busy === 'idea'}
                onClick={async () => {
                    const data = await send('patch', `/books/${book.id}/idea`, idea, 'idea');

                    if (data) {
                        setIdea(data.idea);
                        setDirty(false);
                        say('Saved.');
                    }
                }}
                className="mt-4 h-[40px] rounded-control bg-brand-primary px-5 text-[13.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
            >
                {busy === 'idea' ? 'Saving…' : 'Save'}
            </button>
        </section>
    );
}

function Field({ name, label, hint, value, onChange }) {
    return (
        <label className="block">
            <span className="text-[13px] font-semibold text-text-primary">{label}</span>
            <span className="ml-1.5 text-[11.5px] text-text-secondary">{hint}</span>
            <textarea
                id={name}
                data-a={`guided:idea:${name}`}
                rows={2}
                value={value}
                onChange={onChange}
                className="mt-1.5 w-full resize-y rounded-control border border-border-input px-3.5 py-2.5 text-[14px] leading-relaxed placeholder:text-text-secondary/70 focus:border-brand-primary focus:ring-0"
            />
        </label>
    );
}

/**
 * How much help the author wants.
 *
 * Stored on the book and changeable at any point, because the answer in
 * chapter one is often not the answer in chapter nine.
 */
function Assistance({ book, journey, send, busy }) {
    return (
        <section className="mt-9">
            <h2 className="font-display text-[17px] font-bold tracking-[-0.015em] text-text-primary">
                How much help
            </h2>
            <p className="mt-1 text-[12.5px] text-text-secondary">
                Change this whenever. It never touches what you have written.
            </p>

            <div className="mt-4 grid gap-2.5 sm:grid-cols-3">
                {journey.assistance_levels.map((level) => {
                    const chosen = journey.assistance === level.key;

                    return (
                        <button
                            key={level.key}
                            data-a={`guided:assist:${level.key}`}
                            disabled={busy === 'assist'}
                            onClick={() =>
                                send(
                                    'patch',
                                    `/books/${book.id}/assistance`,
                                    { assistance: level.key },
                                    'assist',
                                )
                            }
                            aria-pressed={chosen}
                            className={`rounded-tile border px-4 py-3 text-left transition ${
                                chosen
                                    ? 'border-brand-primary bg-brand-primary/[0.05]'
                                    : 'border-border-default bg-bg-surface hover:border-brand-primary/40'
                            }`}
                        >
                            <span className="block text-[13.5px] font-semibold text-text-primary">
                                {level.name}
                            </span>
                            <span className="mt-0.5 block text-[12px] leading-relaxed text-text-secondary">
                                {level.detail}
                            </span>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}

/**
 * Pasting in an outline the author already has.
 *
 * Collapsed by default: it is the right tool for somebody arriving with a
 * list in a notes app, and clutter for everybody else.
 */
function ImportOutline({ book, send, busy, say }) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');

    if (!open) {
        return (
            <section className="mt-9">
                <button
                    data-a="guided:import:open"
                    onClick={() => setOpen(true)}
                    className="text-[12.5px] font-semibold text-brand-primary hover:underline"
                >
                    I already have an outline
                </button>
                <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                    Paste a list of chapter titles and it becomes the binder. Indented lines
                    become chapters under a part.
                </p>
            </section>
        );
    }

    return (
        <section className="mt-9">
            <h2 className="font-display text-[17px] font-bold tracking-[-0.015em] text-text-primary">
                Paste your outline
            </h2>
            <p className="mt-1 text-[12.5px] leading-relaxed text-text-secondary">
                One title per line. Numbering is stripped — the binder keeps the order.
                Indent a line to put it under the one above. Chapters you have already
                written words into are left alone.
            </p>

            <textarea
                data-a="guided:import:text"
                rows={8}
                value={text}
                autoFocus
                placeholder={'Why it matters\nThe groundwork\nWhat goes wrong'}
                onChange={(event) => setText(event.target.value)}
                className="mt-3 w-full resize-y rounded-control border border-border-input px-3.5 py-2.5 font-mono text-[13px] leading-relaxed placeholder:text-text-secondary/70 focus:border-brand-primary focus:ring-0"
            />

            <div className="mt-3 flex gap-2.5">
                <button
                    data-a="guided:import:go"
                    disabled={!text.trim() || busy === 'import'}
                    onClick={async () => {
                        const data = await send(
                            'post',
                            `/books/${book.id}/outline/import`,
                            { outline: text },
                            'import',
                        );

                        if (data) {
                            setText('');
                            setOpen(false);
                            say(`${data.created} sections created.`);
                        }
                    }}
                    className="h-[40px] rounded-control bg-brand-primary px-5 text-[13.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy === 'import' ? 'Importing…' : 'Import'}
                </button>

                <button
                    data-a="guided:import:cancel"
                    onClick={() => setOpen(false)}
                    className="h-[40px] rounded-control border border-border-default px-4 text-[13.5px] font-semibold text-text-secondary transition hover:border-border-input"
                >
                    Cancel
                </button>
            </div>
        </section>
    );
}

/** Where each stage's work happens in the studio. Discover is on this page. */
const STAGE_SCREEN = {
    plan: 'manuscript',
    create: 'manuscript',
    improve: 'ai',
    design: 'layout',
    publish: 'export',
};
