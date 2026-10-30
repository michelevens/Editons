import Dialog, { CloseButton } from '@/Components/Dialog';
import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n';
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
    const { t, formatNumber } = useI18n();
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
            label={t('goals.title')}
            onClose={onClose}
            data-a="goals:sheet"
            className="w-[420px] max-w-full rounded-panel bg-bg-surface p-5 shadow-popover"
        >
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-[15px] font-semibold text-text-primary">{t('goals.title')}</h2>
                <CloseButton onClick={onClose} label={t('goals.close')} />
            </div>

            {failed ? (
                <p role="alert" className="text-[12.5px] text-status-danger">
                    {t('goals.load_failed')}
                </p>
            ) : !data ? (
                <p className="py-6 text-center text-[12.5px] text-text-secondary">{t('common.loading')}</p>
            ) : (
                <div className="space-y-4">
                    {chapter && (
                        <Block title={t('goals.this_chapter')}>
                            <GoalRow
                                testid="chapter"
                                value={chapterWords}
                                goal={chapter.goal}
                                none={t('goals.no_chapter_goal')}
                            />
                            <GoalForm
                                id="goal-chapter"
                                label={t('goals.chapter_goal_label')}
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

                    <Block title={t('goals.the_book')}>
                        {data.book.target ? (
                            <GoalRow testid="book" value={data.book.words} goal={data.book.target} />
                        ) : (
                            <p className="text-[12.5px] text-text-secondary">
                                {t('goals.book_no_target', { words: formatNumber(data.book.words) })}
                            </p>
                        )}
                        <a
                            href={route('books.overview', bookId)}
                            className="mt-1 inline-block text-[12px] font-medium text-brand-primary hover:underline"
                        >
                            {data.book.target ? t('goals.change_target') : t('goals.set_target')}
                        </a>
                    </Block>

                    <Block title={t('goals.today')}>
                        <Today today={data.today} />
                        <GoalForm
                            id="goal-daily"
                            label={t('goals.daily_goal_label')}
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
                            {t('goals.net_words')}
                            {!data.book.owned && ` ${t('goals.shared_not_counted')}`}
                        </p>
                    </Block>

                    <Block title={t('goals.streak')}>
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
    const { t, formatNumber } = useI18n();
    const reached = goal > 0 && progressOf(value, goal) >= 1;

    return (
        <div className="flex items-center gap-3" data-a={`goals:${testid}:progress`}>
            <ProgressRing value={value} goal={goal} size={44} stroke={4} />
            <div className="min-w-0 text-[12.5px] text-text-body">
                {goal ? (
                    <>
                        <span className="font-semibold tabular-nums">{formatNumber(Number(value) || 0)}</span>
                        {` ${t('goals.of')} `}
                        <span className="tabular-nums">{formatNumber(goal)}</span> {t('goals.words')}
                        <span className="ml-1.5 text-text-secondary">
                            {t('goals.percent', { percent: Math.floor(progressOf(value, goal) * 100) })}
                        </span>
                        {reached && (
                            <span data-a={`goals:${testid}:reached`} className="mt-0.5 block text-[12px] font-semibold text-status-success">
                                {t('goals.reached')}
                            </span>
                        )}
                    </>
                ) : (
                    <>
                        <span className="font-semibold tabular-nums">{formatNumber(Number(value) || 0)}</span>{' '}
                        {t('goals.words_period')}{' '}
                        <span className="text-text-secondary">{none}</span>
                    </>
                )}
            </div>
        </div>
    );
}

function Today({ today }) {
    const { t, formatNumber } = useI18n();
    const words = today.words;
    const shown = words < 0 ? `−${formatNumber(Math.abs(words))}` : formatNumber(words);

    if (!today.goal) {
        return (
            <p className="text-[12.5px] text-text-body" data-a="goals:today">
                <span className="font-semibold tabular-nums">{shown}</span> {t('goals.words_today_no_goal')}
            </p>
        );
    }

    return (
        <div className="flex items-center gap-3" data-a="goals:today">
            <ProgressRing value={Math.max(0, words)} goal={today.goal} size={44} stroke={4} />
            <p className="text-[12.5px] text-text-body">
                <span className="font-semibold tabular-nums">{shown}</span> {t('goals.of')}{' '}
                <span className="tabular-nums">{formatNumber(today.goal)}</span> {t('goals.words_today')}
                {today.met && <span className="mt-0.5 block text-[12px] font-semibold text-status-success">{t('goals.reached')}</span>}
            </p>
        </div>
    );
}

function Streak({ streak, hasHistory }) {
    const { t } = useI18n();
    if (!hasHistory) {
        return (
            <p className="text-[12.5px] text-text-secondary" data-a="goals:streak">
                {t('goals.no_history')}
            </p>
        );
    }

    const rule = streak.rule === 'goal' ? t('goals.rule_goal') : t('goals.rule_words');
    const days = streak.at_least
        ? t('goals.at_least_days', { count: streak.days })
        : t('goals.days', { count: streak.days });

    return (
        <p className="text-[12.5px] text-text-body" data-a="goals:streak">
            {streak.days === 0 ? (
                <>{t('goals.no_streak', { rule })}</>
            ) : (
                <>
                    <span className="font-semibold">{days}</span> {t('goals.in_a_row', { rule })}
                    {!streak.includes_today && (
                        <span className="block text-text-secondary">{t('goals.not_counted_today')}</span>
                    )}
                </>
            )}
        </p>
    );
}

/** A number field with Save and Clear. Clear sends null; nothing is guessed. */
function GoalForm({ id, label, initial, min, max, save, ...rest }) {
    const { t } = useI18n();
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
            setSaved(goal === null ? t('goals.cleared') : t('goals.saved'));
        } catch (failure) {
            setError(failure?.response?.data?.errors?.goal?.[0] ?? failure?.response?.data?.message ?? t('goals.not_saved'));
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
                    {t('goals.save')}
                </button>
                {initial ? (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => submit(null)}
                        className="tap h-[34px] rounded-control border border-border-input px-3 text-[12.5px] text-text-secondary transition hover:text-text-primary disabled:opacity-60"
                    >
                        {t('goals.clear')}
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
