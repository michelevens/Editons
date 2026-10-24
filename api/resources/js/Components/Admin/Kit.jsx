import Dialog from '@/Components/Dialog';
import { buttonClass, inputClass } from '@/Layouts/AdminLayout';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The admin console's small building blocks, shared by its pages: badges,
 * pagination, tables, and the button that asks for a reason before it posts.
 *
 * A module of its own rather than exports of a page: a page imported by
 * another page is folded into a shared chunk, and the Vite manifest then has
 * no entry for the page itself.
 */

export function Badge({ children, tone = 'neutral' }) {
    const tones = {
        neutral: 'bg-bg-subtle text-text-secondary',
        info: 'bg-status-info-bg text-status-info',
        danger: 'bg-status-danger-bg text-status-danger',
        success: 'bg-status-success-bg text-status-success',
        warning: 'bg-status-warning-bg text-status-warning',
    };

    return <span className={`rounded-badge px-2 py-0.5 font-medium ${tones[tone]}`}>{children}</span>;
}

/** Laravel's paginator links, as a row. */
export function Pager({ links }) {
    if (!links || links.length <= 3) return null;

    return (
        <nav aria-label="Pages" className="mt-4 flex flex-wrap gap-1">
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={`tap rounded-control px-3 py-1.5 text-[12.5px] ${
                            link.active ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-surface text-text-secondary hover:text-text-primary'
                        }`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span
                        key={index}
                        className="rounded-control px-3 py-1.5 text-[12.5px] text-text-muted"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}

export function Empty({ children }) {
    return <p className="text-[12.5px] text-text-secondary">{children}</p>;
}

export function Table({ head, rows }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[360px] text-left text-[12.5px]">
                <thead className="text-text-secondary">
                    <tr>
                        {head.map((cell) => (
                            <th key={cell} className="py-1.5 pr-3 font-medium">
                                {cell}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="tabular-nums">
                    {rows.map((row, index) => (
                        <tr key={index} className="border-t border-border-divider">
                            {row.map((cell, i) => (
                                <td key={i} className="py-1.5 pr-3">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * A button that opens a dialog asking for a reason, then posts.
 * `extra` adds fields; `children` renders more inputs bound to the form.
 */
export function ReasonAction({ label, route: url, dataA, danger = false, detail = null, extra = {}, noReason = false, confirm = null, children = null, initial = {} }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '', ...extra, ...initial });

    const submit = (event) => {
        event.preventDefault();
        // transform() returns nothing in Inertia's useForm: set, then post.
        form.transform((data) => ({ ...data, ...extra }));
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <>
            <button
                type="button"
                data-a={dataA}
                onClick={() => setOpen(true)}
                className={`${buttonClass} border ${
                    danger
                        ? 'border-status-danger/40 text-status-danger hover:bg-status-danger-bg'
                        : 'border-border-default text-text-primary hover:bg-bg-subtle'
                }`}
            >
                {label}
            </button>
            {open && (
                <Dialog label={label} onClose={() => setOpen(false)} className="w-[460px] rounded-panel bg-bg-surface p-5">
                    <form onSubmit={submit} className="text-left">
                        <h2 className="text-[16px] font-bold text-text-primary">{label}</h2>
                        {(detail || confirm) && <p className="mt-1 text-[12.5px] text-text-secondary">{detail ?? confirm}</p>}
                        {children && <div className="mt-3 space-y-3">{children(form)}</div>}
                        {!noReason && (
                            <div className="mt-3">
                                <label htmlFor={`${dataA}-reason`} className="text-[12.5px] font-medium text-text-primary">
                                    Reason (kept in the audit log)
                                </label>
                                <textarea
                                    id={`${dataA}-reason`}
                                    data-a={`${dataA}:reason`}
                                    data-autofocus
                                    required
                                    minLength={4}
                                    rows={3}
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                    className={`${inputClass} mt-1`}
                                />
                            </div>
                        )}
                        <Errors errors={form.errors} />
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setOpen(false)} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                data-a={`${dataA}:confirm`}
                                disabled={form.processing}
                                className={`${buttonClass} text-white ${danger ? 'bg-status-danger-fill' : 'bg-brand-fill hover:bg-brand-primary-hover'}`}
                            >
                                {label}
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}
        </>
    );
}

export function Errors({ errors }) {
    const list = Object.values(errors ?? {});

    if (list.length === 0) return null;

    return (
        <div role="alert" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[12.5px] text-status-danger" data-a="admin:error">
            {list.map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
    );
}
