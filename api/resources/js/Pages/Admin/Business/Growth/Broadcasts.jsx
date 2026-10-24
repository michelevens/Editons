import AdminLayout, { Panel, buttonClass, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Empty, Pager, Table } from '@/Components/Admin/Kit';
import { GrowthTabs } from '@/Components/Admin/BusinessKit';
import { Link } from '@inertiajs/react';

/**
 * Broadcasts: one email to an audience, sent in throttled batches, only to
 * people who accept news. Sent, failed and skipped are counted; opens and
 * clicks are not tracked.
 */
const BROADCAST_TONE = { draft: 'neutral', scheduled: 'info', sending: 'warning', sent: 'success', cancelled: 'neutral' };

export default function Broadcasts({ broadcasts, can_edit: canEdit }) {
    return (
        <AdminLayout
            title="Growth"
            active="growth"
            actions={canEdit ? <Link href={route('admin.growth.broadcasts.create')} data-a="admin:broadcast:new" className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>New broadcast</Link> : null}
        >
            <GrowthTabs active="broadcasts" />
            <Panel title="Broadcasts" detail="Opens and clicks are not tracked: “sent” means handed to the mail provider.">
                {broadcasts.data.length === 0 ? (
                    <Empty>No broadcasts yet.</Empty>
                ) : (
                    <Table
                        head={['Subject', 'Audience', 'Status', 'When', 'Sent', 'Failed', 'Skipped']}
                        rows={broadcasts.data.map((b) => [
                            <Link key="s" href={route('admin.growth.broadcasts.edit', b.id)} className="text-brand-primary hover:underline" data-a={`admin:broadcast:${b.id}`}>{b.subject}</Link>,
                            b.audience,
                            <Badge key="b" tone={BROADCAST_TONE[b.status]}>{b.status}</Badge>,
                            formatDate(b.finished_at ?? b.started_at ?? b.scheduled_at ?? b.created_at),
                            b.sent,
                            b.failed,
                            b.skipped,
                        ])}
                    />
                )}
                <Pager links={broadcasts.links} />
            </Panel>
        </AdminLayout>
    );
}
