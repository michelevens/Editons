import Dialog from '@/Components/Dialog';
import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Badge, Empty, Errors, Pager, Table } from '@/Components/Admin/Kit';
import { Field, FormDialog, Tile, Tiles } from '@/Components/Admin/BusinessKit';
import { STATE_TONES, perkSummary } from '@/Components/Admin/InvitationKit';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Invitations to join Écrivain: one person, or a pasted list or CSV file
 * previewed row by row before anything is sent. Then the list, with what
 * became of each, and a funnel from invited to first export.
 *
 * Support invites too, without a plan and with credits up to its limit
 * (the server enforces both; the form only stops offering them).
 */
const ROW_STATUS = {
    ok: { label: 'Will be invited', tone: 'success' },
    invalid: { label: 'Invalid', tone: 'danger' },
    duplicate: { label: 'Duplicate', tone: 'warning' },
    member: { label: 'Already a member', tone: 'neutral' },
    pending: { label: 'Already invited', tone: 'info' },
    opted_out: { label: 'Asked not to be invited', tone: 'neutral' },
};

export default function Invitations({ filters, counts, funnel, invitations, batches, inviters, statuses, languages, plans, limits }) {
    const steps = Object.entries(funnel.steps);
    const pct = (n) => (funnel.counts.invited > 0 ? `${Math.round((n / funnel.counts.invited) * 100)}% of invited` : null);
    const apply = (changes) => router.get(route('admin.invitations.index'), { ...filters, ...changes }, { preserveScroll: true, preserveState: true });
    const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== null && v !== ''));

    return (
        <AdminLayout
            title="Invitations"
            active="invitations"
            actions={
                <>
                    <InviteList languages={languages} plans={plans} limits={limits} />
                    <InviteOne languages={languages} plans={plans} limits={limits} />
                </>
            }
        >
            <p className="mb-3 text-[12.5px] text-text-secondary" data-a="admin:invitations:limits">
                You can send {limits.remaining_today.toLocaleString()} more in the next 24 hours. Links stay open {limits.default_days} days unless you choose otherwise, and work while new sign-ups are closed.
            </p>

            <Tiles dataA="admin:invitations:counts">
                <Tile label="Pending" value={counts.pending} />
                <Tile label="Accepted" value={counts.accepted} />
                <Tile label="Expired" value={counts.expired} />
                <Tile label="Revoked or declined" value={counts.revoked + counts.declined} />
            </Tiles>

            <Panel className="mt-4" title="From invited to first export" detail="Everybody the filters below select, whatever their state. Counted from the accounts' own rows; View as is not counted.">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-a="admin:invitations:funnel">
                    {steps.map(([key, label]) => (
                        <div key={key} className="min-w-0 rounded-tile bg-bg-subtle p-3">
                            <p className="text-[12px] text-text-secondary">{label}</p>
                            <p className="font-display text-[20px] font-extrabold tabular-nums text-text-primary">{funnel.counts[key].toLocaleString()}</p>
                            {key !== 'invited' && <p className="text-[11.5px] text-text-secondary">{pct(funnel.counts[key]) ?? '—'}</p>}
                        </div>
                    ))}
                </div>
            </Panel>

            <Panel
                className="mt-4"
                title="Invitations"
                actions={
                    <a href={route('admin.invitations.export', query)} data-a="admin:invitations:export" className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                        Export CSV
                    </a>
                }
            >
                <Filters filters={filters} statuses={statuses} batches={batches} inviters={inviters} apply={apply} />

                {invitations.data.length === 0 ? (
                    <Empty>No invitations match.</Empty>
                ) : (
                    <Table
                        head={['Email', 'State', 'Perks', 'Invited by', 'Sent', 'Expires', 'Account']}
                        rows={invitations.data.map((i) => [
                            <Link key="e" href={route('admin.invitations.show', i.id)} data-a={`admin:invitation:${i.id}`} className="break-all text-brand-primary hover:underline">
                                {i.email}
                                {i.name && <span className="block text-[11.5px] text-text-secondary">{i.name}</span>}
                            </Link>,
                            <Badge key="s" tone={STATE_TONES[i.state]}>{i.state}</Badge>,
                            perkSummary(i.perks, plans) || '—',
                            <span key="b">
                                {i.inviter?.name ?? '—'}
                                {i.batch && <span className="block text-[11.5px] text-text-secondary">{i.batch.label}</span>}
                            </span>,
                            <span key="t">
                                {formatDate(i.sent_at ?? i.created_at, false)}
                                {i.resend_count > 0 && <span className="block text-[11.5px] text-text-secondary">resent {i.resend_count}×</span>}
                            </span>,
                            formatDate(i.expires_at, false),
                            i.accepted_user ? (
                                <Link key="a" href={route('admin.accounts.show', i.accepted_user.id)} className="text-brand-primary hover:underline">
                                    {i.accepted_user.name}
                                </Link>
                            ) : (
                                '—'
                            ),
                        ])}
                    />
                )}
                <Pager links={invitations.links} />
            </Panel>
        </AdminLayout>
    );
}

