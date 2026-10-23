import Dialog, { CloseButton } from '@/Components/Dialog';
import { useEffect, useState } from 'react';
import ProgressRing, { progressOf } from './ProgressRing';

/**
 * The goals sheet: a chapter against its word goal, the book against its
 * target, and the author's day against the daily goal, with the streak.
 *
 * Every figure is the server's (GoalsController, WritingProgress), computed
 * from saved chapters and version history — the same arithmetic as the
 * Analytics chart. The one exception is the open chapter's word count,
 * which `liveWords` brings from the editor so unsaved typing is not
 * missing from the ring the author just tapped.
 *
 * A centred dialog, so a bottom sheet on a phone (Components/Dialog).
 */
export default function GoalsSheet({ bookId, sectionId = null, liveWords = null, onClose, onChapterGoal }) {
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let live = true;

        window.axios
            .get(route('books.goals', bookId), { params: sectionId ? { section: sectionId } : {} })
            .then((res) => live && setData(res.data))
            .catch(() => live && setFailed(true));

        return () => {
            live = false;
        };
    }, [bookId, sectionId]);

    const chapter = data?.chapter;
    const chapterWords = chapter && liveWords !== null ? liveWords : chapter?.words ?? 0;

    return (
        <Dialog
            label="Goals"
            onClose={onClose}
            data-a="goals:sheet"
            className="w-[420px] max-w-full rounded-panel bg-bg-surface p-5 shadow-popover"
        >
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-[15px] font-semibold text-text-primary">Goals</h2>
                <CloseButton onClick={onClose} label="Close goals" />
            </div>

            {failed ? (
                <p role="alert" className="text-[12.5px] text-status-danger">
                    The goals could not be loaded. Check your connection and open them again.
                </p>
            ) : !data ? (
                <p className="py-6 text-center text-[12.5px] text-text-secondary">Loading…</p>
            ) : (
                <div className="space-y-4">
                    {chapter && (
                        <Block title="This chapter">
                            <GoalRow
                                testid="chapter"
                                value={chapterWords}
                                goal={chapter.goal}
                                none="No goal for this chapter."
                            />
                            <GoalForm
                                id="goal-chapter"
                                label="Chapter goal (words)"
                                initial={chapter.goal}
                                min={1}
                                max={1000000}
                                data-a="goals:chapter"
                                save={async (goal) => {
                                    const res = await window.axios.patch(route('sections.goal', chapter.id), { goal });
                                    setData((d) => ({ ...d, chapter: { ...d.chapter, goal: res.data.goal } }));
                                    onChapterGoal?.(chapter.id, res.data.goal);
                                }}
                            />
                        </Block>
                    )}

                    <Block title="The book">
                        {data.book.target ? (
                            <GoalRow testid="book" value={data.book.words} goal={data.book.target} />
                        ) : (
                            <p className="text-[12.5px] text-text-secondary">
                                {data.book.words.toLocaleString()} words. No word target set.
                            </p>
                        )}
                        <a
                            href={route('books.overview', bookId)}
                            className="mt-1 inline-block text-[12px] font-medium text-brand-primary hover:underline"
                        >
                            {data.book.target ? 'Change the target in Overview' : 'Set a target in Overview'}
                        </a>
                    </Block>

                    <Block title="Today">
                        <Today today={data.today} />
                        <GoalForm
                            id="goal-daily"
                            label="Daily goal (words, 50–20,000)"
                            initial={data.today.goal}
                            min={50}
                            max={20000}
                            data-a="goals:daily"
                            save={async (goal) => {
                                const res = await window.axios.patch(route('profile.daily-goal'), { goal });
                                setData((d) => ({ ...d, ...res.data }));
                            }}
                        />
                        <p className="mt-1.5 text-[11px] leading-relaxed text-text-secondary">
                            Net words saved today across the books you own: cuts count against it.
                            {!data.book.owned && ' This book is shared with you, so it is not counted.'}
                        </p>
                    </Block>

                    <Block title="Streak">
                        <Streak streak={data.streak} hasHistory={data.has_history} />
                    </Block>
                </div>
            )}
        </Dialog>
    );
}

function Block({ title, children }) {
    return (
        <section className="rounded-control border border-border-divider p-3">
            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-text-secondary">{title}</h3>
            {children}
        </section>
    );
}

