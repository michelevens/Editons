import AdminLayout, { Panel, buttonClass } from '@/Layouts/AdminLayout';
import { Badge } from '@/Components/Admin/Kit';
import { Check, Field, FormDialog, GrowthTabs, inputClass } from '@/Components/Admin/BusinessKit';
import { router } from '@inertiajs/react';

/**
 * Lifecycle emails: a trigger, a delay, a message, on or off. Each goes to
 * an account once, ever, only within a few days of its trigger (so turning
 * one on does not email every old account), and only to people who accept
 * getting-started tips. `growth:lifecycle` sends them hourly.
 */
export default function Lifecycle({ campaigns, window_days: windowDays, can_edit: canEdit }) {
    return (
        <AdminLayout title="Growth" active="growth">
            <GrowthTabs active="lifecycle" />
            <p className="mb-3 text-[12.5px] text-text-secondary">
                Sent hourly, once per account, within {windowDays} days of the trigger, to people whose email preferences allow getting-started tips.
            </p>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2" data-a="admin:lifecycle">
                {campaigns.map((c) => (
                    <Panel
                        key={c.id}
                        title={<span className="flex flex-wrap items-center gap-2">{c.name} <span className="text-[11.5px] font-normal"><Badge tone={c.enabled ? 'success' : 'neutral'}>{c.enabled ? 'On' : 'Off'}</Badge></span></span>}
                        detail={`${c.trigger_label}: ${c.delay_days}`}
                        data-a={`admin:lifecycle:${c.key}`}
                        actions={
                            canEdit ? (
                                <div className="flex flex-wrap gap-2">
                                    <button type="button" data-a={`admin:lifecycle:${c.key}:test`} onClick={() => router.post(route('admin.growth.lifecycle.test', c.id), {}, { preserveScroll: true })} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                                        Send me a test
                                    </button>
                                    <Editor campaign={c} />
                                </div>
                            ) : null
                        }
                    >
                        <p className="text-[13px] font-semibold text-text-primary">{c.subject}</p>
                        <p className="mt-1 whitespace-pre-line text-[12.5px] text-text-body">{c.body}</p>
                        <p className="mt-3 text-[12px] tabular-nums text-text-secondary">
                            {c.sent} sent · {c.skipped} skipped (preferences) · {c.failed} failed · {c.due_now} would get it on the next run{c.enabled ? '' : ' if it were on'}
                        </p>
                    </Panel>
                ))}
            </div>
        </AdminLayout>
    );
}

function Editor({ campaign: c }) {
    const id = `lc-${c.id}`;

    return (
        <FormDialog
            label="Edit"
            title={`Edit ${c.name}`}
            dataA={`admin:lifecycle:${c.key}:edit`}
            url={route('admin.growth.lifecycle.update', c.id)}
            method="patch"
            wide
            detail={c.enabled ? null : 'Switching it on sends it to accounts the trigger has just become true for — not to every old account.'}
            initial={{ name: c.name, delay_days: c.delay_days, subject: c.subject, body: c.body, cta_label: c.cta_label ?? '', enabled: c.enabled }}
            fields={(form) => (
                <>
                    <Check id={`${id}-on`} dataA={`admin:lifecycle:${c.key}:enabled`} label="On" checked={form.data.enabled} onChange={(v) => form.setData('enabled', v)} />
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-[1fr_140px]">
                        <Field label="Name" id={`${id}-name`}>
                            <input id={`${id}-name`} required maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={inputClass} />
                        </Field>
                        <Field label="Days" id={`${id}-days`}>
                            <input id={`${id}-days`} type="number" min="1" max="365" required value={form.data.delay_days} onChange={(e) => form.setData('delay_days', e.target.value)} className={inputClass} />
                        </Field>
                    </div>
                    <Field label="Subject" id={`${id}-subject`}>
                        <input id={`${id}-subject`} required maxLength={150} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Body" id={`${id}-body`} hint="Plain text; a blank line starts a new paragraph. The greeting is added.">
                        <textarea id={`${id}-body`} required rows={6} maxLength={5000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Button label" id={`${id}-cta`} hint="The button opens their books.">
                        <input id={`${id}-cta`} maxLength={60} value={form.data.cta_label} onChange={(e) => form.setData('cta_label', e.target.value)} className={inputClass} />
                    </Field>
                </>
            )}
        />
    );
}
