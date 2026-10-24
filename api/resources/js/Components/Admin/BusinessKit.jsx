import Dialog from '@/Components/Dialog';
import { money } from '@/Components/Store/money';
import { buttonClass, inputClass } from '@/Layouts/AdminLayout';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Errors } from '@/Components/Admin/Kit';

/**
 * Building blocks of the console's business pages (billing, plans, sales,
 * growth, onboarding): figure tiles, tab links, a bar chart, and a dialog
 * form that can PATCH as well as POST. Kept apart from Kit.jsx so the two
 * sets of pages do not edit one file.
 */

export { money };

/** A figure with its label. `none` replaces the number with a sentence. */
export function Tile({ label, value, detail = null, none = null, alert = null, dataA }) {
    return (
        <div className="min-w-0 rounded-panel border border-border-default bg-bg-surface p-4" data-a={dataA}>
            <p className="text-[12px] font-medium text-text-secondary">{label}</p>
            {none ? (
                <p className="mt-2 text-[14px] font-semibold text-text-primary">{none}</p>
            ) : (
                <p className="mt-1 truncate font-display text-[24px] font-extrabold tabular-nums tracking-[-0.02em] text-text-primary">
                    {typeof value === 'number' ? value.toLocaleString() : value}
                </p>
            )}
            {detail && <p className="mt-0.5 text-[11.5px] text-text-secondary">{detail}</p>}
            {alert && (
                <p className="mt-1.5 inline-flex rounded-badge bg-status-warning-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-warning">{alert}</p>
            )}
        </div>
    );
}

export function Tiles({ children, dataA }) {
    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4" data-a={dataA}>
            {children}
        </div>
    );
}

