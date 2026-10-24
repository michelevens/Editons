import AdminLayout, { formatDate } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { Badge, Pager, ReasonAction } from '@/Components/Admin/Kit';
import { Chips } from '@/Components/Admin/TrustKit';

/**
 * The moderation queue: "Report this book" from the public storefront.
 * Resolve when something was done, dismiss when nothing needed doing; the
 * owner can take the listing down from here, which resolves the report.
 */
export default function Moderation({ status, counts, reports, can }) {
    return (
        <AdminLayout title="Moderation" active="moderation">
            <Chips
                label="Status"
                value={status}
                dataA="admin:moderation:status"
                onChange={(next) => router.get(route('admin.moderation.index'), { status: next }, { preserveState: true })}
                options={['open', 'resolved', 'dismissed'].map((s) => [s, `${s[0].toUpperCase()}${s.slice(1)} (${counts[s] ?? 0})`])}
            />

            <ul className="mt-4 space-y-2" data-a="admin:moderation:list">
                {reports.data.length === 0 && (
                    <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">No {status} reports.</li>
                )}
                {reports.data.map((r) => (
                    <li key={r.id} className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]" data-a={`admin:report:${r.id}`}>
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <Badge tone={r.category === 'sexual_minors' || r.category === 'illegal' ? 'danger' : 'warning'}>{r.category_label}</Badge>
                            <span className="font-semibold text-text-primary">
                                {r.product ? r.product.title : r.author_handle ? `Author page ${r.author_handle}` : 'A page no longer on sale'}
                            </span>
                            <span className="ml-auto text-text-muted">
                                #{r.id} · {formatDate(r.created_at)}
                            </span>
                        </div>
                        {r.details && <p className="mt-1 whitespace-pre-line break-words text-text-body">{r.details}</p>}
                        <p className="mt-1 text-text-muted">
                            {r.reporter_email ? `Reply to ${r.reporter_email}` : 'No reply address'}
                            {r.same_source > 1 && ` · ${r.same_source} reports from the same address`}
                            {r.product?.account && (
                                <>
                                    {' · seller '}
                                    <Link href={route('admin.accounts.show', r.product.account.id)} className="text-brand-primary hover:underline">
                                        {r.product.account.name}
                                    </Link>
                                </>
                            )}
                        </p>
                        {r.status !== 'open' && (
                            <p className="mt-1 text-text-secondary">
                                {r.status === 'resolved' ? 'Resolved' : 'Dismissed'} by {r.resolver ?? 'staff'} {formatDate(r.resolved_at)}: {r.resolution}
                            </p>
                        )}
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {r.product && !r.product.taken_down && (
                                <a href={r.product.url} target="_blank" rel="noreferrer" className="text-brand-primary hover:underline">
                                    Public page
                                </a>
                            )}
                            {r.author_url && (
                                <a href={r.author_url} target="_blank" rel="noreferrer" className="text-brand-primary hover:underline">
                                    Author page
                                </a>
                            )}
                            {r.product?.taken_down && <Badge tone="danger">Listing taken down</Badge>}
                            {r.status === 'open' && (
                                <span className="ml-auto flex flex-wrap gap-2">
                                    {can.owner && r.product && !r.product.taken_down && (
                                        <ReasonAction
                                            label="Take down listing"
                                            danger
                                            route={route('admin.catalog.take-down', r.product.id)}
                                            extra={{ report_id: r.id }}
                                            dataA={`admin:report:${r.id}:take-down`}
                                            detail="The public page goes offline; buyers keep their copies; the author is emailed this reason. The report is resolved with it."
                                        />
                                    )}
                                    <ReasonAction
                                        label="Resolve"
                                        route={route('admin.moderation.update', r.id)}
                                        extra={{ status: 'resolved', _method: 'patch' }}
                                        dataA={`admin:report:${r.id}:resolve`}
                                        detail="Something was done about it. Say what."
                                    />
                                    <ReasonAction
                                        label="Dismiss"
                                        route={route('admin.moderation.update', r.id)}
                                        extra={{ status: 'dismissed', _method: 'patch' }}
                                        dataA={`admin:report:${r.id}:dismiss`}
                                        detail="Nothing needed doing. Say why."
                                    />
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <Pager links={reports.links} />
        </AdminLayout>
    );
}
