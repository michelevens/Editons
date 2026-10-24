import AdminLayout, { formatDate } from '@/Layouts/AdminLayout';
import { Link } from '@inertiajs/react';
import { Badge, Pager } from '@/Components/Admin/Kit';

/** Every "View as" session: who, whom, why, and how it ended. */
export default function Impersonations({ sessions, filters }) {
    return (
        <AdminLayout title="Impersonation log" active="impersonations">
            {(filters.admin || filters.target) && (
                <p className="mb-3 text-[12.5px] text-text-secondary">
                    Filtered. <Link href={route('admin.impersonations.index')} className="text-brand-primary hover:underline">Show all</Link>
                </p>
            )}
            <div className="overflow-x-auto rounded-panel border border-border-default bg-bg-surface">
                {sessions.data.length === 0 ? (
                    <p className="p-6 text-center text-[13px] text-text-secondary">No View as sessions yet.</p>
                ) : (
                    <table className="w-full min-w-[720px] text-left text-[12.5px]" data-a="admin:impersonations">
                        <thead className="bg-bg-subtle text-text-secondary">
                            <tr>
                                {['Started', 'Staff', 'Account', 'Reason', 'Ended', 'How'].map((cell) => (
                                    <th key={cell} className="px-3 py-2 font-medium">
                                        {cell}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sessions.data.map((s) => (
                                <tr key={s.id} className="border-t border-border-divider align-top">
                                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(s.started_at)}</td>
                                    <td className="px-3 py-2">{s.impersonator ?? 'Former staff'}</td>
                                    <td className="px-3 py-2">
                                        {s.target ? (
                                            <Link href={route('admin.accounts.show', s.target.id)} className="text-brand-primary hover:underline">
                                                {s.target.name}
                                            </Link>
                                        ) : (
                                            'Deleted account'
                                        )}
                                        <p className="text-text-muted">{s.target?.email}</p>
                                    </td>
                                    <td className="max-w-[320px] px-3 py-2">{s.reason}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{s.ended_at ? formatDate(s.ended_at) : '—'}</td>
                                    <td className="px-3 py-2">
                                        <Badge tone={s.end_reason === 'live' ? 'danger' : 'neutral'}>{s.end_reason}</Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
            <Pager links={sessions.links} />
        </AdminLayout>
    );
}
