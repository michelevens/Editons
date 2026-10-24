import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Empty, Errors, ReasonAction } from '@/Components/Admin/Kit';
import { STATE_TONES } from '@/Components/Admin/TrustKit';

/**
 * Data health: the latest stored run (daily, or "Run now"). Each check
 * says what it measured and "unknown" when it could not. A fix runs as a
 * dry run first; applying it needs a reason and is audited.
 */
export default function DataHealth({ run, history, preview, can }) {
    const [running, setRunning] = useState(false);

    return (
        <AdminLayout
            title="Data health"
            active="data-health"
            actions={
                <button
                    type="button"
                    data-a="admin:health:run"
                    disabled={running}
                    onClick={() => router.post(route('admin.data-health.run'), {}, { preserveScroll: true, onStart: () => setRunning(true), onFinish: () => setRunning(false) })}
                    className="tap rounded-control px-3 py-2 text-[12.5px] font-semibold text-brand-primary hover:bg-bg-subtle disabled:opacity-50"
                >
                    {running ? 'Running…' : 'Run now'}
                </button>
            }
        >
            {!run ? (
                <Empty>The checks have not run yet. They run daily at 04:10; run them now to see where things stand.</Empty>
            ) : (
                <>
                    <p className="mb-3 text-[12px] text-text-muted" data-a="admin:health:when">
                        {run.trigger === 'schedule' ? 'Scheduled run' : `Run by ${run.by ?? 'staff'}`} · {formatDate(run.started_at)}
                        {run.seconds !== null && ` · ${run.seconds} s`}
                    </p>
                    <ul className="space-y-2" data-a="admin:health:checks">
                        {run.results.map((check) => (
                            <Check key={check.key} check={check} owner={can.owner} preview={preview?.check === check.key ? preview : null} />
                        ))}
                    </ul>
                </>
            )}

            {history.length > 1 && (
                <Panel title="Recent runs" className="mt-4">
                    <ul className="divide-y divide-border-divider text-[12.5px]">
                        {history.map((h) => (
                            <li key={h.id} className="flex flex-wrap gap-x-3 py-1.5">
                                <span className="text-text-primary">{formatDate(h.started_at)}</span>
                                <span className="text-text-secondary">{h.trigger}</span>
                                <span className="ml-auto text-text-secondary">
                                    {h.problems} with problems · {h.unknown} unknown
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </AdminLayout>
    );
}

function Check({ check, owner, preview }) {
    const [open, setOpen] = useState(false);
    const dry = useForm({ dry_run: true });

    return (
        <li className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]" data-a={`admin:health:${check.key}`}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-[13.5px] font-semibold text-text-primary">{check.label}</span>
                <Badge tone={STATE_TONES[check.status]}>{check.status}</Badge>
                <span className="tabular-nums text-text-secondary">{check.count === null ? 'not measured' : check.count.toLocaleString()}</span>
                {check.rows.length > 0 && (
                    <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="ml-auto text-brand-primary hover:underline">
                        {open ? 'Hide rows' : `Show ${check.rows.length} row${check.rows.length === 1 ? '' : 's'}`}
                    </button>
                )}
            </div>
            <p className="mt-1 text-text-secondary">{check.detail}</p>

            {open && (
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full min-w-[320px] text-left text-[12px]">
                        <thead className="text-text-secondary">
                            <tr>
                                {Object.keys(check.rows[0]).map((k) => (
                                    <th key={k} className="py-1 pr-3 font-medium">
                                        {k}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="tabular-nums">
                            {check.rows.map((row, i) => (
                                <tr key={i} className="border-t border-border-divider">
                                    {Object.entries(row).map(([k, v]) => (
                                        <td key={k} className="py-1 pr-3">
                                            {k === 'user_id' && v ? (
                                                <Link href={route('admin.accounts.show', v)} className="text-brand-primary hover:underline">
                                                    #{v}
                                                </Link>
                                            ) : k === 'ticket_id' ? (
                                                <Link href={route('admin.support.show', v)} className="text-brand-primary hover:underline">
                                                    #{v}
                                                </Link>
                                            ) : (
                                                String(v ?? '—')
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {check.fix && owner && (check.count ?? 0) > 0 && (
                <div className="mt-2 rounded-tile bg-bg-subtle px-3 py-2">
                    <p className="text-text-secondary">Fix: {check.fix}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            data-a={`admin:health:${check.key}:dry-run`}
                            disabled={dry.processing}
                            onClick={() => dry.post(route('admin.data-health.fix', check.key), { preserveScroll: true })}
                            className="tap rounded-control border border-border-default bg-bg-surface px-3 py-1.5 font-semibold text-text-primary hover:bg-bg-subtle"
                        >
                            Dry run
                        </button>
                        {preview && (
                            <>
                                <span className="text-text-primary" data-a={`admin:health:${check.key}:preview`}>
                                    Would change {preview.changed}
                                    {preview.ids.length > 0 && ` (ids ${preview.ids.slice(0, 12).join(', ')}${preview.ids.length > 12 ? '…' : ''})`}.
                                </span>
                                {preview.changed > 0 && (
                                    <ReasonAction
                                        label="Apply fix"
                                        danger
                                        route={route('admin.data-health.fix', check.key)}
                                        extra={{ dry_run: false }}
                                        dataA={`admin:health:${check.key}:apply`}
                                        detail={check.fix}
                                    />
                                )}
                            </>
                        )}
                    </div>
                    <Errors errors={dry.errors} />
                </div>
            )}
        </li>
    );
}