function Filters({ filters, statuses, batches, inviters, apply }) {
    const [q, setQ] = useState(filters.q ?? '');

    return (
        <form
            className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6"
            onSubmit={(e) => {
                e.preventDefault();
                apply({ q: q || null });
            }}
        >
            <label className="sr-only" htmlFor="inv-q">Search</label>
            <input id="inv-q" data-a="admin:invitations:q" placeholder="Email or name" value={q} onChange={(e) => setQ(e.target.value)} className={`${inputClass} lg:col-span-2`} />
            <label className="sr-only" htmlFor="inv-status">State</label>
            <select id="inv-status" data-a="admin:invitations:status" value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || null })} className={inputClass}>
                <option value="">Every state</option>
                {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
            <label className="sr-only" htmlFor="inv-batch">Batch</label>
            <select id="inv-batch" value={filters.batch ?? ''} onChange={(e) => apply({ batch: e.target.value || null })} className={inputClass}>
                <option value="">Every batch</option>
                {batches.map((b) => <option key={b.id} value={b.id}>{b.label}</option>)}
            </select>
            <label className="sr-only" htmlFor="inv-inviter">Invited by</label>
            <select id="inv-inviter" value={filters.inviter ?? ''} onChange={(e) => apply({ inviter: e.target.value || null })} className={inputClass}>
                <option value="">Anyone</option>
                {inviters.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <div className="grid grid-cols-2 gap-2">
                <label className="sr-only" htmlFor="inv-from">From</label>
                <input id="inv-from" type="date" value={filters.from ?? ''} onChange={(e) => apply({ from: e.target.value || null })} className={inputClass} />
                <label className="sr-only" htmlFor="inv-to">To</label>
                <input id="inv-to" type="date" value={filters.to ?? ''} onChange={(e) => apply({ to: e.target.value || null })} className={inputClass} />
            </div>
        </form>
    );
}

/** Language, note, perks, expiry and campaign: the same for one person or a list. */
function SharedFields({ form, languages, plans, limits, idPrefix, languageLabel = 'Language of the email' }) {
    return (
        <>
            <Field label={languageLabel} id={`${idPrefix}-lang`}>
                <select id={`${idPrefix}-lang`} data-a={`${idPrefix}:language`} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)} className={inputClass}>
                    {Object.entries(languages).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                </select>
            </Field>
            <Field label="Personal note (optional)" id={`${idPrefix}-note`} hint="Shown as a quote in the email, as plain text. Signed by “the Écrivain team”.">
                <textarea id={`${idPrefix}-note`} data-a={`${idPrefix}:note`} rows={3} maxLength={1000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={inputClass} />
            </Field>
            <fieldset className="mt-3">
                <legend className="text-[12.5px] font-medium text-text-primary">Perks when they accept (optional)</legend>
                <div className="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    {limits.can_plan ? (
                        <>
                            <div className="sm:col-span-2">
                                <label htmlFor={`${idPrefix}-plan`} className="text-[12px] text-text-secondary">Complimentary plan</label>
                                <select id={`${idPrefix}-plan`} data-a={`${idPrefix}:plan`} value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)} className={inputClass}>
                                    <option value="">None</option>
                                    {plans.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor={`${idPrefix}-months`} className="text-[12px] text-text-secondary">Months</label>
                                <input id={`${idPrefix}-months`} data-a={`${idPrefix}:months`} type="number" min="1" max={limits.max_plan_months} disabled={!form.data.plan} required={!!form.data.plan} value={form.data.plan_months} onChange={(e) => form.setData('plan_months', e.target.value)} className={inputClass} />
                            </div>
                        </>
                    ) : (
                        <p className="text-[11.5px] text-text-secondary sm:col-span-2">Only the owner can include a plan.</p>
                    )}
                    <div>
                        <label htmlFor={`${idPrefix}-credits`} className="text-[12px] text-text-secondary">AI credits (up to {limits.max_credits.toLocaleString()})</label>
                        <input id={`${idPrefix}-credits`} data-a={`${idPrefix}:credits`} type="number" min="0" max={limits.max_credits} value={form.data.credits} onChange={(e) => form.setData('credits', e.target.value)} className={inputClass} />
                    </div>
                </div>
            </fieldset>
            <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                <Field label="Link open for (days)" id={`${idPrefix}-days`}>
                    <input id={`${idPrefix}-days`} type="number" min="1" max={limits.max_days} value={form.data.expires_days} onChange={(e) => form.setData('expires_days', e.target.value)} className={inputClass} />
                </Field>
                <Field label="Campaign (optional)" id={`${idPrefix}-campaign`} hint="Letters, digits, . _ -. Growth shows it as the campaign.">
                    <input id={`${idPrefix}-campaign`} pattern="[A-Za-z0-9._\-]*" maxLength={150} value={form.data.campaign} onChange={(e) => form.setData('campaign', e.target.value)} className={inputClass} />
                </Field>
            </div>
        </>
    );
}

