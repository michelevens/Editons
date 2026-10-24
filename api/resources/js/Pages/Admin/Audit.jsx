import AdminLayout, { buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Pager } from '@/Components/Admin/Kit';

/** The admin audit log, filterable, with a CSV of whatever is filtered. */
export default function Audit({ logs, filters, actions }) {
    const [values, setValues] = useState({
        action: filters.action ?? '',
        actor: filters.actor ?? '',
        subject_type: filters.subject_type ?? '',
        subject_id: filters.subject_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const clean = () => Object.fromEntries(Object.entries(values).filter(([, v]) => v !== ''));

    const apply = (event) => {
        event.preventDefault();
        router.get(route('admin.audit.index'), clean(), { preserveState: true });
    };

    const field = (key, label, props = {}) => (
        <div className="min-w-0">
            <label htmlFor={`audit-${key}`} className="text-[11.5px] font-medium text-text-secondary">
                {label}
            </label>
            <input
                id={`audit-${key}`}
                value={values[key]}
                onChange={(event) => setValues({ ...values, [key]: event.target.value })}
                className={`${inputClass} mt-0.5`}
                {...props}
            />
        </div>
    );

    return (
        <AdminLayout
            title="Audit log"
            active="audit"
            actions={
                <a href={route('admin.audit.export', clean())} data-a="admin:audit:export" className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                    Export CSV
                </a>
            }
        >
            <form onSubmit={apply} className="grid grid-cols-1 gap-2 rounded-panel border border-border-default bg-bg-surface p-3 sm:grid-cols-3 lg:grid-cols-7">
                <div className="min-w-0">
                    <label htmlFor="audit-action" className="text-[11.5px] font-medium text-text-secondary">
                        Action
                    </label>
                    <select
                        id="audit-action"
                        value={values.action}
                        onChange={(event) => setValues({ ...values, action: event.target.value })}
                        className={`${inputClass} mt-0.5`}
                    >
                        <option value="">Any</option>
                        {actions.map((action) => (
                            <option key={action} value={action}>
                                {action}
                            </option>
                        ))}
                    </select>
                </div>
                {field('actor', 'Staff (email or id)')}
                {field('subject_type', 'Subject type', { placeholder: 'User' })}
                {field('subject_id', 'Subject id', { inputMode: 'numeric' })}
                {field('from', 'From', { type: 'date' })}
                {field('to', 'To', { type: 'date' })}
                <div className="flex items-end">
                    <button type="submit" className={`${buttonClass} w-full bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                        Filter
                    </button>
                </div>
            </form>

            <ul className="mt-4 space-y-2" data-a="admin:audit:list">
                {logs.data.length === 0 && (
                    <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">Nothing logged.</li>
                )}
                {logs.data.map((log) => (
                    <li key={log.id} className="rounded-panel border border-border-default bg-bg-surface px-4 py-3 text-[12.5px]">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                            <code className="font-semibold text-text-primary">{log.action}</code>
                            <span className="text-text-secondary">
                                by {log.actor ? `${log.actor.name} (${log.actor.email})` : 'the platform'}
                                {log.impersonator && <span className="text-status-danger"> while {log.impersonator} was viewing</span>}
                            </span>
                            {log.subject_type && (
                                <span className="text-text-secondary">
                                    on {log.subject_type} #{log.subject_id}
                                </span>
                            )}
                            <span className="ml-auto text-text-muted">{formatDate(log.at)} · {log.ip ?? 'console'}</span>
                        </div>
                        {log.reason && <p className="mt-1 text-text-body">“{log.reason}”</p>}
                        {(log.before || log.after) && (
                            <div className="mt-1.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <Diff label="Before" value={log.before} />
                                <Diff label="After" value={log.after} />
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            <Pager links={logs.links} />
        </AdminLayout>
    );
}

function Diff({ label, value }) {
    return (
        <div className="min-w-0 rounded-tile bg-bg-subtle px-2 py-1.5">
            <p className="text-[11px] font-medium text-text-muted">{label}</p>
            <pre className="overflow-x-auto whitespace-pre-wrap break-all text-[11.5px] text-text-body">{value ? JSON.stringify(value, null, 1) : '—'}</pre>
        </div>
    );
}
