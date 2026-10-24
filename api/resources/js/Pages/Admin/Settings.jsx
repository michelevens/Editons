import AdminLayout, { Panel, buttonClass, inputClass } from '@/Layouts/AdminLayout';
import { useForm } from '@inertiajs/react';

/**
 * Platform settings. Every switch here changes behaviour; nothing is
 * decorative. Owner only to change; support can read.
 */
export default function Settings({ settings, can_edit: canEdit, has_two_factor: hasTwoFactor }) {
    const form = useForm({
        settings: {
            ...settings,
            announcement_ends_at: settings.announcement_ends_at ? settings.announcement_ends_at.slice(0, 16) : '',
        },
        reason: '',
    });

    const set = (key, value) => form.setData('settings', { ...form.data.settings, [key]: value });
    const s = form.data.settings;

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            settings: {
                ...data.settings,
                // The browser's local time, sent with its offset.
                announcement_ends_at: data.settings.announcement_ends_at ? new Date(data.settings.announcement_ends_at).toISOString() : null,
            },
        }));
        form.patch(route('admin.settings.update'), { preserveScroll: true });
    };

    const error = (key) => form.errors[`settings.${key}`] ?? form.errors[key];

    return (
        <AdminLayout title="Settings" active="settings">
            {!canEdit && (
                <p className="mb-4 rounded-tile bg-status-info-bg px-3 py-2 text-[13px] text-status-info">Only the platform owner can change these.</p>
            )}
            <form onSubmit={submit} className="space-y-4">
                <fieldset disabled={!canEdit} className="space-y-4">
                    <Panel title="Maintenance" detail="Writers get a maintenance page (503). Staff carry on; sign-in, the status endpoint and Stripe's webhook stay open.">
                        <Switch id="maintenance_mode" label="Maintenance mode" checked={s.maintenance_mode} onChange={(v) => set('maintenance_mode', v)} />
                        <TextArea id="maintenance_message" label="Message on the maintenance page" value={s.maintenance_message} onChange={(v) => set('maintenance_message', v)} error={error('maintenance_message')} />
                    </Panel>

                    <Panel title="Announcement" detail="A banner across the top of every page.">
                        <Switch id="announcement_enabled" label="Show the announcement" checked={s.announcement_enabled} onChange={(v) => set('announcement_enabled', v)} />
                        <TextArea id="announcement_message" label="Message" value={s.announcement_message} onChange={(v) => set('announcement_message', v)} error={error('announcement_message')} />
                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Select
                                id="announcement_tone"
                                label="Tone"
                                value={s.announcement_tone}
                                onChange={(v) => set('announcement_tone', v)}
                                options={[['info', 'Information'], ['success', 'Good news'], ['warning', 'Warning'], ['danger', 'Problem']]}
                            />
                            <Select
                                id="announcement_audience"
                                label="Who sees it"
                                value={s.announcement_audience}
                                onChange={(v) => set('announcement_audience', v)}
                                options={[['all', 'Everybody, signed in or not'], ['writers', 'Signed-in writers'], ['admins', 'Staff only']]}
                            />
                            <div>
                                <label htmlFor="announcement_ends_at" className="text-[12.5px] font-medium text-text-primary">
                                    Ends (optional)
                                </label>
                                <input
                                    id="announcement_ends_at"
                                    type="datetime-local"
                                    value={s.announcement_ends_at ?? ''}
                                    onChange={(event) => set('announcement_ends_at', event.target.value)}
                                    className={`${inputClass} mt-1`}
                                />
                            </div>
                        </div>
                    </Panel>

                    <Panel title="Accounts and security">
                        <Switch id="new_signups_open" label="New accounts can register" checked={s.new_signups_open} onChange={(v) => set('new_signups_open', v)} />
                        <Switch
                            id="require_2fa_for_admins"
                            label="Require two-factor for staff"
                            detail={hasTwoFactor ? 'Staff without it reach only its set-up page.' : 'Turn on two-factor for your own account before you can require it.'}
                            checked={s.require_2fa_for_admins}
                            onChange={(v) => set('require_2fa_for_admins', v)}
                            error={error('require_2fa_for_admins')}
                        />
                    </Panel>

                    <Panel title="Save">
                        <label htmlFor="settings-reason" className="text-[12.5px] font-medium text-text-primary">
                            Reason (optional, kept in the audit log)
                        </label>
                        <input id="settings-reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className={`${inputClass} mt-1`} />
                        <button type="submit" data-a="admin:settings:save" disabled={form.processing} className={`${buttonClass} mt-3 bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                            Save settings
                        </button>
                    </Panel>
                </fieldset>
            </form>
        </AdminLayout>
    );
}

function Switch({ id, label, detail = null, checked, onChange, error = null }) {
    return (
        <div className="py-1.5">
            <label htmlFor={id} className="flex items-center gap-3 text-[13.5px] text-text-primary">
                <input
                    id={id}
                    type="checkbox"
                    role="switch"
                    data-a={`admin:settings:${id}`}
                    checked={Boolean(checked)}
                    onChange={(event) => onChange(event.target.checked)}
                    className="h-4 w-4"
                />
                {label}
            </label>
            {detail && <p className="ml-7 text-[12px] text-text-secondary">{detail}</p>}
            {error && <p role="alert" className="ml-7 text-[12px] text-status-danger">{error}</p>}
        </div>
    );
}

function TextArea({ id, label, value, onChange, error }) {
    return (
        <div className="mt-2">
            <label htmlFor={id} className="text-[12.5px] font-medium text-text-primary">
                {label}
            </label>
            <textarea id={id} data-a={`admin:settings:${id}`} rows={2} maxLength={500} value={value ?? ''} onChange={(event) => onChange(event.target.value)} className={`${inputClass} mt-1`} />
            {error && <p role="alert" className="text-[12px] text-status-danger">{error}</p>}
        </div>
    );
}

function Select({ id, label, value, onChange, options }) {
    return (
        <div>
            <label htmlFor={id} className="text-[12.5px] font-medium text-text-primary">
                {label}
            </label>
            <select id={id} value={value} onChange={(event) => onChange(event.target.value)} className={`${inputClass} mt-1`}>
                {options.map(([key, text]) => (
                    <option key={key} value={key}>
                        {text}
                    </option>
                ))}
            </select>
        </div>
    );
}
