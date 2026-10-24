import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { Badge, Empty, ReasonAction, Table } from '@/Components/Admin/Kit';
import { Chips } from '@/Components/Admin/TrustKit';

/**
 * Sign-in security across the platform. Failed attempts keep a keyed hash
 * of the address typed, never the address. "Sign out" is the account page's
 * own action: every session ends, on every device.
 */
const EVENT = {
    success: ['Signed in', 'success'],
    view_as: ['View as', 'info'],
    failure: ['Failed', 'warning'],
    lockout: ['Locked out', 'danger'],
};

export default function Security({ window, summary, top_ips: topIps, top_targets: topTargets, events, two_factor: tf, staff, sessions }) {
    const pct = (a, b) => (b ? `${Math.round((a / b) * 100)}%` : '—');

    return (
        <AdminLayout title="Security" active="security">
            <Chips
                label="Window"
                value={window}
                dataA="admin:security:window"
                onChange={(next) => router.get(route('admin.security.index'), { window: next }, { preserveState: true })}
                options={[['24h', 'Last 24 hours'], ['7d', '7 days'], ['30d', '30 days']]}
            />

            <ul className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6" data-a="admin:security:summary">
                {[
                    ['Sign-ins', summary.sign_ins],
                    ['Accounts', summary.accounts],
                    ['New locations', summary.new_locations],
                    ['Wrong passwords', summary.failed_passwords],
                    ['Wrong codes', summary.failed_codes],
                    ['Lockouts', summary.lockouts],
                ].map(([label, n]) => (
                    <li key={label} className="rounded-panel border border-border-default bg-bg-surface p-3">
                        <p className="text-[11.5px] text-text-secondary">{label}</p>
                        <p className="text-[20px] font-bold tabular-nums text-text-primary">{n.toLocaleString()}</p>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-[12px] text-text-muted" data-a="admin:security:basis">
                {summary.location_basis}
            </p>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Two-factor adoption" data-a="admin:security:2fa">
                    <p className="text-[13px] text-text-primary">
                        {tf.with.toLocaleString()} of {tf.accounts.toLocaleString()} accounts ({pct(tf.with, tf.accounts)}) · staff {tf.staff_with} of {tf.staff}
                    </p>
                </Panel>

                <Panel title="Signed-in sessions" detail={sessions.note} data-a="admin:security:sessions">
                    {!sessions.measurable || sessions.top.length === 0 ? (
                        <Empty>{sessions.measurable ? 'Nobody is signed in right now.' : 'Not measurable with this session driver.'}</Empty>
                    ) : (
                        <Table
                            head={['Account', 'Sessions', 'Last active', '']}
                            rows={sessions.top.map((s) => [
                                s.user ? (
                                    <Link href={route('admin.accounts.show', s.user.id)} className="text-brand-primary hover:underline">
                                        {s.user.email}
                                    </Link>
                                ) : (
                                    '—'
                                ),
                                s.sessions,
                                formatDate(s.last_active),
                                s.user ? <SignOut user={s.user} /> : null,
                            ])}
                        />
                    )}
                </Panel>

                <Panel title="Staff accounts" className="xl:col-span-2" data-a="admin:security:staff">
                    <Table
                        head={['Name', 'Role', 'Two-factor', 'Last sign-in', 'Sessions', '']}
                        rows={staff.map((s) => [
                            <Link key="n" href={route('admin.accounts.show', s.id)} className="text-brand-primary hover:underline">
                                {s.name} <span className="text-text-muted">{s.email}</span>
                            </Link>,
                            s.role,
                            <Badge key="t" tone={s.two_factor ? 'success' : 'danger'}>{s.two_factor ? 'on' : 'off'}</Badge>,
                            s.last_sign_in ? formatDate(s.last_sign_in) : 'never',
                            s.sessions ?? 'n/a',
                            <SignOut key="o" user={s} />,
                        ])}
                    />
                </Panel>

                <Panel title="Most failures by IP" data-a="admin:security:ips">
                    {topIps.length === 0 ? (
                        <Empty>No failed sign-ins in this window.</Empty>
                    ) : (
                        <Table head={['IP', 'Failures', 'Addresses tried']} rows={topIps.map((r) => [<code key="ip">{r.ip ?? '—'}</code>, r.n, r.addresses])} />
                    )}
                </Panel>

                <Panel title="Accounts with the most failures" data-a="admin:security:targets">
                    {topTargets.length === 0 ? (
                        <Empty>No failed sign-in against an existing account.</Empty>
                    ) : (
                        <Table
                            head={['Account', 'Failures', '']}
                            rows={topTargets.map((r) => [
                                r.user ? (
                                    <Link key="u" href={route('admin.accounts.show', r.user.id)} className="text-brand-primary hover:underline">
                                        {r.user.email}
                                    </Link>
                                ) : (
                                    '—'
                                ),
                                r.n,
                                r.user ? <SignOut key="o" user={r.user} /> : null,
                            ])}
                        />
                    )}
                </Panel>
            </div>

            <Panel title="Sign-in events" detail="Newest first: successes, View as, failed passwords and codes, lockouts." className="mt-4">
                {events.length === 0 ? (
                    <Empty>Nothing in this window.</Empty>
                ) : (
                    <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:security:events">
                        {events.map((e) => (
                            <li key={e.key} className="py-1.5">
                                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <Badge tone={EVENT[e.type][1]}>{EVENT[e.type][0]}</Badge>
                                    {e.new_location && <Badge tone="warning">New location</Badge>}
                                    <span className="text-text-primary">
                                        {e.user ? (
                                            <Link href={route('admin.accounts.show', e.user.id)} className="hover:underline">
                                                {e.user.email}
                                            </Link>
                                        ) : e.email_hash ? (
                                            <span className="text-text-secondary">unknown address #{e.email_hash}</span>
                                        ) : (
                                            '—'
                                        )}
                                    </span>
                                    <span className="text-text-secondary">
                                        {e.method} · {e.ip ?? 'no IP'}
                                        {e.country ? ` · ${e.country}` : e.network ? ` · ${e.network}` : ''}
                                    </span>
                                    <span className="ml-auto text-text-muted">{formatDate(e.at)}</span>
                                </div>
                                {e.agent && <p className="truncate text-[11.5px] text-text-muted">{e.agent}</p>}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AdminLayout>
    );
}

function SignOut({ user }) {
    return (
        <ReasonAction
            label="Sign out"
            route={route('admin.accounts.sign-out', user.id)}
            dataA={`admin:security:sign-out:${user.id}`}
            detail={`Ends every session ${user.email ?? 'this account'} has, on every device.`}
        />
    );
}
