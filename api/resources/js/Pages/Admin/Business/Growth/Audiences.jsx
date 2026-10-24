import Dialog from '@/Components/Dialog';
import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Empty, Errors } from '@/Components/Admin/Kit';
import { Check, Field, FormDialog, GrowthTabs } from '@/Components/Admin/BusinessKit';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Audiences: saved groups of accounts, by rules over real fields, with a
 * live count while the rules are built. Suspended accounts and staff are
 * always left out; "reachable" is how many of them accept news by email.
 */
export default function Audiences({ audiences, empty_rules: emptyRules, options, can_edit: canEdit }) {
    return (
        <AdminLayout title="Growth" active="growth" actions={canEdit ? <AudienceEditor emptyRules={emptyRules} options={options} /> : null}>
            <GrowthTabs active="audiences" />
            {!canEdit && <p className="mb-3 text-[12.5px] text-text-secondary">Only the platform owner can change audiences.</p>}

            {audiences.length === 0 ? (
                <Panel title="No audiences yet">
                    <Empty>An audience is a saved set of rules — plan, sign-up date, activity, books, words, exports, language, country, email preferences. Broadcasts can then go to it.</Empty>
                </Panel>
            ) : (
                <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2" data-a="admin:audiences">
                    {audiences.map((a) => (
                        <li key={a.id}>
                            <Panel
                                title={a.name}
                                detail={a.description}
                                actions={
                                    canEdit ? (
                                        <div className="flex gap-2">
                                            <AudienceEditor audience={a} emptyRules={emptyRules} options={options} />
                                            <FormDialog label="Delete" danger noReason method="delete" dataA={`admin:audience:${a.id}:delete`} url={route('admin.growth.audiences.destroy', a.id)} detail="Broadcasts already sent keep a copy of the rules they used." />
                                        </div>
                                    ) : null
                                }
                            >
                                <p className="text-[12.5px] text-text-body">{a.summary}</p>
                                <p className="mt-2 text-[12.5px] tabular-nums text-text-secondary">
                                    <span className="font-semibold text-text-primary">{a.count.toLocaleString()}</span> accounts · {a.reachable.toLocaleString()} accept news by email · updated {formatDate(a.updated_at, false)}
                                </p>
                            </Panel>
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}

const WITHIN = [
    ['', 'Any time'],
    [7, '7 days'],
    [30, '30 days'],
    [90, '90 days'],
    [180, '6 months'],
    [365, 'a year'],
];

function AudienceEditor({ audience = null, emptyRules, options }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: audience?.name ?? '', description: audience?.description ?? '', rules: audience?.rules ?? emptyRules });
    const [count, setCount] = useState(null);
    const id = audience ? `aud-${audience.id}` : 'aud-new';
    const rules = form.data.rules;
    const set = (key, value) => form.setData('rules', { ...rules, [key]: value });

    // The live count, once the rules have settled for 400 ms.
    useEffect(() => {
        if (!open) return undefined;

        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.post(route('admin.growth.audiences.preview'), { rules });
                setCount(data);
            } catch {
                setCount({ error: true });
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [open, JSON.stringify(rules)]);

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setOpen(false) };
        audience ? form.patch(route('admin.growth.audiences.update', audience.id), done) : form.post(route('admin.growth.audiences.store'), done);
    };

    const toggle = (key, value) => set(key, rules[key].includes(value) ? rules[key].filter((v) => v !== value) : [...rules[key], value]);
    const number = (key) => (
        <input id={`${id}-${key}`} type="number" min="0" value={rules[key] ?? ''} onChange={(e) => set(key, e.target.value === '' ? null : Number(e.target.value))} className={inputClass} />
    );

    return (
        <>
            <button type="button" data-a={audience ? `admin:audience:${audience.id}:edit` : 'admin:audience:new'} onClick={() => setOpen(true)} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                {audience ? 'Edit' : 'New audience'}
            </button>
            {open && (
                <Dialog label={audience ? `Edit ${audience.name}` : 'New audience'} onClose={() => setOpen(false)} className="max-h-[90dvh] w-[680px] overflow-y-auto rounded-panel bg-bg-surface p-5">
                    <form onSubmit={submit}>
                        <h2 className="text-[16px] font-bold text-text-primary">{audience ? `Edit ${audience.name}` : 'New audience'}</h2>
                        <p className="mt-1 text-[12.5px] text-text-secondary">All rules must hold; ticking several values of one rule matches any of them.</p>

                        <div className="mt-3 grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                            <Field label="Name" id={`${id}-name`}>
                                <input id={`${id}-name`} data-a="admin:audience:name" required maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={inputClass} />
                            </Field>
                            <Field label="Description (optional)" id={`${id}-desc`}>
                                <input id={`${id}-desc`} maxLength={500} value={form.data.description ?? ''} onChange={(e) => form.setData('description', e.target.value)} className={inputClass} />
                            </Field>
                        </div>

                        <fieldset className="mt-4">
                            <legend className="text-[12.5px] font-medium text-text-primary">Plan</legend>
                            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                {options.plans.map((p) => <Check key={p.key} id={`${id}-plan-${p.key}`} dataA={`admin:audience:plan:${p.key}`} label={p.name} checked={rules.plans.includes(p.key)} onChange={() => toggle('plans', p.key)} />)}
                            </div>
                        </fieldset>

                        <div className="mt-1 grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                            <Field label="Signed up within" id={`${id}-jw`}>
                                <select id={`${id}-jw`} value={rules.joined_within_days ?? ''} onChange={(e) => set('joined_within_days', e.target.value === '' ? null : Number(e.target.value))} className={inputClass}>
                                    {WITHIN.map(([v, l]) => <option key={l} value={v}>{l}</option>)}
                                </select>
                            </Field>
                            <Field label="Signed up more than … days ago" id={`${id}-joined_before_days`}>{number('joined_before_days')}</Field>
                            <Field label="Active within (signed in or wrote)" id={`${id}-aw`}>
                                <select id={`${id}-aw`} value={rules.active_within_days ?? ''} onChange={(e) => set('active_within_days', e.target.value === '' ? null : Number(e.target.value))} className={inputClass}>
                                    {WITHIN.map(([v, l]) => <option key={l} value={v}>{l}</option>)}
                                </select>
                            </Field>
                            <Field label="Inactive for at least … days" id={`${id}-inactive_days`}>{number('inactive_days')}</Field>
                            <Field label="Books, at least" id={`${id}-books_min`}>{number('books_min')}</Field>
                            <Field label="Books, at most" id={`${id}-books_max`}>{number('books_max')}</Field>
                            <Field label="Words written, at least" id={`${id}-words_min`}>{number('words_min')}</Field>
                            <Field label="Has exported a book" id={`${id}-exp`}>
                                <select id={`${id}-exp`} value={rules.has_exported ?? ''} onChange={(e) => set('has_exported', e.target.value || null)} className={inputClass}>
                                    <option value="">Either</option>
                                    <option value="yes">Yes</option>
                                    <option value="no">No</option>
                                </select>
                            </Field>
                            <Field label="Accepts news by email" id={`${id}-mkt`}>
                                <select id={`${id}-mkt`} value={rules.marketing ?? ''} onChange={(e) => set('marketing', e.target.value || null)} className={inputClass}>
                                    <option value="">Either</option>
                                    <option value="yes">Yes</option>
                                    <option value="no">No (turned it off)</option>
                                </select>
                            </Field>
                        </div>

                        {options.languages.length > 0 && (
                            <fieldset className="mt-4">
                                <legend className="text-[12.5px] font-medium text-text-primary">Writes in (a book in)</legend>
                                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                    {options.languages.map((l) => <Check key={l} id={`${id}-lang-${l}`} label={l} checked={rules.languages.includes(l)} onChange={() => toggle('languages', l)} />)}
                                </div>
                            </fieldset>
                        )}
                        {options.countries.length > 0 && (
                            <fieldset className="mt-4">
                                <legend className="text-[12.5px] font-medium text-text-primary">Country of publication (a book in)</legend>
                                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                    {options.countries.map((c) => <Check key={c} id={`${id}-country-${c}`} label={c} checked={rules.countries.includes(c)} onChange={() => toggle('countries', c)} />)}
                                </div>
                            </fieldset>
                        )}

                        <p role="status" aria-live="polite" className="mt-4 rounded-tile bg-bg-subtle px-3 py-2 text-[12.5px] text-text-body" data-a="admin:audience:count">
                            {count === null ? 'Counting…' : count.error ? 'Could not count.' : (
                                <>
                                    <span className="font-semibold">{count.count.toLocaleString()}</span> accounts match · {count.reachable.toLocaleString()} accept news by email
                                    {count.sample.length > 0 && <span className="block text-text-secondary">e.g. {count.sample.join(', ')}</span>}
                                </>
                            )}
                        </p>

                        <Errors errors={form.errors} />
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setOpen(false)} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>Cancel</button>
                            <button type="submit" data-a="admin:audience:save" disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>Save audience</button>
                        </div>
                    </form>
                </Dialog>
            )}
        </>
    );
}
