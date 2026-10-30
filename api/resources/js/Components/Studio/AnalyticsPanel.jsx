import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * Writing progress, from version history.
 *
 * The daily chart is computed from real snapshots rather than generated:
 * every save records a word count and a timestamp, so the curve already
 * exists. A day's figure is the net change, which means deletions count
 * against it — an author who cut four thousand words did not write them.
 */
export default function AnalyticsPanel({ bookId, onOpenSection, onOpenFindings }) {
    const { t, formatNumber } = useI18n();
    const [data, setData] = useState(null);

    useEffect(() => {
        let cancelled = false;

        window.axios
            .get(`/books/${bookId}/analytics`)
            .then((response) => !cancelled && setData(response.data));

        return () => {
            cancelled = true;
        };
    }, [bookId]);

    if (!data) {
        return <p className="p-8 text-center text-[13px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { totals } = data;
    const pct = totals.target
        ? Math.min(100, Math.round((totals.words / totals.target) * 100))
        : null;

    return (
        <div className="mx-auto max-w-[1040px] p-4 sm:p-8">
            <header className="mb-6">
                <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">
                    {t('analytics.title')}
                </h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    {t('analytics.subtitle')}
                </p>
            </header>

            <div className="grid grid-cols-1 gap-[14px] sm:grid-cols-2 lg:grid-cols-4">
                <Stat label={t('analytics.words')} value={formatNumber(totals.words)} sub={t('analytics.chapters', { n: totals.chapters })} />
                <Stat
                    label={t('analytics.progress')}
                    value={pct === null ? '—' : `${pct}%`}
                    sub={
                        totals.target
                            ? t('analytics.of_target', { target: formatNumber(totals.target) })
                            : t('analytics.no_target')
                    }
                />
                <Stat
                    label={t('analytics.findings_resolved')}
                    value={totals.findings_applied + totals.findings_dismissed}
                    sub={t('analytics.applied_dismissed', { applied: totals.findings_applied, dismissed: totals.findings_dismissed })}
                />
                <Stat
                    label={t('analytics.still_open')}
                    value={totals.findings_open}
                    tone={totals.findings_open > 0 ? 'warning' : undefined}
                    sub={t('analytics.versions_saved', { n: totals.versions })}
                />
            </div>

            <section className="mt-5 rounded-panel border border-border-default bg-bg-surface p-5">
                <h3 className="text-[15px] font-semibold text-text-primary">
                    {t('analytics.per_day')}
                </h3>
                <p className="mt-1 text-[12px] text-text-secondary">
                    {t('analytics.per_day_hint', { days: data.days })}
                </p>

                <DailyChart days={data.daily} />

                {!data.has_history && (
                    <p className="mt-3 rounded-control bg-status-warning-bg px-3 py-2 text-[11.5px] text-status-warning">
                        {t('analytics.no_history')}
                    </p>
                )}
            </section>

            <div className="mt-5 grid grid-cols-1 gap-[18px] lg:grid-cols-2">
                <Bars
                    title={t('analytics.words_by_chapter')}
                    rows={data.by_section.map((section) => ({
                        key: section.id,
                        label: section.title,
                        value: section.words,
                    }))}
                    onPick={onOpenSection}
                    empty={t('analytics.no_chapters')}
                    format={(value) => formatNumber(value)}
                />

                <Bars
                    title={t('analytics.open_by_agent')}
                    rows={data.by_agent.map((agent) => ({
                        key: agent.value,
                        label: agent.label,
                        value: agent.open,
                    }))}
                    // Opens the AI Editor on that agent's findings; with no
                    // onPick the bars rendered permanently disabled.
                    onPick={onOpenFindings}
                    empty={t('analytics.nothing_open')}
                    tone="warning"
                    format={(value) => value}
                />
            </div>
        </div>
    );
}

function Stat({ label, value, sub, tone }) {
    return (
        <div className="rounded-panel border border-border-default bg-bg-surface p-4">
            <p className="text-[11.5px] font-medium uppercase tracking-wide text-text-secondary">
                {label}
            </p>
            <p
                className={`mt-2 font-display text-[26px] font-bold leading-none tracking-[-0.03em] ${
                    tone === 'warning' ? 'text-status-warning' : 'text-text-primary'
                }`}
            >
                {value}
            </p>
            <p className="mt-1.5 text-[11.5px] text-text-secondary">{sub}</p>
        </div>
    );
}

/**
 * A plain bar chart.
 *
 * Negative days drop below the axis rather than being clamped to zero, which
 * would hide the one thing a writing chart should show honestly: the day the
 * manuscript got shorter.
 */
function DailyChart({ days }) {
    const { t, formatNumber } = useI18n();
    const peak = Math.max(1, ...days.map((day) => Math.abs(day.words)));
    const hasNegative = days.some((day) => day.words < 0);

    return (
        <div className="mt-4">
            <div className="flex h-[150px] items-end gap-[3px]">
                {days.map((day) => {
                    const height = Math.round((Math.abs(day.words) / peak) * 100);

                    return (
                        <div
                            key={day.date}
                            title={t('analytics.day_words', { day: day.label, words: `${day.words >= 0 ? '+' : ''}${formatNumber(day.words)}` })}
                            className="flex min-w-0 flex-1 flex-col justify-end"
                            style={{ height: '100%' }}
                        >
                            <div
                                className={`flex ${hasNegative ? 'h-1/2' : 'h-full'} flex-col justify-end`}
                            >
                                {day.words >= 0 && (
                                    <div
                                        className={`w-full rounded-t-[2px] ${
                                            day.today ? 'bg-brand-fill' : 'bg-brand-primary/35'
                                        }`}
                                        style={{ height: `${height}%` }}
                                    />
                                )}
                            </div>

                            {hasNegative && (
                                <div className="h-1/2">
                                    {day.words < 0 && (
                                        <div
                                            className="w-full rounded-b-[2px] bg-status-danger/45"
                                            style={{ height: `${height}%` }}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            <div className="mt-2 flex justify-between text-[10.5px] text-text-secondary">
                <span>{days[0]?.label}</span>
                <span>{t('analytics.today')}</span>
            </div>
        </div>
    );
}

function Bars({ title, rows, onPick, empty, format, tone }) {
    const peak = Math.max(1, ...rows.map((row) => row.value));

    return (
        <section className="rounded-panel border border-border-default bg-bg-surface p-5">
            <h3 className="text-[15px] font-semibold text-text-primary">{title}</h3>

            {rows.length === 0 ? (
                <p className="mt-3 text-[13px] text-text-secondary">{empty}</p>
            ) : (
                <ul className="mt-3.5 space-y-2">
                    {rows.map((row) => (
                        <li key={row.key}>
                            <button
                                data-a={`analytics:bar:${row.key}`}
                                onClick={() => onPick?.(row.key)}
                                disabled={!onPick}
                                className="flex w-full items-center gap-3 text-left disabled:cursor-default"
                            >
                                <span className="w-[42%] shrink-0 truncate text-[12.5px] text-text-body">
                                    {row.label}
                                </span>
                                <span className="h-2 flex-1 overflow-hidden rounded-full bg-bg-subtle">
                                    <span
                                        className={`block h-full rounded-full ${
                                            tone === 'warning'
                                                ? 'bg-status-warning'
                                                : 'bg-brand-fill'
                                        }`}
                                        style={{ width: `${(row.value / peak) * 100}%` }}
                                    />
                                </span>
                                <span className="w-[52px] shrink-0 text-right text-[11.5px] tabular-nums text-text-secondary">
                                    {format(row.value)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
