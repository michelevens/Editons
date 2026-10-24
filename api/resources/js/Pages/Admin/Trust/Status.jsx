import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Empty, Errors } from '@/Components/Admin/Kit';
import { STATE_LABELS, STATE_TONES } from '@/Components/Admin/TrustKit';

/**
 * The status page from the inside: what each component's signals say now,
 * and the incidents staff post. Everything here is public on /status the
 * moment it is saved.
 */
export default function Status({ snapshot, incidents, components, severities, statuses, public_url: publicUrl }) {
    return (
        <AdminLayout
            title="Status"
            active="status"
            actions={
                <a href={publicUrl} target="_blank" rel="noreferrer" data-a="admin:status:public" className="tap rounded-control px-3 py-2 text-[12.5px] font-semibold text-brand-primary hover:bg-bg-subtle">
                    Public page
                </a>
            }
        >
            <p className="mb-3 text-[13px] font-semibold text-text-primary" data-a="admin:status:headline">
                {snapshot.headline}
            </p>
            <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5" data-a="admin:status:components">
                {snapshot.components.map((c) => (
                    <li key={c.key} className="rounded-panel border border-border-default bg-bg-surface p-3" data-a={`admin:status:component:${c.key}`}>
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[13px] font-semibold text-text-primary">{c.label}</span>
                            <Badge tone={STATE_TONES[c.status]}>{STATE_LABELS[c.status] ?? c.status}</Badge>
                        </div>
                        {c.note && <p className="mt-1 text-[12px] text-text-secondary">{c.note}</p>}
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-[12px] text-text-muted">Signals checked {formatDate(snapshot.checked_at)} (cached for a minute).</p>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                <NewIncident components={components} severities={severities} statuses={statuses} />

                <Panel title="Incidents" detail="Open first. A resolved incident stays on the public page for 14 days.">
                    {incidents.length === 0 ? (
                        <Empty>No incidents yet.</Empty>
                    ) : (
                        <ul className="space-y-3" data-a="admin:status:incidents">
                            {incidents.map((i) => (
                                <Incident key={i.id} incident={i} statuses={statuses} />
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}

function NewIncident({ components, severities, statuses }) {
    const form = useForm({ title: '', severity: 'minor', status: 'investigating', components: [], body: '', show_in_banner: false });

    const toggle = (key) =>
        form.setData('components', form.data.components.includes(key) ? form.data.components.filter((k) => k !== key) : [...form.data.components, key]);

    return (
        <Panel title="Post an incident" detail="Public at once on /status.">
            <form
                data-a="admin:status:new"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('admin.status.incidents.store'), { preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="space-y-3 text-[12.5px]"
            >
                <label className="block">
                    <span className="font-medium text-text-primary">Title</span>
                    <input data-a="admin:status:title" required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={`${inputClass} mt-1`} placeholder="PDF exports are slow" />
                </label>
                <div className="flex flex-wrap gap-3">
                    <label>
                        <span className="font-medium text-text-primary">Severity</span>
                        <select data-a="admin:status:severity" value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)} className={`${inputClass} mt-1 w-auto`}>
                            {severities.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="font-medium text-text-primary">Status</span>
                        <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={`${inputClass} mt-1 w-auto`}>
                            {Object.entries(statuses).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <fieldset>
                    <legend className="font-medium text-text-primary">Affected</legend>
                    <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                        {Object.entries(components).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-1.5">
                                <input type="checkbox" data-a={`admin:status:component-pick:${k}`} checked={form.data.components.includes(k)} onChange={() => toggle(k)} />
                                {l}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <label className="block">
                    <span className="font-medium text-text-primary">First update (public)</span>
                    <textarea data-a="admin:status:body" required rows={3} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className={`${inputClass} mt-1`} />
                </label>
                <label className="flex items-center gap-1.5">
                    <input type="checkbox" data-a="admin:status:banner" checked={form.data.show_in_banner} onChange={(e) => form.setData('show_in_banner', e.target.checked)} />
                    Also show in the app&rsquo;s banner while open (unless an announcement is showing)
                </label>
                <Errors errors={form.errors} />
                <button type="submit" data-a="admin:status:post" disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                    Post incident
                </button>
            </form>
        </Panel>
    );
}

function Incident({ incident, statuses }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ status: incident.status === 'resolved' ? 'resolved' : 'monitoring', body: '', show_in_banner: incident.show_in_banner });

    return (
        <li className="rounded-tile border border-border-default px-3 py-2 text-[12.5px]" data-a={`admin:status:incident:${incident.id}`}>
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="font-semibold text-text-primary">{incident.title}</span>
                <Badge tone={incident.resolved_at ? 'success' : incident.severity === 'minor' ? 'warning' : incident.severity === 'maintenance' ? 'info' : 'danger'}>
                    {incident.resolved_at ? 'resolved' : incident.severity}
                </Badge>
                {incident.show_in_banner && !incident.resolved_at && <Badge tone="info">in banner</Badge>}
                <span className="ml-auto text-text-muted">{formatDate(incident.started_at)}</span>
            </div>
            <p className="text-text-secondary">{incident.components.join(', ')}</p>
            <ol className="mt-1 space-y-1 border-l border-border-default pl-3">
                {incident.updates.map((u, idx) => (
                    <li key={idx}>
                        <span className="font-medium text-text-primary">{u.status_label}</span> <span className="text-text-muted">{formatDate(u.at)}</span>
                        <p className="whitespace-pre-line text-text-body">{u.body}</p>
                    </li>
                ))}
            </ol>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} data-a={`admin:status:incident:${incident.id}:update`} className="mt-1 text-brand-primary hover:underline">
                {open ? 'Close' : incident.resolved_at ? 'Reopen or add a note' : 'Post an update'}
            </button>
            {open && (
                <form
                    className="mt-2 space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.status.incidents.update', incident.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                >
                    <select data-a={`admin:status:incident:${incident.id}:status`} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={`${inputClass} w-auto`}>
                        {Object.entries(statuses).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <label className="sr-only" htmlFor={`incident-${incident.id}-body`}>
                        Update
                    </label>
                    <textarea id={`incident-${incident.id}-body`} data-a={`admin:status:incident:${incident.id}:body`} required rows={2} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className={inputClass} />
                    <label className="flex items-center gap-1.5">
                        <input type="checkbox" checked={form.data.show_in_banner} onChange={(e) => form.setData('show_in_banner', e.target.checked)} />
                        Show in the app&rsquo;s banner
                    </label>
                    <Errors errors={form.errors} />
                    <button type="submit" data-a={`admin:status:incident:${incident.id}:send`} disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                        Post update
                    </button>
                </form>
            )}
        </li>
    );
}
