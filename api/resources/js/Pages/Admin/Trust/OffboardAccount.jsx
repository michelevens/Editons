import AdminLayout, { Panel, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link } from '@inertiajs/react';
import { Badge, Empty, ReasonAction, Table } from '@/Components/Admin/Kit';
import { bytes } from '@/Components/Admin/TrustKit';

/**
 * One account's offboarding: its data export, the dry run of deleting it
 * (exactly what goes, what is anonymised, what stays and why), and the
 * deletion itself — scheduled with seven days' grace, cancellable, carried
 * out with the account's email typed again, then a certificate.
 */
const TONE = { scheduled: 'warning', cancelled: 'neutral', completed: 'danger', queued: 'info', ready: 'success', failed: 'danger', expired: 'neutral' };

const label = (key) => key.replaceAll('_', ' ');

export default function OffboardAccount({ account, plan, deletions, exports, can }) {
    const open = deletions.find((d) => d.status === 'scheduled');
    const done = deletions.find((d) => d.status === 'completed');
    const owner = can.owner;

    return (
        <AdminLayout title={`Offboarding · ${account.name}`} active="offboarding">
            <Link href={route('admin.accounts.show', account.id)} className="text-[12.5px] text-brand-primary hover:underline">
                ← {account.email}
            </Link>

            {done && (
                <p role="status" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[13px] text-status-danger" data-a="admin:offboard:deleted">
                    Deleted {formatDate(done.completed_at)}. Certificate SHA-256 <code className="break-all">{done.certificate_sha256}</code>.{' '}
                    {owner && (
                        <>
                            <a href={route('admin.offboarding.certificate', done.id)} target="_blank" rel="noreferrer" className="underline" data-a="admin:offboard:certificate">
                                Certificate
                            </a>{' '}
                            ·{' '}
                            <a href={`${route('admin.offboarding.certificate', done.id)}?format=json`} className="underline">
                                JSON
                            </a>
                        </>
                    )}
                </p>
            )}

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel
                    title="Data export"
                    detail="Every book as Markdown and .ecrivain, any EPUB on file, metadata, the credit ledger, purchases and sales. Built on the worker; kept seven days."
                    data-a="admin:offboard:export"
                    actions={
                        owner &&
                        !account.offboarded_at && (
                            <ReasonAction
                                label="Export data"
                                route={route('admin.offboarding.export', account.id)}
                                dataA="admin:offboard:export:request"
                                initial={{ email_account: false }}
                                detail="Builds a zip of everything in the account. Downloading it is recorded."
                            >
                                {(form) => (
                                    <label className="flex items-center gap-2 text-[12.5px]">
                                        <input type="checkbox" data-a="admin:offboard:export:email" checked={form.data.email_account} onChange={(e) => form.setData('email_account', e.target.checked)} />
                                        Email the account a download link (signed, expires with the file)
                                    </label>
                                )}
                            </ReasonAction>
                        )
                    }
                >
                    {exports.length === 0 ? (
                        <Empty>No export yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]">
                            {exports.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-baseline gap-x-2 py-1.5">
                                    <Badge tone={TONE[e.status]}>{e.status}</Badge>
                                    <span className="text-text-secondary">
                                        {formatDate(e.created_at)}
                                        {e.contents && ` · ${e.contents.books} book(s), ${e.contents.epubs} EPUB, ${bytes(e.bytes)}`}
                                        {e.emailed_at && ' · emailed'}
                                        {e.error && ` · ${e.error}`}
                                    </span>
                                    {owner && e.downloadable && (
                                        <a href={route('admin.offboarding.export.download', e.id)} data-a={`admin:offboard:export:${e.id}:download`} className="ml-auto text-brand-primary hover:underline">
                                            Download
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Deletion" detail="Owner only. Never platform staff; never while a paid subscription is active." data-a="admin:offboard:deletion">
                    {account.offboarded_at ? (
                        <Empty>This account has been deleted.</Empty>
                    ) : open ? (
                        <div className="space-y-2 text-[12.5px]">
                            <p className="text-text-primary" data-a="admin:offboard:scheduled">
                                Scheduled by {open.requested_by ?? 'staff'} {formatDate(open.created_at)}: {open.reason}
                            </p>
                            <p className="text-text-secondary">
                                The account is suspended. {open.grace_over ? 'The grace period is over.' : `Deletion can be carried out from ${formatDate(open.scheduled_for)}.`}
                            </p>
                            {owner && (
                                <div className="flex flex-wrap gap-2">
                                    <ReasonAction label="Cancel deletion" route={route('admin.offboarding.cancel', open.id)} dataA="admin:offboard:cancel" detail="Nothing is deleted. The suspension scheduling put on is lifted." />
                                    {open.grace_over && (
                                        <ReasonAction
                                            label="Delete now"
                                            danger
                                            noReason
                                            route={route('admin.offboarding.execute', open.id)}
                                            dataA="admin:offboard:execute"
                                            initial={{ confirm_email: '' }}
                                            detail="Permanent. The books, their files and versions go; orders are kept without the buyer's details. A certificate is issued."
                                        >
                                            {(form) => <ConfirmEmail form={form} email={account.email} id="execute" />}
                                        </ReasonAction>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : plan?.refusals?.length ? (
                        <ul className="list-disc pl-5 text-[12.5px] text-status-danger" data-a="admin:offboard:refused">
                            {plan.refusals.map((r) => (
                                <li key={r}>{r}</li>
                            ))}
                        </ul>
                    ) : (
                        owner && (
                            <ReasonAction
                                label="Schedule deletion"
                                danger
                                route={route('admin.offboarding.schedule', account.id)}
                                dataA="admin:offboard:schedule"
                                initial={{ confirm_email: '' }}
                                detail={`Suspends the account now and deletes it after ${plan?.grace_days ?? 7} days, when you carry it out. Cancellable until then. Read the dry run below first.`}
                            >
                                {(form) => <ConfirmEmail form={form} email={account.email} id="schedule" />}
                            </ReasonAction>
                        )
                    )}
                </Panel>
            </div>

            {plan && <DryRun plan={plan} />}
        </AdminLayout>
    );
}

function ConfirmEmail({ form, email, id }) {
    return (
        <label className="block text-[12.5px]" htmlFor={`confirm-${id}`}>
            <span className="font-medium text-text-primary">
                Type <code>{email}</code> to confirm
            </span>
            <input
                id={`confirm-${id}`}
                data-a={`admin:offboard:${id}:confirm-email`}
                autoComplete="off"
                required
                value={form.data.confirm_email}
                onChange={(e) => form.setData('confirm_email', e.target.value)}
                className={`${inputClass} mt-1`}
            />
        </label>
    );
}

function DryRun({ plan }) {
    return (
        <Panel title="Dry run" detail={`What deleting this account would do, measured ${formatDate(plan.planned_at)}. Nothing has been changed.`} className="mt-4" data-a="admin:offboard:plan">
            {plan.warnings.length > 0 && (
                <ul className="mb-3 list-disc rounded-tile bg-status-warning-bg py-2 pl-7 pr-3 text-[12.5px] text-status-warning">
                    {plan.warnings.map((w) => (
                        <li key={w}>{w}</li>
                    ))}
                </ul>
            )}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div>
                    <h3 className="mb-1 text-[13px] font-semibold text-status-danger">Deleted</h3>
                    <Table head={['What', 'Rows']} rows={Object.entries(plan.delete).map(([k, v]) => [label(k), v])} />
                    <p className="mt-2 text-[12px] text-text-secondary" data-a="admin:offboard:plan:files">
                        Files: {plan.files.count} ({bytes(plan.files.bytes)}). {plan.files.measured}
                    </p>
                    {plan.files.sample.length > 0 && (
                        <ul className="mt-1 max-h-[140px] overflow-auto text-[11px] text-text-muted">
                            {plan.files.sample.map((f) => (
                                <li key={f} className="break-all">
                                    {f}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div>
                    <h3 className="mb-1 text-[13px] font-semibold text-status-warning">Anonymised</h3>
                    <Table head={['What', 'Rows']} rows={Object.entries(plan.anonymise).map(([k, v]) => [label(k), v])} />
                </div>
                <div>
                    <h3 className="mb-1 text-[13px] font-semibold text-text-primary">Kept, and why</h3>
                    <ul className="space-y-1.5 text-[12.5px]">
                        {Object.entries(plan.retain).map(([k, v]) => (
                            <li key={k}>
                                <span className="font-medium text-text-primary">
                                    {label(k)}: {v.rows}
                                </span>
                                <p className="text-text-secondary">{v.why}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </Panel>
    );
}
