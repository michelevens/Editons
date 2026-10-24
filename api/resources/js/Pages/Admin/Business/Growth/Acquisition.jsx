import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Empty, Table } from '@/Components/Admin/Kit';
import { GrowthTabs, Tile, Tiles } from '@/Components/Admin/BusinessKit';
import { Link, router } from '@inertiajs/react';

/**
 * Where accounts come from (first touch: UTM parameters or the referring
 * site, captured on the first visit and saved at sign-up), and how far each
 * source's accounts get: first book, first chapter, first export, first
 * payment — counted from the rows that record each.
 */
export default function Acquisition({ funnel, weeks, recent }) {
    const steps = Object.entries(funnel.steps);
    const pct = (n, of) => (of > 0 ? `${Math.round((n / of) * 100)}%` : '—');

    return (
        <AdminLayout title="Growth" active="growth">
            <GrowthTabs active="acquisition" />

            <div className="mb-3 flex flex-wrap items-center gap-1.5">
                {[4, 12, 26, 52].map((w) => (
                    <button key={w} type="button" aria-pressed={weeks === w} onClick={() => router.get(route('admin.growth.acquisition'), { weeks: w }, { preserveScroll: true })} className={`tap rounded-control px-3 py-1.5 text-[12.5px] ${weeks === w ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-subtle text-text-secondary'}`}>
                        {w} weeks
                    </button>
                ))}
                <span className="text-[12px] text-text-secondary">Accounts created since {formatDate(funnel.since, false)}; staff excluded.</span>
            </div>

            <Tiles dataA="admin:growth:funnel">
                {steps.map(([key, label]) => (
                    <Tile key={key} label={label} value={funnel.total[key]} detail={key === 'signed_up' ? null : `${pct(funnel.total[key], funnel.total.signed_up)} of sign-ups`} />
                ))}
            </Tiles>

            <Panel className="mt-4" title="By source" detail="The first-touch source. “Direct or unknown”: no UTM source and no other site referred them, or cookies were blocked.">
                {funnel.by_source.length === 0 ? (
                    <Empty>No sign-ups in this period.</Empty>
                ) : (
                    <FunnelTable rows={funnel.by_source} first="Source" firstKey="source" steps={steps} pct={pct} />
                )}
            </Panel>

            <Panel className="mt-4" title="By week of sign-up" detail="Each cohort keeps filling in as its accounts reach later steps.">
                <FunnelTable rows={funnel.by_week} first="Week of" firstKey="week" format={(v) => formatDate(v, false)} steps={steps} pct={pct} />
            </Panel>

            <Panel className="mt-4" title="Latest sign-ups" detail="What was captured for each. The marketing site is another domain: its links to sign-up should carry utm_ parameters.">
                {recent.length === 0 ? (
                    <Empty>No accounts yet.</Empty>
                ) : (
                    <Table
                        head={['Account', 'Joined', 'Source', 'Medium', 'Campaign', 'Landing page']}
                        rows={recent.map((r) => [
                            <Link key="a" href={route('admin.accounts.show', r.id)} className="text-brand-primary hover:underline">{r.name}</Link>,
                            formatDate(r.at, false),
                            r.source ?? '—',
                            r.medium ?? '—',
                            r.campaign ?? '—',
                            <code key="l" className="text-[11px]">{r.landing ?? '—'}</code>,
                        ])}
                    />
                )}
            </Panel>
        </AdminLayout>
    );
}

function FunnelTable({ rows, first, firstKey, steps, pct, format = (v) => v }) {
    return (
        <Table
            head={[first, ...steps.map(([, label]) => label)]}
            rows={rows.map((row) => [
                format(row[firstKey]),
                ...steps.map(([key]) => (key === 'signed_up' ? row[key] : <span key={key}>{row[key]} <span className="text-text-muted">{pct(row[key], row.signed_up)}</span></span>)),
            ])}
        />
    );
}
