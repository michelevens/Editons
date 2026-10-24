import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Badge, Empty, Errors, Table } from '@/Components/Admin/Kit';
import { Check, Field, FormDialog, GrowthTabs, Tile, Tiles } from '@/Components/Admin/BusinessKit';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Compose a broadcast: subject and plain paragraphs in Écrivain's email
 * template, an audience, and optionally an inbox item. Save, look at the
 * preview, send yourself a test, then send now or schedule it. Once it
 * starts it cannot be changed; this page then shows what happened.
 */
const TONE = { draft: 'neutral', scheduled: 'info', sending: 'warning', sent: 'success', cancelled: 'neutral' };

export default function BroadcastEdit({ broadcast, recipients, audiences, everyone_reachable: everyone, per_minute: perMinute, can_edit: canEdit }) {
    const editable = canEdit && (!broadcast || ['draft', 'scheduled'].includes(broadcast.status));
    const form = useForm({
        subject: broadcast?.subject ?? '',
        preheader: broadcast?.preheader ?? '',
        body: broadcast?.body ?? '',
        cta_label: broadcast?.cta_label ?? '',
        cta_url: broadcast?.cta_url ?? '',
        audience_id: broadcast?.audience_id ?? '',
        inbox: broadcast?.inbox ?? false,
    });
    const [preview, setPreview] = useState(null);
    const reach = form.data.audience_id ? audiences.find((a) => String(a.id) === String(form.data.audience_id))?.reachable ?? 0 : everyone;

    const save = (event) => {
        event.preventDefault();
        broadcast ? form.patch(route('admin.growth.broadcasts.update', broadcast.id), { preserveScroll: true }) : form.post(route('admin.growth.broadcasts.store'));
    };

    const showPreview = async () => {
        try {
            const { data } = await window.axios.post(route('admin.growth.broadcasts.preview'), form.data);
            setPreview(data.html);
        } catch {
            setPreview('<p style="font-family:sans-serif">The preview could not be drawn. Check the fields above.</p>');
        }
    };

    return (
        <AdminLayout title="Growth" active="growth">
            <GrowthTabs active="broadcasts" />
            <Link href={route('admin.growth.broadcasts.index')} className="text-[12.5px] text-brand-primary hover:underline">← Broadcasts</Link>

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <h2 className="font-display text-[19px] font-bold text-text-primary">{broadcast ? broadcast.subject : 'New broadcast'}</h2>
                {broadcast && <span className="text-[11.5px]"><Badge tone={TONE[broadcast.status]}>{broadcast.status}</Badge></span>}
                {broadcast?.scheduled_at && broadcast.status === 'scheduled' && <span className="text-[12px] text-text-secondary">for {formatDate(broadcast.scheduled_at)}</span>}
            </div>

            {broadcast && !['draft', 'scheduled'].includes(broadcast.status) && <Results broadcast={broadcast} recipients={recipients} />}

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1fr_1fr]">
                <Panel title="Message" detail="Plain text: a blank line starts a new paragraph. The layout, footer and unsubscribe link are added for you.">
                    <form onSubmit={save} data-a="admin:broadcast:form">
                        <fieldset disabled={!editable} className="contents">
                            <Field label="Subject" id="b-subject">
                                <input id="b-subject" data-a="admin:broadcast:subject" required maxLength={150} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} className={inputClass} />
                            </Field>
                            <Field label="Preview text (optional)" id="b-pre" hint="Shown after the subject in most inboxes.">
                                <input id="b-pre" maxLength={200} value={form.data.preheader} onChange={(e) => form.setData('preheader', e.target.value)} className={inputClass} />
                            </Field>
                            <Field label="Body" id="b-body">
                                <textarea id="b-body" data-a="admin:broadcast:body" required rows={9} maxLength={20000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className={inputClass} />
                            </Field>
                            <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                                <Field label="Button label (optional)" id="b-cta">
                                    <input id="b-cta" maxLength={60} value={form.data.cta_label} onChange={(e) => form.setData('cta_label', e.target.value)} className={inputClass} />
                                </Field>
                                <Field label="Button link (https)" id="b-url">
                                    <input id="b-url" type="url" maxLength={500} placeholder="https://" value={form.data.cta_url} onChange={(e) => form.setData('cta_url', e.target.value)} className={inputClass} />
                                </Field>
                            </div>
                            <Field label="Audience" id="b-aud" hint={`${reach.toLocaleString()} would get the email now: people who accept news, not suspended, not staff.`}>
                                <select id="b-aud" data-a="admin:broadcast:audience" value={form.data.audience_id} onChange={(e) => form.setData('audience_id', e.target.value)} className={inputClass}>
                                    <option value="">Every account</option>
                                    {audiences.map((a) => <option key={a.id} value={a.id}>{a.name} ({a.reachable.toLocaleString()})</option>)}
                                </select>
                            </Field>
                            <div className="mt-3">
                                <Check id="b-inbox" label="Also put it in their Écrivain inbox (everyone in the audience, whatever their email settings)" checked={form.data.inbox} onChange={(v) => form.setData('inbox', v)} />
                            </div>
                        </fieldset>
                        <Errors errors={form.errors} />
                        <div className="mt-4 flex flex-wrap gap-2">
                            {editable && (
                                <button type="submit" data-a="admin:broadcast:save" disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                    {broadcast ? 'Save changes' : 'Save draft'}
                                </button>
                            )}
                            <button type="button" data-a="admin:broadcast:preview" onClick={showPreview} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                                Preview
                            </button>
                            {broadcast && canEdit && (
                                <button type="button" data-a="admin:broadcast:test" onClick={() => router.post(route('admin.growth.broadcasts.test', broadcast.id), {}, { preserveScroll: true })} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                                    Send me a test
                                </button>
                            )}
                        </div>
                    </form>

                    {broadcast && editable && (
                        <div className="mt-4 flex flex-wrap gap-2 border-t border-border-divider pt-4">
                            <FormDialog
                                label="Send…"
                                title="Send this broadcast"
                                dataA="admin:broadcast:schedule"
                                url={route('admin.growth.broadcasts.schedule', broadcast.id)}
                                detail={`It goes to the saved version (save your changes first), in batches of ${perMinute} a minute, only to people who accept news. It cannot be changed once it starts.`}
                                initial={{ when: 'now', scheduled_at: '' }}
                                transform={(d) => ({ ...d, scheduled_at: d.when === 'later' && d.scheduled_at ? new Date(d.scheduled_at).toISOString() : null })}
                                fields={(f) => (
                                    <>
                                        <fieldset>
                                            <legend className="text-[12.5px] font-medium text-text-primary">When</legend>
                                            <label className="mt-1 flex items-center gap-2 text-[13px]"><input type="radio" name="when" checked={f.data.when === 'now'} onChange={() => f.setData('when', 'now')} /> Now (within a minute)</label>
                                            <label className="mt-1 flex items-center gap-2 text-[13px]"><input type="radio" name="when" checked={f.data.when === 'later'} onChange={() => f.setData('when', 'later')} /> Later</label>
                                        </fieldset>
                                        {f.data.when === 'later' && (
                                            <Field label="Date and time (your time zone)" id="b-at">
                                                <input id="b-at" type="datetime-local" required value={f.data.scheduled_at} onChange={(e) => f.setData('scheduled_at', e.target.value)} className={inputClass} />
                                            </Field>
                                        )}
                                    </>
                                )}
                            />
                            {broadcast.status === 'scheduled' && (
                                <FormDialog label="Cancel broadcast" danger dataA="admin:broadcast:cancel" url={route('admin.growth.broadcasts.cancel', broadcast.id)} detail="It will not be sent. Nothing has gone out yet." />
                            )}
                        </div>
                    )}
                </Panel>

                <Panel title="Preview" detail="Exactly as the email template draws it, addressed to you.">
                    {preview === null ? (
                        <Empty>Press Preview to see the email.</Empty>
                    ) : (
                        <iframe title="Email preview" data-a="admin:broadcast:preview-frame" sandbox="" srcDoc={preview} className="h-[560px] w-full rounded-tile border border-border-default bg-white" />
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}

function Results({ broadcast, recipients }) {
    return (
        <div className="mt-4">
            <Tiles dataA="admin:broadcast:stats">
                <Tile label="Recipients" value={broadcast.recipients_total} detail={broadcast.audience} />
                <Tile label="Sent" value={broadcast.sent} detail="handed to the mail provider" />
                <Tile label="Failed" value={broadcast.failed} />
                <Tile label="Skipped" value={broadcast.skipped} detail="email preferences off" />
            </Tiles>
            <p className="mt-2 text-[12px] text-text-secondary">Opens and clicks are not tracked. {broadcast.inbox ? 'Inbox items went to everyone in the audience.' : ''}</p>
            <Panel className="mt-3" title="Recipients" detail="Failures first. Addresses are masked.">
                {recipients.length === 0 ? (
                    <Empty>Nobody yet.</Empty>
                ) : (
                    <Table head={['Address', 'Status', 'Inbox', 'Sent', 'Error']} rows={recipients.map((r) => [r.email, <Badge key="s" tone={r.status === 'sent' ? 'success' : r.status === 'failed' ? 'danger' : 'neutral'}>{r.status}{r.skip_reason ? ` (${r.skip_reason})` : ''}</Badge>, r.inboxed ? 'yes' : '—', formatDate(r.sent_at), r.error ?? ''])} />
                )}
            </Panel>
        </div>
    );
}