function GoalRow({ value, goal, none = null, testid }) {
    const reached = goal > 0 && progressOf(value, goal) >= 1;

    return (
        <div className="flex items-center gap-3" data-a={`goals:${testid}:progress`}>
            <ProgressRing value={value} goal={goal} size={44} stroke={4} />
            <div className="min-w-0 text-[12.5px] text-text-body">
                {goal ? (
                    <>
                        <span className="font-semibold tabular-nums">{(Number(value) || 0).toLocaleString()}</span>
                        {' of '}
                        <span className="tabular-nums">{goal.toLocaleString()}</span> words
                        <span className="ml-1.5 text-text-secondary">· {Math.floor(progressOf(value, goal) * 100)}%</span>
                        {reached && (
                            <span data-a={`goals:${testid}:reached`} className="mt-0.5 block text-[12px] font-semibold text-status-success">
                                Goal reached
                            </span>
                        )}
                    </>
                ) : (
                    <>
                        <span className="font-semibold tabular-nums">{(Number(value) || 0).toLocaleString()}</span> words.{' '}
                        <span className="text-text-secondary">{none}</span>
                    </>
                )}
            </div>
        </div>
    );
}

function Today({ today }) {
    const words = today.words;
    const shown = words < 0 ? `−${Math.abs(words).toLocaleString()}` : words.toLocaleString();

    if (!today.goal) {
        return (
            <p className="text-[12.5px] text-text-body" data-a="goals:today">
                <span className="font-semibold tabular-nums">{shown}</span> words today. No daily goal set.
            </p>
        );
    }

    return (
        <div className="flex items-center gap-3" data-a="goals:today">
            <ProgressRing value={Math.max(0, words)} goal={today.goal} size={44} stroke={4} />
            <p className="text-[12.5px] text-text-body">
                <span className="font-semibold tabular-nums">{shown}</span> of{' '}
                <span className="tabular-nums">{today.goal.toLocaleString()}</span> words today
                {today.met && <span className="mt-0.5 block text-[12px] font-semibold text-status-success">Goal reached</span>}
            </p>
        </div>
    );
}

function Streak({ streak, hasHistory }) {
    if (!hasHistory) {
        return (
            <p className="text-[12.5px] text-text-secondary" data-a="goals:streak">
                No saved history yet. Today and the streak count from your first saved chapter.
            </p>
        );
    }

    const rule = streak.rule === 'goal' ? 'meeting your daily goal' : 'with words written';
    const days = `${streak.at_least ? 'At least ' : ''}${streak.days} day${streak.days === 1 ? '' : 's'}`;

    return (
        <p className="text-[12.5px] text-text-body" data-a="goals:streak">
            {streak.days === 0 ? (
                <>No streak yet: a day {rule} starts one.</>
            ) : (
                <>
                    <span className="font-semibold">{days}</span> in a row {rule}.
                    {!streak.includes_today && (
                        <span className="block text-text-secondary">Today is not counted yet; write today to keep it going.</span>
                    )}
                </>
            )}
        </p>
    );
}

/** A number field with Save and Clear. Clear sends null; nothing is guessed. */
function GoalForm({ id, label, initial, min, max, save, ...rest }) {
    const [value, setValue] = useState(initial ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState('');

    useEffect(() => setValue(initial ?? ''), [initial]);

    const submit = async (goal) => {
        setBusy(true);
        setError('');
        setSaved('');

        try {
            await save(goal);
            setSaved(goal === null ? 'Goal cleared.' : 'Goal saved.');
        } catch (failure) {
            setError(failure?.response?.data?.errors?.goal?.[0] ?? failure?.response?.data?.message ?? 'The goal was not saved.');
        } finally {
            setBusy(false);
        }
    };

    return (
        // noValidate: the server's limits and its message, rather than a
        // browser bubble that differs per browser and says nothing on some.
        <form
            noValidate
            className="mt-2.5"
            data-a={rest['data-a']}
            onSubmit={(event) => {
                event.preventDefault();
                submit(value === '' ? null : Number(value));
            }}
        >
            <label htmlFor={id} className="mb-1 block text-[11.5px] text-text-secondary">
                {label}
            </label>
            <div className="flex items-center gap-2">
                <input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={min}
                    max={max}
                    step={1}
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className="tap h-[34px] min-w-0 flex-1 rounded-control border border-border-input bg-bg-surface px-2.5 text-[13px] text-text-body focus:border-brand-primary focus:ring-0"
                />
                <button
                    type="submit"
                    disabled={busy}
                    className="tap h-[34px] rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    Save
                </button>
                {initial ? (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => submit(null)}
                        className="tap h-[34px] rounded-control border border-border-input px-3 text-[12.5px] text-text-secondary transition hover:text-text-primary disabled:opacity-60"
                    >
                        Clear
                    </button>
                ) : null}
            </div>
            {error && (
                <p id={`${id}-error`} role="alert" className="mt-1 text-[11.5px] text-status-danger">
                    {error}
                </p>
            )}
            <p aria-live="polite" className="sr-only">
                {saved}
            </p>
        </form>
    );
}
