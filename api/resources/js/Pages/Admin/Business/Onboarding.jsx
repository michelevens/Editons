import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Empty, Table } from '@/Components/Admin/Kit';
import { Tile, Tiles } from '@/Components/Admin/BusinessKit';
import { Link, router } from '@inertiajs/react';

/**
 * How far accounts get through setting up. "Stuck at" is the first step an
 * account has not done, in the order most people take them; choose a step
 * to see who is stuck there. Staff accounts are left out.
 */
export default function Onboarding({ overview, days, step, stuck }) {
    const go = (changes) => router.get(route('admin.onboarding.index'), { days, step, ...changes }, { preserveScroll: true });
    const current = overview.steps.find((s) => s.key === step);

    return (
        <AdminLayout title="Onboarding" active="onboarding">
            <div className="mb-3 flex flex-wrap gap-1.5">
                {[null, 7, 30, 90, 365].map((d) => (
                    <button key={d ?? 'all'} type="button" aria-pressed={days === d} onClick={() => go({ days: d })} className={`tap rounded-control px-3 py-1.5 text-[12.5px] ${days === d ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-subtle text-text-secondary'}`}>
                        {d ? `Joined in the last ${d} days` : 'All accounts'}
                    </button>
                ))}
            </div>

            <Tiles>
                <Tile label="Accounts" value={overview.accounts} />
                <Tile label="Every step done" value={overview.complete} detail={overview.accounts ? `${Math.round((overview.complete / overview.accounts) * 100)}%` : null} />
            </Tiles>

            <Panel className="mt-4" title="Steps" detail="Done: accounts that have done it. Stuck: it is the first step they have not done." data-a="admin:onboarding:steps">
                <ol className="space-y-3">
                    {overview.steps.map((s, i) => (
                        <li key={s.key}>
                            <div className="flex flex-wrap items-baseline justify-between gap-2 text-[13px]">
                                <span className="font-medium text-text-primary">{i + 1}. {s.label}</span>
                                <span className="tabular-nums text-text-secondary">
                                    {s.done.toLocaleString()} done ({s.done_pct}%) ·{' '}
                                    <button type="button" data-a={`admin:onboarding:stuck:${s.key}`} onClick={() => go({ step: s.key })} className="text-brand-primary underline-offset-2 hover:underline">
                                        {s.stuck.toLocaleString()} stuck here
                                    </button>
                                </span>
                            </div>
                            <div className="mt-1 h-2 overflow-hidden rounded-full bg-bg-subtle">
                                <div className="h-full bg-brand-fill" style={{ width: `${s.done_pct}%` }} />
                            </div>
                        </li>
                    ))}
                </ol>
            </Panel>

            {current && (
                <Panel className="mt-4" title={`Stuck at: ${current.label}`} detail="The 50 newest. Open an account to see its checklist.">
                    {stuck.length === 0 ? (
                        <Empty>Nobody is stuck at this step.</Empty>
                    ) : (
                        <Table head={['Account', 'Email', 'Joined']} rows={stuck.map((a) => [<Link key="a" href={route('admin.accounts.show', a.id)} className="text-brand-primary hover:underline">{a.name}</Link>, a.email, formatDate(a.created_at, false)])} />
                    )}
                </Panel>
            )}
        </AdminLayout>
    );
}
