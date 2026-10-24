import AdminLayout, { Panel, formatDate } from '@/Layouts/AdminLayout';
import { Link } from '@inertiajs/react';
import { Badge, ReasonAction } from '@/Components/Admin/Kit';

/**
 * Feature flags. Decided in order: the module's own configuration (a flag
 * never turns on what cannot work), an override for the account, the
 * platform switch here, the default. Overrides are set on an account page.
 */
export default function Features({ features, can_edit: canEdit }) {
    return (
        <AdminLayout title="Feature flags" active="features">
            {!canEdit && <p className="mb-4 rounded-tile bg-status-info-bg px-3 py-2 text-[13px] text-status-info">Only the platform owner can change these.</p>}

            <div className="space-y-3" data-a="admin:features:list">
                {features.map((f) => (
                    <Panel
                        key={f.key}
                        title={f.label}
                        detail={f.description}
                        data-a={`admin:feature:${f.key}`}
                        actions={
                            <div className="flex items-center gap-2">
                                <Badge tone={f.effective ? 'success' : 'neutral'}>{f.effective ? 'On' : 'Off'}</Badge>
                                {canEdit && f.available && (
                                    <ReasonAction
                                        label="Change"
                                        route={route('admin.features.update')}
                                        dataA={`admin:feature:${f.key}:change`}
                                        detail={`${f.label} for everybody without an override. Default: ${f.default ? 'on' : 'off'}.`}
                                        initial={{ key: f.key, state: f.global === null ? 'default' : f.global ? 'on' : 'off' }}
                                    >
                                        {(form) => (
                                            <fieldset>
                                                <legend className="text-[12.5px] font-medium text-text-primary">For everybody</legend>
                                                <div className="mt-1 flex gap-3 text-[13px]">
                                                    {[['on', 'On'], ['off', 'Off'], ['default', `Default (${f.default ? 'on' : 'off'})`]].map(([value, text]) => (
                                                        <label key={value} className="flex items-center gap-1.5">
                                                            <input type="radio" name={`global-${f.key}`} value={value} checked={form.data.state === value} onChange={() => form.setData('state', value)} />
                                                            {text}
                                                        </label>
                                                    ))}
                                                </div>
                                            </fieldset>
                                        )}
                                    </ReasonAction>
                                )}
                            </div>
                        }
                    >
                        <p className="text-[12.5px] text-text-secondary">
                            <code className="text-text-muted">{f.key}</code> ·{' '}
                            {!f.available
                                ? 'not configured on this deployment, so off whatever is set here'
                                : f.global === null
                                  ? `default (${f.default ? 'on' : 'off'})`
                                  : `switched ${f.global ? 'on' : 'off'}${f.updated_by ? ` by ${f.updated_by}` : ''}${f.updated_at ? `, ${formatDate(f.updated_at)}` : ''}${f.reason ? ` — “${f.reason}”` : ''}`}
                        </p>
                        {f.overrides.length > 0 && (
                            <ul className="mt-2 divide-y divide-border-divider text-[12.5px]">
                                {f.overrides.map((o) => (
                                    <li key={o.user.id} className="flex flex-wrap gap-x-2 py-1">
                                        <Link href={route('admin.accounts.show', o.user.id)} className="text-brand-primary hover:underline">
                                            {o.user.email}
                                        </Link>
                                        <Badge tone={o.enabled ? 'success' : 'neutral'}>forced {o.enabled ? 'on' : 'off'}</Badge>
                                        <span className="text-text-secondary">“{o.reason}”{o.by && ` — ${o.by}`}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                ))}
            </div>
        </AdminLayout>
    );
}
