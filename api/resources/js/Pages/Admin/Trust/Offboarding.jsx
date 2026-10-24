import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Link } from '@inertiajs/react';
import { Badge, Empty } from '@/Components/Admin/Kit';
import { bytes } from '@/Components/Admin/TrustKit';

/**
 * Offboarding across the platform: deletions (scheduled, cancelled,
 * completed with a certificate) and data exports. Each account's own page
 * (from its account page) has the dry run and the actions.
 */
const TONE = { scheduled: 'warning', cancelled: 'neutral', completed: 'danger', queued: 'info', ready: 'success', failed: 'danger', expired: 'neutral' };

export default function Offboarding({ deletions, exports }) {
    return (
        <AdminLayout title="Offboarding" active="offboarding">
            <p className="mb-4 text-[12.5px] text-text-secondary">
                To export or delete an account, open it from{' '}
                <Link href={route('admin.accounts.index')} className="text-brand-primary hover:underline">
                    Accounts
                </Link>{' '}
                → Offboarding.
            </p>
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Account deletions" detail="Seven days' grace, suspended meanwhile; then deleted with a certificate." data-a="admin:offboarding:deletions">
                    {deletions.length === 0 ? (
                        <Empty>No deletion requested yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]">
                            {deletions.map((d) => (
                                <li key={d.id} className="py-2">
                                    <div className="flex flex-wrap items-baseline gap-x-2">
                                        <Badge tone={TONE[d.status]}>{d.status}</Badge>
                                        {d.user_id ? (
                                            <Link href={route('admin.offboarding.show', d.user_id)} className="font-medium text-text-primary hover:underline">
                                                {d.account?.email ?? `#${d.user_id}`}
                                            </Link>
                                        ) : (
                                            <span>account gone</span>
                                        )}
                                        <span className="ml-auto text-text-muted">{formatDate(d.created_at)}</span>
                                    </div>
                                    <p className="text-text-secondary">
                                        {d.status === 'scheduled' && `Due ${formatDate(d.scheduled_for)}${d.grace_over ? ' (grace over)' : ''} · `}
                                        {d.status === 'completed' && `Deleted ${formatDate(d.completed_at)} · certificate ${d.certificate_sha256?.slice(0, 12)}… · `}
                                        {d.status === 'cancelled' && `Cancelled ${formatDate(d.cancelled_at)}: ${d.cancel_reason} · `}
                                        {d.books} book(s), {d.files} file(s) · by {d.requested_by ?? 'staff'}
                                    </p>
                                    <p className="text-text-muted">{d.reason}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Data exports" detail="Kept seven days, then deleted." data-a="admin:offboarding:exports">
                    {exports.length === 0 ? (
                        <Empty>No exports yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]">
                            {exports.map((e) => (
                                <li key={e.id} className="py-2">
                                    <div className="flex flex-wrap items-baseline gap-x-2">
                                        <Badge tone={TONE[e.status]}>{e.status}</Badge>
                                        {e.user_id ? (
                                            <Link href={route('admin.offboarding.show', e.user_id)} className="font-medium text-text-primary hover:underline">
                                                {e.account?.email ?? `#${e.user_id}`}
                                            </Link>
                                        ) : (
                                            <span>account gone</span>
                                        )}
                                        <span className="ml-auto text-text-muted">{formatDate(e.created_at)}</span>
                                    </div>
                                    <p className="text-text-secondary">
                                        {e.contents ? `${e.contents.books} book(s), ${bytes(e.bytes)}` : e.error ?? 'Being built on the worker'}
                                        {e.emailed_at && ` · emailed ${formatDate(e.emailed_at)}`} · by {e.requested_by ?? 'staff'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}
