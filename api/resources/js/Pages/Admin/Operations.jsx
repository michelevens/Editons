import AdminLayout, { Panel, age, formatDate } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { Badge, Empty, ReasonAction } from '@/Components/Admin/Kit';

/**
 * Jobs & health: measured, never guessed. "Unknown" means the page could
 * not measure it — a scheduler that has never reported, a bucket that does
 * not say how full it is — not that it is fine.
 */
const TONES = { ok: 'success', warning: 'warning', failing: 'danger', unknown: 'neutral', late: 'warning', never: 'danger', stuck: 'danger' };

export default function Operations({ health, jobs, backlog, failed, failed_total: failedTotal, stuck_exports: stuck, can, checked_at: checkedAt }) {
    return (
        <AdminLayout
            title="Jobs & health"
            active="operations"
            actions={
                <button
                    type="button"
                    data-a="admin:ops:refresh"
                    onClick={() => router.reload({ preserveScroll: true })}
                    className="tap rounded-control px-3 py-2 text-[12.5px] font-semibold text-brand-primary hover:bg-bg-subtle"
                >
                    Check again
                </button>
            }
        >
            <p className="mb-3 text-[12px] text-text-muted">Checked {formatDate(checkedAt)}.</p>

            <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4" data-a="admin:ops:health">
                {health.map((check) => (
                    <li key={check.key} className="rounded-panel border border-border-default bg-bg-surface p-3" data-a={`admin:ops:health:${check.key}`}>
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[13px] font-semibold text-text-primary">{check.label}</span>
                            <Badge tone={TONES[check.status]}>{check.status}</Badge>
                        </div>
                        <p className="mt-1 text-[12px] text-text-secondary">
                            {check.detail}
                            {typeof check.ms === 'number' && <span className="text-text-muted"> ({check.ms} ms)</span>}
                        </p>
                    </li>
                ))}
            </ul>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Scheduled commands" detail="From routes/console.php. Each run writes a heartbeat; late means its due time passed without it starting." className="xl:col-span-2">
                    <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:ops:jobs">
                        {jobs.map((job) => (
                            <li key={job.key} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:gap-3">
                                <div className="min-w-0 flex-1">
                                    <code className="font-semibold text-text-primary">{job.key}</code>
                                    <span className="ml-2 text-text-muted">{job.expression}</span>
                                    <p className="text-text-secondary">
                                        Last started {job.last_started_at ? formatDate(job.last_started_at) : 'never'} · last success{' '}
                                        {job.last_success_at ? formatDate(job.last_success_at) : 'never'}
                                        {job.last_duration_ms !== null && job.last_duration_ms !== undefined && ` · ${job.last_duration_ms} ms`} · next {formatDate(job.next_due_at)}
                                    </p>
                                    {job.last_error && <p className="break-words text-status-danger">{job.last_error}</p>}
                                </div>
                                <Badge tone={TONES[job.status]}>
                                    {job.status}
                                    {job.failure_count > 1 && ` ×${job.failure_count}`}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <Panel title="Queue" detail={backlog.measurable ? `Connection ${backlog.connection}.` : `Connection ${backlog.connection}: backlog is not measurable here (database queues only).`}>
                    {!backlog.measurable ? (
                        <Empty>Unknown.</Empty>
                    ) : backlog.by_queue.length === 0 ? (
                        <Empty>Nothing waiting.</Empty>
                    ) : (
                        <ul className="text-[12.5px]" data-a="admin:ops:backlog">
                            {backlog.by_queue.map((q) => (
                                <li key={q.queue} className="flex justify-between border-t border-border-divider py-1.5 first:border-0">
                                    <code>{q.queue}</code>
                                    <span className="tabular-nums text-text-secondary">
                                        {q.waiting} waiting · {q.reserved} running{q.oldest_minutes !== null && ` · oldest ${q.oldest_minutes} min`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Stuck exports" detail="Still generating past the job timeout: the worker died without saying so. exports:expire settles these every five minutes.">
                    {stuck.length === 0 ? (
                        <Empty>None.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:ops:stuck">
                            {stuck.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-center gap-2 py-1.5">
                                    <span className="min-w-0 flex-1">
                                        #{e.id} {e.format.toUpperCase()} · {e.book ? e.book.title : 'deleted book'} · generating for {age(e.since)}
                                        {e.book && (
                                            <>
                                                {' '}·{' '}
                                                <Link href={route('admin.accounts.show', e.book.user_id)} className="text-brand-primary hover:underline">
                                                    account
                                                </Link>
                                            </>
                                        )}
                                    </span>
                                    <ReasonAction label="Mark failed" route={route('admin.exports.fail', e.id)} dataA={`admin:ops:fail-export:${e.id}`} detail="Its author is told it stopped, and can generate it again." danger />
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title={`Failed jobs (${failedTotal})`} detail={can.owner ? 'Only exports and mail can be retried: other jobs may not be safe to run twice.' : 'Retrying and forgetting are the owner’s.'} className="xl:col-span-2">
                    {failed.length === 0 ? (
                        <Empty>No failed jobs.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:ops:failed">
                            {failed.map((job) => (
                                <li key={job.uuid} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:gap-3">
                                    <div className="min-w-0 flex-1">
                                        <code className="font-semibold text-text-primary">{job.name.split('\\').pop()}</code>
                                        <span className="text-text-secondary">
                                            {' '}· {job.queue} · {formatDate(job.failed_at)}
                                            {job.export_id && ` · export #${job.export_id}`}
                                        </span>
                                        <p className="break-words text-text-muted">{job.exception}</p>
                                    </div>
                                    {can.owner && (
                                        <div className="flex gap-2">
                                            {job.retryable && <ReasonAction label="Retry" route={route('admin.failed-jobs.retry', job.uuid)} dataA={`admin:ops:retry:${job.uuid}`} />}
                                            <ForgetAction uuid={job.uuid} />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}

/** Forget is a DELETE; ReasonAction posts, so this sends the method override. */
function ForgetAction({ uuid }) {
    return <ReasonAction label="Forget" route={route('admin.failed-jobs.forget', uuid)} dataA={`admin:ops:forget:${uuid}`} extra={{ _method: 'delete' }} danger detail="The failed job is deleted. It is not run." />;
}