const sharedInitial = (limits) => ({ language: 'en', note: '', plan: '', plan_months: 3, credits: '', expires_days: limits.default_days, campaign: '' });

/** Strip empty perks so the server sees "none", not zero. */
const clean = (data) => ({
    ...data,
    plan: data.plan || null,
    plan_months: data.plan ? Number(data.plan_months) : null,
    credits: data.credits === '' ? null : Number(data.credits),
    campaign: data.campaign || null,
    note: data.note || null,
});

function InviteOne({ languages, plans, limits }) {
    return (
        <FormDialog
            label="Invite someone"
            title="Invite someone to Écrivain"
            dataA="admin:invite"
            url={route('admin.invitations.store')}
            noReason
            wide
            buttonClassName={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}
            initial={{ email: '', name: '', ...sharedInitial(limits) }}
            transform={clean}
            fields={(form) => (
                <>
                    <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                        <Field label="Email" id="inv-email">
                            <input id="inv-email" data-a="admin:invite:email" type="email" required maxLength={255} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={inputClass} />
                        </Field>
                        <Field label="Name (optional)" id="inv-name">
                            <input id="inv-name" data-a="admin:invite:name" maxLength={255} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={inputClass} />
                        </Field>
                    </div>
                    <EmailCheck email={form.data.email} />
                    <SharedFields form={form} languages={languages} plans={plans} limits={limits} idPrefix="admin:invite" />
                </>
            )}
        />
    );
}

/** Says, as the address is typed, whether it can be invited — with a link when it cannot. */
function EmailCheck({ email }) {
    const [row, setRow] = useState(null);

    useEffect(() => {
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) {
            setRow(null);
            return undefined;
        }

        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.post(route('admin.invitations.preview'), { text: email.trim() });
                setRow(data.rows[0] ?? null);
            } catch {
                setRow(null);
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [email]);

    if (!row || row.status === 'ok') return null;

    return (
        <p role="status" data-a="admin:invite:check" className="mt-2 rounded-tile bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning">
            {row.message}{' '}
            {row.user_id && <Link href={route('admin.accounts.show', row.user_id)} className="font-semibold underline">Open the account</Link>}
            {row.invitation_id && <Link href={route('admin.invitations.show', row.invitation_id)} className="font-semibold underline">Open the invitation</Link>}
        </p>
    );
}

/**
 * A list: paste it or choose a CSV file, preview it (every row checked on
 * the server), then send the rows that can go. The server checks them all
 * again when they are sent.
 */