/** Tabs that are links (each its own URL), scrolling sideways on a phone. */
export function TabLinks({ tabs, active, label }) {
    return (
        <nav aria-label={label} className="-mx-4 mb-4 overflow-x-auto border-b border-border-default px-4 sm:mx-0 sm:px-0">
            <ul className="flex min-w-max gap-1">
                {tabs.map((tab) => (
                    <li key={tab.key}>
                        <Link
                            href={tab.href}
                            preserveScroll
                            data-a={`admin:tab:${tab.key}`}
                            aria-current={tab.key === active ? 'page' : undefined}
                            className={`tap -mb-px inline-flex h-10 items-center border-b-2 px-3 text-[13px] font-medium ${
                                tab.key === active
                                    ? 'border-brand-primary text-text-primary'
                                    : 'border-transparent text-text-secondary hover:text-text-primary'
                            }`}
                        >
                            {tab.label}
                            {tab.count !== undefined && tab.count !== null && (
                                <span className="ml-1.5 rounded-badge bg-bg-subtle px-1.5 text-[11px] tabular-nums">{tab.count}</span>
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

export const GROWTH_TABS = [
    { key: 'acquisition', label: 'Acquisition', route: 'admin.growth.acquisition' },
    { key: 'audiences', label: 'Audiences', route: 'admin.growth.audiences.index' },
    { key: 'broadcasts', label: 'Broadcasts', route: 'admin.growth.broadcasts.index' },
    { key: 'discounts', label: 'Discounts', route: 'admin.growth.discounts.index' },
    { key: 'lifecycle', label: 'Lifecycle', route: 'admin.growth.lifecycle.index' },
];

export function GrowthTabs({ active }) {
    return <TabLinks label="Growth" active={active} tabs={GROWTH_TABS.map((t) => ({ ...t, href: route(t.route) }))} />;
}

/**
 * One series of bars from a shared baseline. Hover or focus a bar for its
 * label and value; the numbers are also in a table for screen readers.
 */
export function BarChart({ series, ariaLabel, format = (v) => v.toLocaleString(), height = 150 }) {
    const [hover, setHover] = useState(null);
    const max = Math.max(1, ...series.map((p) => p.value));
    const width = 600;
    const step = width / Math.max(1, series.length);
    const bar = Math.max(2, step - 2);

    return (
        <div>
            <div className="relative">
                <svg viewBox={`0 0 ${width} ${height + 18}`} className="h-auto w-full" role="img" aria-label={ariaLabel}>
                    <line x1="0" x2={width} y1={height} y2={height} className="stroke-border-default" strokeWidth="1" />
                    {series.map((point, index) => {
                        const h = point.value === 0 ? 0 : Math.max(3, (point.value / max) * (height - 8));
                        const x = index * step + 1;
                        const r = Math.min(4, bar / 2);

                        return (
                            <g
                                key={point.key ?? index}
                                tabIndex={0}
                                onMouseEnter={() => setHover(index)}
                                onMouseLeave={() => setHover(null)}
                                onFocus={() => setHover(index)}
                                onBlur={() => setHover(null)}
                                className="outline-none"
                            >
                                <rect x={index * step} y="0" width={step} height={height} fill="transparent" />
                                {h > 0 && (
                                    <path
                                        d={`M${x},${height} V${height - h + r} Q${x},${height - h} ${x + r},${height - h} H${x + bar - r} Q${x + bar},${height - h} ${x + bar},${height - h + r} V${height} Z`}
                                        className={hover === index ? 'fill-brand-primary' : 'fill-brand-fill'}
                                    />
                                )}
                            </g>
                        );
                    })}
                    <text x="0" y={height + 14} className="fill-text-muted text-[11px]">{series[0]?.label}</text>
                    <text x={width} y={height + 14} textAnchor="end" className="fill-text-muted text-[11px]">{series[series.length - 1]?.label}</text>
                </svg>
                {hover !== null && series[hover] && (
                    <div
                        className="pointer-events-none absolute top-0 -translate-x-1/2 whitespace-nowrap rounded-control bg-bg-inverse px-2 py-1 text-[11.5px] text-text-inverse shadow-floating"
                        style={{ left: `${Math.min(85, Math.max(15, ((hover + 0.5) / series.length) * 100))}%` }}
                    >
                        {series[hover].label}: {format(series[hover].value)}
                    </div>
                )}
            </div>
            <table className="sr-only">
                <caption>{ariaLabel}</caption>
                <tbody>
                    {series.map((p, i) => (
                        <tr key={p.key ?? i}>
                            <td>{p.label}</td>
                            <td>{format(p.value)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** "12 Oct" from an ISO day. */
export function shortDay(day) {
    if (!day) return '';

    return new Date(`${String(day).slice(0, 10)}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/**
 * A button that opens a dialog with a form, then sends it.
 *
 * `fields(form)` renders the inputs; a required reason box is added unless
 * `noReason`. `method` is post, patch or delete.
 */
export function FormDialog({ label, title = null, url, method = 'post', dataA, detail = null, initial = {}, fields = null, noReason = false, danger = false, wide = false, buttonClassName = null, onDone = null, transform = null }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '', ...initial });

    const submit = (event) => {
        event.preventDefault();
        // transform() returns nothing in Inertia's useForm: set, then send.
        form.transform(transform ?? ((data) => data));
        form[method](url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
                onDone?.();
            },
        });
    };

    return (
        <>
            <button
                type="button"
                data-a={dataA}
                onClick={() => setOpen(true)}
                className={
                    buttonClassName ??
                    `${buttonClass} border ${danger ? 'border-status-danger/40 text-status-danger hover:bg-status-danger-bg' : 'border-border-default text-text-primary hover:bg-bg-subtle'}`
                }
            >
                {label}
            </button>
            {open && (
                <Dialog label={title ?? label} onClose={() => setOpen(false)} className={`${wide ? 'w-[640px]' : 'w-[460px]'} max-h-[90dvh] overflow-y-auto rounded-panel bg-bg-surface p-5`}>
                    <form onSubmit={submit} className="text-left">
                        <h2 className="text-[16px] font-bold text-text-primary">{title ?? label}</h2>
                        {detail && <p className="mt-1 text-[12.5px] text-text-secondary">{detail}</p>}
                        {fields && <div className="mt-3 space-y-3">{fields(form)}</div>}
                        {!noReason && (
                            <Field label="Reason (kept in the audit log)" id={`${dataA}-reason`}>
                                <textarea
                                    id={`${dataA}-reason`}
                                    data-a={`${dataA}:reason`}
                                    required
                                    minLength={4}
                                    rows={2}
                                    value={form.data.reason}
                                    onChange={(e) => form.setData('reason', e.target.value)}
                                    className={inputClass}
                                />
                            </Field>
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

/** A labelled control. */
export function Field({ label, id, hint = null, children }) {
    return (
        <div className="mt-3 first:mt-0">
            <label htmlFor={id} className="text-[12.5px] font-medium text-text-primary">
                {label}
            </label>
            <div className="mt-1">{children}</div>
            {hint && <p className="mt-1 text-[11.5px] text-text-secondary">{hint}</p>}
        </div>
    );
}

export function Check({ id, label, checked, onChange, dataA }) {
    return (
        <label htmlFor={id} className="flex items-center gap-2 text-[13px] text-text-primary">
            <input id={id} data-a={dataA} type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} className="h-4 w-4 rounded border-border-input" />
            {label}
        </label>
    );
}

/** The notice pages show when nothing can move money. */
export function PaymentsNotice({ payments }) {
    if (payments?.available) return null;

    return (
        <p role="status" className="mb-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning" data-a="admin:payments-off">
            {payments?.refusal ?? 'Stripe is not configured on this deployment.'}
        </p>
    );
}

/** Account link for a row. */
export function AccountLink({ account }) {
    if (!account) return <span className="text-text-muted">Deleted account</span>;

    return (
        <Link href={route('admin.accounts.show', account.id)} className="text-brand-primary hover:underline">
            {account.email ?? account.name}
        </Link>
    );
}

export { inputClass, buttonClass };
