import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head } from '@inertiajs/react';

/**
 * The public status page (/status; the same data as /status.json). Each
 * component's state comes from what the platform measures, plus incidents
 * staff post. "Unknown" means not measured from here — never assumed fine.
 */
const STATES = {
    operational: ['Operational', 'bg-status-success-bg text-status-success'],
    degraded: ['Degraded', 'bg-status-warning-bg text-status-warning'],
    partial_outage: ['Partial outage', 'bg-status-danger-bg text-status-danger'],
    major_outage: ['Major outage', 'bg-status-danger-bg text-status-danger'],
    maintenance: ['Maintenance', 'bg-status-info-bg text-status-info'],
    unknown: ['Not measured', 'bg-bg-subtle text-text-secondary'],
    unavailable: ['Not offered', 'bg-bg-subtle text-text-secondary'],
};

const when = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

export default function Status({ snapshot }) {
    const worst = STATES[snapshot.overall] ?? STATES.unknown;

    return (
        <main id="main" className="min-h-dvh bg-bg-app px-4 py-8 font-ui text-text-body sm:py-12" data-a="status:page">
            <Head title="Status" />
            <div className="mx-auto w-full max-w-[720px]">
                <div className="flex items-center gap-3">
                    <ApplicationLogo className="h-8 w-auto" />
                    <span className="text-[13px] text-text-secondary">System status</span>
                </div>

                <section className={`mt-6 rounded-panel px-5 py-4 ${worst[1]}`} data-a="status:headline">
                    <h1 className="font-display text-[22px] font-extrabold tracking-[-0.02em]">{snapshot.headline}</h1>
                    {snapshot.message && <p className="mt-1 text-[14px]">{snapshot.message}</p>}
                </section>

                {snapshot.incidents.length > 0 && (
                    <section className="mt-6" aria-labelledby="status-open">
                        <h2 id="status-open" className="mb-2 text-[15px] font-semibold text-text-primary">
                            Happening now
                        </h2>
                        <ul className="space-y-3">
                            {snapshot.incidents.map((i) => (
                                <Incident key={i.id} incident={i} />
                            ))}
                        </ul>
                    </section>
                )}

                <section className="mt-6" aria-labelledby="status-components">
                    <h2 id="status-components" className="mb-2 text-[15px] font-semibold text-text-primary">
                        Components
                    </h2>
                    <ul className="divide-y divide-border-divider rounded-panel border border-border-default bg-bg-surface" data-a="status:components">
                        {snapshot.components.map((c) => {
                            const [label, tone] = STATES[c.status] ?? STATES.unknown;

                            return (
                                <li key={c.key} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3" data-a={`status:component:${c.key}`}>
                                    <span className="min-w-0 flex-1 text-[14px] font-medium text-text-primary">{c.label}</span>
                                    <span className={`rounded-badge px-2 py-0.5 text-[12px] font-medium ${tone}`}>{label}</span>
                                    {c.note && <p className="w-full text-[12.5px] text-text-secondary">{c.note}</p>}
                                </li>
                            );
                        })}
                    </ul>
                </section>

                {snapshot.recent.length > 0 && (
                    <section className="mt-6" aria-labelledby="status-recent">
                        <h2 id="status-recent" className="mb-2 text-[15px] font-semibold text-text-primary">
                            Past 14 days
                        </h2>
                        <ul className="space-y-3">
                            {snapshot.recent.map((i) => (
                                <Incident key={i.id} incident={i} />
                            ))}
                        </ul>
                    </section>
                )}

                <p className="mt-8 text-[12px] text-text-muted">
                    Checked {when(snapshot.checked_at)}. Machine-readable: <a href="/status.json" className="text-brand-primary hover:underline">/status.json</a>
                </p>
            </div>
        </main>
    );
}

function Incident({ incident }) {
    return (
        <li className="rounded-panel border border-border-default bg-bg-surface px-4 py-3" data-a={`status:incident:${incident.id}`}>
            <div className="flex flex-wrap items-baseline gap-x-2">
                <h3 className="text-[14.5px] font-semibold text-text-primary">{incident.title}</h3>
                <span className="text-[12px] text-text-secondary">{incident.components.join(', ')}</span>
            </div>
            <ol className="mt-2 space-y-2 border-l-2 border-border-default pl-3">
                {incident.updates.map((u, idx) => (
                    <li key={idx} className="text-[13px]">
                        <span className="font-semibold text-text-primary">{u.status_label}</span> <span className="text-text-muted">{when(u.at)}</span>
                        <p className="whitespace-pre-line text-text-body">{u.body}</p>
                    </li>
                ))}
            </ol>
        </li>
    );
}