function InviteList({ languages, plans, limits }) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState(null);
    const form = useForm({ ...sharedInitial(limits), rows: [], source: 'paste' });

    const close = () => {
        setOpen(false);
        setPreview(null);
        setText('');
        setFile(null);
        setFailure(null);
        form.reset();
        form.clearErrors();
    };

    const check = async () => {
        setBusy(true);
        setFailure(null);
        try {
            const body = new FormData();
            if (file) body.append('file', file);
            else body.append('text', text);
            const { data } = await window.axios.post(route('admin.invitations.preview'), body);
            setPreview(data);
        } catch (error) {
            setFailure(Object.values(error?.response?.data?.errors ?? {}).flat()[0] ?? 'The list could not be read.');
        } finally {
            setBusy(false);
        }
    };

    const sendable = preview ? preview.rows.filter((r) => r.status === 'ok') : [];

    const send = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...clean(data),
            source: file ? 'csv' : 'paste',
            rows: sendable.map((r) => ({ email: r.email, name: r.name, language: r.language })),
        }));
        form.post(route('admin.invitations.bulk'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <>
            <button type="button" data-a="admin:invite-list" onClick={() => setOpen(true)} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                Invite a list
            </button>
            {open && (
                <Dialog label="Invite a list" onClose={close} className="max-h-[90dvh] w-[720px] overflow-y-auto rounded-panel bg-bg-surface p-5">
                    <form onSubmit={send} className="text-left">
                        <h2 className="text-[16px] font-bold text-text-primary">Invite a list</h2>
                        <p className="mt-1 text-[12.5px] text-text-secondary">
                            One person per line: <code>email,name,language</code> (name and language optional; language en, fr or ht). Up to {limits.batch_max} at a time. Duplicates, members and people already invited are left out.
                        </p>

                        {!preview ? (
                            <>
                                <Field label="Paste the list" id="list-text">
                                    <textarea id="list-text" data-a="admin:invite-list:text" rows={7} disabled={!!file} value={text} onChange={(e) => setText(e.target.value)} placeholder={'ada@example.com,Ada Lovelace,en\nmarie@example.com,Marie Jean,fr'} className={`${inputClass} font-mono text-[12.5px]`} />
                                </Field>
                                <Field label="Or choose a CSV file" id="list-file" hint="UTF-8 or a spreadsheet's CSV export. A header row starting with “email” is skipped.">
                                    <input id="list-file" data-a="admin:invite-list:file" type="file" accept=".csv,text/csv,text/plain" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-[12.5px]" />
                                </Field>
                                {failure && <p role="alert" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[12.5px] text-status-danger">{failure}</p>}
                                <div className="mt-4 flex justify-end gap-2">
                                    <button type="button" onClick={close} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>Cancel</button>
                                    <button type="button" data-a="admin:invite-list:preview" disabled={busy || (!file && text.trim() === '')} onClick={check} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                        {busy ? 'Checking…' : 'Preview'}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <p className="mt-3 text-[13px] text-text-primary" data-a="admin:invite-list:summary">
                                    <strong>{preview.summary.ok}</strong> of {preview.summary.total} can be invited.
                                    {preview.too_many && <span className="text-status-danger"> That is more than {preview.max}: split the list.</span>}
                                </p>
                                <div className="mt-2 max-h-[260px] overflow-y-auto rounded-tile border border-border-divider px-2" data-a="admin:invite-list:rows">
                                    <Table
                                        head={['Line', 'Email', 'Name', 'Lang', 'Status']}
                                        rows={preview.rows.map((r) => [
                                            r.line,
                                            <span key="e" className="break-all">{r.email || '—'}</span>,
                                            r.name ?? '—',
                                            r.language ?? '—',
                                            <span key="s">
                                                <Badge tone={ROW_STATUS[r.status]?.tone ?? 'neutral'}>{ROW_STATUS[r.status]?.label ?? r.status}</Badge>
                                                {r.status !== 'ok' && r.message && <span className="block text-[11.5px] text-text-secondary">{r.message}</span>}
                                            </span>,
                                        ])}
                                    />
                                </div>
                                <SharedFields form={form} languages={languages} plans={plans} limits={limits} idPrefix="admin:invite-list" languageLabel="Language for rows that give none" />
                                <Errors errors={form.errors} />
                                <div className="mt-4 flex flex-wrap justify-end gap-2">
                                    <button type="button" onClick={() => setPreview(null)} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>Back to the list</button>
                                    <button type="submit" data-a="admin:invite-list:send" disabled={form.processing || sendable.length === 0 || preview.too_many} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                        Send {sendable.length} invitation{sendable.length === 1 ? '' : 's'}
                                    </button>
                                </div>
                            </>
                        )}
                    </form>
                </Dialog>
            )}
        </>
    );
}
