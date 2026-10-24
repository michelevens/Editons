import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Badge, Table } from '@/Components/Admin/Kit';
import { STATE_TONES } from '@/Components/Admin/TrustKit';

/** What is deployed. Read-only. */
export default function Releases({ version, railway, runtime, drivers, features, migrations, heartbeats }) {
    return (
        <AdminLayout title="Releases" active="releases">
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Deployed version" data-a="admin:releases:version">
                    {version.sha ? (
                        <p className="text-[13px] text-text-primary">
                            <code className="break-all font-semibold">{version.short}</code>
                            {version.branch && <span className="text-text-secondary"> on {version.branch}</span>}
                            {version.dirty && (
                                <span className="ml-2">
                                    <Badge tone="warning">uncommitted changes</Badge>
                                </span>
                            )}
                        </p>
                    ) : (
                        <p className="text-[13px] text-text-secondary">
                            Unknown. The deploy is an upload, not a git build, and no release stamp was found. Run <code>php artisan platform:stamp-release</code> before{' '}
                            <code>railway up</code>.
                        </p>
                    )}
                    <p className="mt-1 text-[12px] text-text-muted">
                        {version.source ? `From ${version.source}.` : ''} Deployed {version.deployed_at ? formatDate(version.deployed_at) : 'at an unknown time'}
                        {version.deployed_at_source && ` (${version.deployed_at_source})`}.
                    </p>
                    {Object.keys(railway).length > 0 && (
                        <div className="mt-3">
                            <Table head={['Railway', '']} rows={Object.entries(railway).map(([k, v]) => [<code key="k">{k}</code>, <code key="v" className="break-all">{v}</code>])} />
                        </div>
                    )}
                </Panel>

                <Panel title="Runtime and drivers" data-a="admin:releases:runtime">
                    <Table
                        head={['', '']}
                        rows={[
                            ['PHP', runtime.php],
                            ['Laravel', runtime.laravel],
                            ['Environment', `${runtime.environment}${runtime.debug ? ' (debug on)' : ''}`],
                            ['Time zone', runtime.timezone],
                            ...Object.entries(drivers).map(([k, v]) => [k.replace('_', ' '), v]),
                        ]}
                    />
                </Panel>

                <Panel title="Migrations" data-a="admin:releases:migrations">
                    {migrations.error ? (
                        <p className="text-[12.5px] text-status-danger">{migrations.error}</p>
                    ) : (
                        <>
                            <p className="text-[13px] text-text-primary">
                                {migrations.ran} ran · <span className={migrations.pending.length ? 'font-semibold text-status-warning' : ''}>{migrations.pending.length} pending</span>
                            </p>
                            {migrations.pending.length > 0 && (
                                <ul className="mt-1 text-[12px] text-text-secondary">
                                    {migrations.pending.map((m) => (
                                        <li key={m} className="break-all">
                                            <code>{m}</code>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </Panel>

                <Panel title="Worker and scheduler" data-a="admin:releases:heartbeats">
                    <ul className="space-y-1.5 text-[12.5px]">
                        {heartbeats.map((h) => (
                            <li key={h.key}>
                                <span className="font-medium text-text-primary">{h.label}</span> <Badge tone={STATE_TONES[h.status]}>{h.status}</Badge>
                                <p className="text-text-secondary">{h.detail}</p>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <Panel title="Feature flags" detail="Platform-wide, before any account override." className="xl:col-span-2" data-a="admin:releases:features">
                    <Table
                        head={['Module', 'State', 'Decided by']}
                        rows={features.map((f) => [f.label, <Badge key="s" tone={f.enabled ? 'success' : 'neutral'}>{f.enabled ? 'on' : 'off'}</Badge>, f.source])}
                    />
                </Panel>
            </div>
        </AdminLayout>
    );
}
