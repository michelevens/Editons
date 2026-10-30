import { useCallback, useEffect, useState } from 'react';
import { t, useI18n } from '../../i18n';
import Icon from './Icon';

/**
 * The Launch Planner.
 *
 * The timeline is computed from the launch date, so moving the date moves
 * every task with it. Nothing here sends email: marking a copy as sent
 * records that the author sent one, and the wording says so rather than
 * implying the application delivered it.
 */
export default function LaunchPanel({ bookId, onNotify }) {
    useI18n();
    const [data, setData] = useState(null);
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/launch`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const patch = async (body) => {
        try {
            const response = await window.axios.patch(`/books/${bookId}/launch`, body);
            setData(response.data);
        } catch {
            onNotify?.(t('launch.not_saved'));
        }
    };

    const addReader = async (event) => {
        event.preventDefault();
        setBusy('add');

        try {
            const response = await window.axios.post(`/books/${bookId}/readers`, {
                name,
                email,
            });

            setData(response.data);
            setName('');
            setEmail('');
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('launch.reader_not_added'));
        } finally {
            setBusy(null);
        }
    };

    const setReaderStatus = async (reader, status) => {
        setBusy(`reader:${reader.id}`);

        try {
            const response = await window.axios.patch(
                `/books/${bookId}/readers/${reader.id}`,
                { status },
            );

            setData(response.data);
        } finally {
            setBusy(null);
        }
    };

    const removeReader = async (reader) => {
        setBusy(`reader:${reader.id}`);

        try {
            const response = await window.axios.delete(`/books/${bookId}/readers/${reader.id}`);
            setData(response.data);
        } finally {
            setBusy(null);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { tasks, readers, launch_date: launchDate, days_away: daysAway, reviews, statuses } = data;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">{t('launch.title')}</h2>
                    <p className="text-[11.5px] text-text-secondary">
                        {launchDate === null
                            ? t('launch.pick_date')
                            : daysAway > 0
                              ? t('launch.days_to', { count: daysAway })
                              : daysAway === 0
                                ? t('launch.launch_day')
                                : t('launch.launched_ago', { count: -daysAway })}
                    </p>
                </div>

                <label className="ml-auto flex items-center gap-2 text-[11.5px] text-text-secondary">
                    {t('launch.date')}
                    <input
                        type="date"
                        value={launchDate ?? ''}
                        data-a="launch:date"
                        onChange={(event) => patch({ launch_date: event.target.value || null })}
                        className="rounded-control border border-border-input px-2 py-1 text-[12px] text-text-primary focus:border-brand-primary focus:ring-0"
                    />
                </label>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                        {t('launch.timeline')}{' '}
                        <span className="font-normal text-text-secondary">
                            {t('launch.done_of', { done: data.done_count, total: data.task_count })}
                        </span>
                    </h3>

                    <ul>
                        {tasks.map((task) => (
                            <li
                                key={task.key}
                                className="flex items-start gap-2.5 border-b border-border-default py-2 last:border-0"
                            >
                                <button
                                    role="checkbox"
                                    aria-checked={task.done}
                                    aria-label={task.label}
                                    data-a={`launch:task:${task.key}`}
                                    onClick={() => patch({ task: task.key, done: !task.done })}
                                    className={`mt-0.5 flex h-[17px] w-[17px] shrink-0 items-center justify-center rounded-full transition ${
                                        task.done
                                            ? 'bg-status-success-fill text-white'
                                            : task.overdue
                                              ? 'border-2 border-status-warning'
                                              : 'border border-border-input'
                                    }`}
                                >
                                    {task.done && <Icon name="check" size={9} strokeWidth={3.6} />}
                                </button>

                                <div className="min-w-0 flex-1">
                                    <p
                                        className={`text-[12.5px] font-medium ${
                                            task.done
                                                ? 'text-text-secondary line-through'
                                                : 'text-text-primary'
                                        }`}
                                    >
                                        {task.label}
                                    </p>
                                    <p className="text-[11px] leading-snug text-text-secondary">
                                        {task.detail}
                                    </p>
                                </div>

                                <span
                                    className={`shrink-0 text-[11px] ${
                                        task.overdue
                                            ? 'font-semibold text-status-warning'
                                            : 'text-text-secondary'
                                    }`}
                                >
                                    {task.due ?? t('launch.offset_days', { offset: `${task.offset > 0 ? '+' : ''}${task.offset}` })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <h3 className="mb-1 text-[13px] font-semibold text-text-primary">
                        {t('launch.readers')}{' '}
                        <span className="font-normal text-text-secondary">
                            {t('launch.reviews', { count: reviews })}
                        </span>
                    </h3>

                    <p className="mb-2.5 text-[11px] leading-relaxed text-text-secondary">
                        {t('launch.readers_help')}
                    </p>

                    {readers.length > 0 && (
                        <ul className="mb-3">
                            {readers.map((reader) => (
                                <li
                                    key={reader.id}
                                    className="flex flex-wrap items-center gap-2 border-b border-border-default py-2 last:border-0"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[12.5px] font-medium text-text-primary">
                                            {reader.name}
                                        </p>
                                        <p className="truncate text-[11px] text-text-secondary">
                                            {reader.email}
                                            {reader.copy_sent_at
                                                ? ` · ${t('launch.copy_sent', { date: reader.copy_sent_at })}`
                                                : ''}
                                        </p>
                                    </div>

                                    <select
                                        value={reader.status}
                                        data-a={`launch:reader-status:${reader.id}`}
                                        disabled={busy === `reader:${reader.id}`}
                                        onChange={(event) =>
                                            setReaderStatus(reader, event.target.value)
                                        }
                                        className="rounded-control border border-border-input px-1.5 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                                    >
                                        {statuses.map((status) => (
                                            <option key={status} value={status}>
                                                {statusLabel(status)}
                                            </option>
                                        ))}
                                    </select>

                                    <button
                                        data-a={`launch:reader-remove:${reader.id}`}
                                        title={t('launch.remove_reader', { name: reader.name })}
                                        disabled={busy === `reader:${reader.id}`}
                                        onClick={() => removeReader(reader)}
                                        className="text-text-secondary transition hover:text-status-danger disabled:opacity-40"
                                    >
                                        <Icon name="x" size={13} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <form onSubmit={addReader} className="flex flex-wrap items-end gap-2">
                        <label className="min-w-[120px] flex-1">
                            <span className="sr-only">{t('launch.reader_name')}</span>
                            <input
                                value={name}
                                required
                                data-a="launch:reader-name"
                                placeholder={t('launch.name')}
                                onChange={(event) => setName(event.target.value)}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12px] focus:border-brand-primary focus:ring-0"
                            />
                        </label>

                        <label className="min-w-[160px] flex-1">
                            <span className="sr-only">{t('launch.reader_email')}</span>
                            <input
                                value={email}
                                type="email"
                                required
                                data-a="launch:reader-email"
                                placeholder={t('launch.email')}
                                onChange={(event) => setEmail(event.target.value)}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12px] focus:border-brand-primary focus:ring-0"
                            />
                        </label>

                        <button
                            type="submit"
                            data-a="launch:reader-add"
                            disabled={busy === 'add' || !name.trim() || !email.trim()}
                            className="rounded-control bg-brand-fill px-2.5 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            {busy === 'add' ? t('launch.adding') : t('launch.add_reader')}
                        </button>
                    </form>
                </section>
            </div>
        </div>
    );
}

/** A reader's status (AdvanceReader::STATUSES) in the interface language. */
function statusLabel(status) {
    switch (status) {
        case 'invited':
            return t('launch.status_invited');
        case 'sent':
            return t('launch.status_sent');
        case 'reading':
            return t('launch.status_reading');
        case 'reviewed':
            return t('launch.status_reviewed');
        case 'declined':
            return t('launch.status_declined');
        default:
            return status.charAt(0).toUpperCase() + status.slice(1);
    }
}
