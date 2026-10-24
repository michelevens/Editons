import AdminLayout, { Panel, buttonClass, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, ReasonAction, Table } from '@/Components/Admin/Kit';
import { STATE_TONES, perkSummary } from '@/Components/Admin/InvitationKit';
import { Link, router } from '@inertiajs/react';

/**
 * One invitation: who, what it carries, and what happened to it, from the
 * send to the account's first export. Resend mints a new link (the old one
 * stops working); revoke closes it.
 */
export default function InvitationShow({ invitation: i, timeline, emails, max_resends: maxResends }) {
    const canResend = ['pending', 'expired'].includes(i.state) && i.resend_count < maxResends;

    return (
        <AdminLayout
            title="Invitation"
            active="invitations"
            actions={
                <Link href={route('admin.invitations.index')} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>
                    All invitations
                </Link>
            }
        >
            <Panel
                title={<span className="break-all">{i.email}</span>}
                detail={i.name}
                data-a="admin:invitation"
                actions={
                    <div className="flex flex-wrap gap-2">
                        {canResend && (
                            <button
                                type="button"
                                data-a="admin:invitation:resend"
                                onClick={() => router.post(route('admin.invitations.resend', i.id), {}, { preserveScroll: true })}
                                className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}
                            >
                                Send again
                            </button>
                        )}
                        {i.state === 'pending' && (
                            <ReasonAction label="Revoke" route={route('admin.invitations.revoke', i.id)} dataA="admin:invitation:revoke" danger detail="The link stops working at once. The person is not told." />
                        )}
                    </div>
                }
            >
                <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                    <Row label="State"><Badge tone={STATE_TONES[i.state]}>{i.state}</Badge></Row>
                    <Row label="Language">{i.language}</Row>
                    <Row label="Invited by">{i.inviter?.name ?? '—'}</Row>
                    <Row label="Batch">{i.batch?.label ?? 'Sent on its own'}</Row>
                    <Row label="Campaign">{i.campaign ?? '—'}</Row>
                    <Row label="Sent">{formatDate(i.sent_at)}{i.resend_count > 0 && ` · sent again ${i.resend_count} of ${maxResends} times`}</Row>
                    <Row label="Link open until">{formatDate(i.expires_at)}</Row>
                    <Row label="Perks">{perkSummary(i.perks) || 'None'}</Row>
                    {i.accepted_user && (
                        <Row label="Account">
                            <Link href={route('admin.accounts.show', i.accepted_user.id)} data-a="admin:invitation:account" className="text-brand-primary hover:underline">{i.accepted_user.name}</Link>
                            {' · '}accepted {formatDate(i.accepted_at)}
                        </Row>
                    )}
                    {i.perks_applied && (
                        <Row label="Applied">
                            <span data-a="admin:invitation:applied">
                                {[
                                    i.perks_applied.plan && `${i.perks_applied.plan} until ${formatDate(i.perks_applied.plan_until, false)}`,
                                    i.perks_applied.credits && `${i.perks_applied.credits.toLocaleString()} credits`,
                                    i.perks_applied.plan_skipped && `plan not given: ${i.perks_applied.plan_skipped}`,
                                ].filter(Boolean).join(' · ')}
                            </span>
                        </Row>
                    )}
                </dl>
                {i.note && (
                    <blockquote className="mt-3 whitespace-pre-line border-l-2 border-border-default pl-3 text-[13px] text-text-secondary">{i.note}</blockquote>
                )}
            </Panel>

            <Panel className="mt-4" title="Timeline" data-a="admin:invitation:timeline">
                {timeline.length === 0 ? (
                    <Empty>Nothing yet.</Empty>
                ) : (
                    <ol className="space-y-2">
                        {timeline.map((event, index) => (
                            <li key={index} className="flex flex-wrap gap-x-3 text-[13px]">
                                <span className="w-[170px] shrink-0 text-text-secondary tabular-nums">{formatDate(event.at)}</span>
                                <span className="font-medium text-text-primary">{event.event}</span>
                                {event.actor && <span className="text-text-secondary">by {event.actor}</span>}
                                {event.detail && <span className="w-full text-[12px] text-text-secondary sm:w-auto">“{event.detail}”</span>}
                            </li>
                        ))}
                    </ol>
                )}
            </Panel>

            <Panel className="mt-4" title="Emails" detail="From the email log. The link is a secret, so only the metadata is kept; “sent” means handed to the mail provider.">
                {emails.length === 0 ? (
                    <Empty>No email logged yet (queued mail appears once the worker sends it).</Empty>
                ) : (
                    <Table head={['When', 'Status', 'Subject']} rows={emails.map((e) => [formatDate(e.at), <Badge key="s" tone={e.status === 'failed' ? 'danger' : 'success'}>{e.status}</Badge>, e.error ?? e.subject])} />
                )}
            </Panel>
        </AdminLayout>
    );
}

function Row({ label, children }) {
    return (
        <div className="flex min-w-0 gap-2">
            <dt className="w-[110px] shrink-0 text-text-secondary">{label}</dt>
            <dd className="min-w-0 text-text-primary">{children}</dd>
        </div>
    );
}
