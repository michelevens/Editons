import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Errors, ReasonAction } from '@/Components/Admin/Kit';

/**
 * Lists the owner can edit. Removing an entry never changes a book that
 * uses it; it only stops being offered. Book types and parts are shown
 * read-only, with why.
 */
export default function MasterData({ editable, read_only: readOnly, can }) {
    return (
        <AdminLayout title="Master data" active="master-data">
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                {editable.map((list) => (
                    <EditableList key={list.key} list={list} owner={can.owner} />
                ))}
                {readOnly.map((list) => (
                    <Panel key={list.key} title={list.label} detail="Read-only" data-a={`admin:master:${list.key}`}>
                        <p className="text-[12.5px] text-text-secondary">{list.why}</p>
                        <ul className="mt-2 flex flex-wrap gap-1.5 text-[11.5px]">
                            {list.items.map((item) => (
                                <li key={item}>
                                    <Badge>{item}</Badge>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                ))}
            </div>
        </AdminLayout>
    );
}

function EditableList({ list, owner }) {
    const [editing, setEditing] = useState(false);
    const isStyles = list.key === 'citation_styles';
    const form = useForm({ items: list.items, reason: '' });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('admin.master-data.update', list.key), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <Panel
            title={list.label}
            detail={`${list.where}${list.customised ? ` · edited ${formatDate(list.updated_at)}` : ' · original entries'}`}
            data-a={`admin:master:${list.key}`}
            actions={
                owner &&
                !editing && (
                    <span className="flex gap-2">
                        <button type="button" data-a={`admin:master:${list.key}:edit`} onClick={() => setEditing(true)} className="text-[12px] font-semibold text-brand-primary hover:underline">
                            Edit
                        </button>
                        {list.customised && (
                            <ReasonAction label="Reset" route={route('admin.master-data.reset', list.key)} extra={{ _method: 'delete' }} dataA={`admin:master:${list.key}:reset`} detail="Back to the entries the app shipped with." />
                        )}
                    </span>
                )
            }
        >
            {!editing ? (
                <ul className="flex flex-wrap gap-1.5 text-[11.5px]" data-a={`admin:master:${list.key}:items`}>
                    {list.items.map((item) => (
                        <li key={item}>
                            <Badge tone="info">{isStyles ? list.choices.find((c) => c.value === item)?.label ?? item : item}</Badge>
                        </li>
                    ))}
                </ul>
            ) : (
                <form onSubmit={submit} className="space-y-3 text-[12.5px]">
                    {isStyles ? (
                        <fieldset>
                            <legend className="font-medium text-text-primary">Styles shown to authors</legend>
                            <div className="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">
                                {list.choices.map((c) => (
                                    <label key={c.value} className="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            checked={form.data.items.includes(c.value)}
                                            onChange={() =>
                                                form.setData('items', form.data.items.includes(c.value) ? form.data.items.filter((v) => v !== c.value) : [...form.data.items, c.value])
                                            }
                                        />
                                        {c.label}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    ) : (
                        <label className="block">
                            <span className="font-medium text-text-primary">One per line</span>
                            <textarea
                                data-a={`admin:master:${list.key}:text`}
                                rows={Math.min(12, form.data.items.length + 2)}
                                value={form.data.items.join('\n')}
                                onChange={(e) => form.setData('items', e.target.value.split('\n'))}
                                className={`${inputClass} mt-1 font-mono`}
                            />
                        </label>
                    )}
                    <label className="block">
                        <span className="font-medium text-text-primary">Reason (kept in the audit log)</span>
                        <input data-a={`admin:master:${list.key}:reason`} required minLength={4} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={`${inputClass} mt-1`} />
                    </label>
                    <Errors errors={form.errors} />
                    <div className="flex gap-2">
                        <button type="submit" data-a={`admin:master:${list.key}:save`} disabled={form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                            Save
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                form.reset();
                                setEditing(false);
                            }}
                            className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </Panel>
    );
}
