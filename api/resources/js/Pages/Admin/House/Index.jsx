import { money } from '@/Components/Store/money';
import AdminLayout, { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Badge, Errors, ReasonAction } from '@/Components/Admin/Kit';
import { Check, Field, FormDialog } from '@/Components/Admin/BusinessKit';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * The House catalog (ADR — house catalog and house publisher accounts): the
 * books the platform's own imprints own. Staff read everything; the owner
 * moves books in, exports, prices, publishes and sets ISBN plans — each with
 * a reason, audited. Writing happens in the studio, opened as yourself.
 */

const STATE_TONE = { on_sale: 'success', draft: 'neutral', taken_down: 'danger' };
const STATE_LABEL = { on_sale: 'On sale', draft: 'Draft', taken_down: 'Taken down' };
const EXPORT_TONE = { ready: 'success', generating: 'info', failed: 'danger', expired: 'neutral' };
const PRINT_TONE = { pass: 'success', warn: 'warning', fail: 'danger' };

export default function HouseIndex({ houses, books, totals, options, results, can }) {
    const [selected, setSelected] = useState([]);
    const allIds = useMemo(() => books.map((b) => b.id), [books]);
    const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    return (
        <AdminLayout title="House catalog" active="house">
            <p className="mb-4 max-w-[760px] text-[12.5px] text-text-secondary">
                Books owned by the platform&rsquo;s house publisher accounts. Nobody signs in to these accounts: their books are run from here,
                and opened in the studio as yourself — every save is recorded under your name. Their sales are platform revenue; no royalty
                is paid out for them.
            </p>

            <Houses houses={houses} />

            {totals.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2 text-[12.5px]" data-a="admin:house:totals">
                    {totals.map((t) => (
                        <span key={t.currency} className="rounded-tile bg-bg-surface px-3 py-2">
                            <span className="font-semibold text-text-primary">{money(t.gross_cents, t.currency)}</span>{' '}
                            <span className="text-text-secondary">
                                gross · {t.units} sold{t.refunded_cents > 0 ? ` · ${money(t.refunded_cents, t.currency)} refunded` : ''}
                            </span>
                        </span>
                    ))}
                </div>
            )}

            {can.manage && houses.length > 0 && <Transfer houses={houses} />}

            {results && <Results results={results} />}

            {can.manage && books.length > 0 && (
                <Bulk selected={selected} allIds={allIds} setSelected={setSelected} options={options} />
            )}

            <ul className="mt-4 space-y-3" data-a="admin:house:books">
                {books.length === 0 && (
                    <li className="rounded-panel border border-border-default bg-bg-surface p-6 text-center text-[13px] text-text-secondary">
                        No house books yet.
                    </li>
                )}
                {books.map((book) => (
                    <BookRow key={book.id} book={book} options={options} can={can} selected={selected.includes(book.id)} onToggle={() => toggle(book.id)} />
                ))}
            </ul>
        </AdminLayout>
    );
}

function Houses({ houses }) {
    if (houses.length === 0) {
        return (
            <Panel title="No house account yet" data-a="admin:house:none">
                <p className="text-[12.5px] text-text-secondary">Create one from a shell on the app service, then move books into it:</p>
                <pre className="mt-2 overflow-x-auto rounded-tile bg-bg-subtle p-3 text-[12px]">
                    php artisan house:create ecrivain-prepa --name=&quot;Écrivain Prépa&quot; --reason=&quot;…&quot;
                </pre>
            </Panel>
        );
    }

    return (
        <div className="flex flex-wrap gap-2" data-a="admin:house:accounts">
            {houses.map((h) => (
                <a key={h.id} href={h.account} className="rounded-tile border border-border-default bg-bg-surface px-3 py-2 text-[12.5px] hover:border-brand-primary">
                    <span className="font-semibold text-text-primary">{h.name}</span>{' '}
                    <span className="text-text-muted">
                        {h.slug} · {h.books} book{h.books === 1 ? '' : 's'}
                    </span>
                </a>
            ))}
        </div>
    );
}

/** Move books in: preview (the dry run), then apply with a reason. */
function Transfer({ houses }) {
    const [open, setOpen] = useState(false);
    const [plan, setPlan] = useState(null);
    const [error, setError] = useState(null);
    const form = useForm({ ids: '', to: houses[0]?.slug ?? '', drop_previous: false, reason: '' });

    const ids = () =>
        form.data.ids
            .split(/[\s,]+/)
            .filter(Boolean)
            .flatMap((part) => {
                const range = part.match(/^(\d+)-(\d+)$/);
                if (range) {
                    const out = [];
                    for (let i = Number(range[1]); i <= Number(range[2]) && out.length < 500; i++) out.push(i);
                    return out;
                }
                return /^\d+$/.test(part) ? [Number(part)] : [];
            });

    const preview = async () => {
        setError(null);
        setPlan(null);
        try {
            const { data } = await window.axios.post(route('admin.house.transfer.preview'), { books: ids(), to: form.data.to, drop_previous: form.data.drop_previous });
            setPlan(data);
        } catch (e) {
            setError(e.response?.data?.message ?? 'The preview failed.');
        }
    };

    const apply = (event) => {
        event.preventDefault();
        form.transform((data) => ({ books: ids(), to: data.to, drop_previous: data.drop_previous, reason: data.reason }));
        form.post(route('admin.house.transfer'), { preserveScroll: true, onSuccess: () => { setOpen(false); setPlan(null); form.reset(); } });
    };

    if (!open) {
        return (
            <div className="mt-3">
                <button type="button" data-a="admin:house:transfer" onClick={() => setOpen(true)} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                    Move books into a house account
                </button>
            </div>
        );
    }

    return (
        <Panel title="Move books into a house account" detail="Preview first: nothing moves until you apply. Sales history stays with whoever owned each book when it sold." className="mt-3">
            <form onSubmit={apply} data-a="admin:house:transfer-form">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_220px]">
                    <Field label="Book ids (e.g. 199-217, or 12 14 15)" id="house-ids">
                        <input id="house-ids" data-a="admin:house:transfer:ids" value={form.data.ids} onChange={(e) => { form.setData('ids', e.target.value); setPlan(null); }} className={inputClass} />
                    </Field>
                    <Field label="Into" id="house-to">
                        <select id="house-to" value={form.data.to} onChange={(e) => { form.setData('to', e.target.value); setPlan(null); }} className={inputClass}>
                            {houses.map((h) => (
                                <option key={h.id} value={h.slug}>{h.name}</option>
                            ))}
                        </select>
                    </Field>
                </div>
                <div className="mt-3">
                    <Check id="house-drop" label="Do not keep the previous owner as an editor" checked={form.data.drop_previous} onChange={(v) => { form.setData('drop_previous', v); setPlan(null); }} />
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" data-a="admin:house:transfer:preview" onClick={preview} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                        Preview
                    </button>
                    <button type="button" onClick={() => { setOpen(false); setPlan(null); }} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>
                        Cancel
                    </button>
                </div>
                {error && <p role="alert" className="mt-2 text-[12.5px] text-status-danger">{error}</p>}

                {plan && (
                    <div className="mt-3" data-a="admin:house:transfer:plan">
                        <ul className="space-y-1.5 text-[12.5px]">
                            {plan.books.map((row) => (
                                <li key={row.id} className="rounded-tile bg-bg-subtle px-3 py-2">
                                    <span className="font-semibold text-text-primary">#{row.id} {row.title ?? ''}</span>{' '}
                                    {row.ok ? <Badge tone="success">ready</Badge> : <Badge tone="danger">{row.problems.join(' ')}</Badge>}
                                    {row.moves && (
                                        <span className="ml-2 text-text-secondary">
                                            {row.moves.sections} sections · {row.moves.exports} exports · {row.moves.isbns} ISBNs
                                            {row.moves.product ? ` · product (${row.moves.product.on_sale ? 'on sale' : 'draft'})` : ''}
                                            {row.previous_owner ? ` · previous owner ${row.previous_owner}` : ''}
                                        </span>
                                    )}
                                    {row.warnings?.map((w) => (
                                        <p key={w} className="mt-1 text-status-warning">{w}</p>
                                    ))}
                                </li>
                            ))}
                        </ul>
                        {plan.ok && (
                            <>
                                <Field label="Reason (kept in the audit log, for every book)" id="house-reason">
                                    <textarea id="house-reason" data-a="admin:house:transfer:reason" required minLength={4} rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={inputClass} />
                                </Field>
                                <Errors errors={form.errors} />
                                <button type="submit" data-a="admin:house:transfer:apply" disabled={form.processing} className={`${buttonClass} mt-3 bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                    Move {plan.books.length} book{plan.books.length === 1 ? '' : 's'} to {plan.house.name}
                                </button>
                            </>
                        )}
                    </div>
                )}
            </form>
        </Panel>
    );
}

/** One action across the selected books. */
function Bulk({ selected, allIds, setSelected, options }) {
    const styles = options.house_styles ?? [];
    const form = useForm({ action: 'export', format: 'cover_wrap', service: options.services[0]?.[0] ?? 'kdp', price: '', currency: options.currencies[0], house_style_id: styles[0]?.id ?? '', follow: true, reason: '' });
    const all = selected.length === allIds.length;

    const submit = (event) => {
        event.preventDefault();
        form.transform((d) => ({
            action: d.action,
            books: selected,
            reason: d.reason,
            ...(d.action === 'export' ? { format: d.format, options: d.format === 'cover_wrap' ? { service: d.service } : {} } : {}),
            ...(d.action === 'price' ? { price: d.price, currency: d.currency } : {}),
            ...(d.action === 'house_style' ? { house_style_id: d.house_style_id, follow: d.follow } : {}),
        }));
        // "Prepare for publishing" is its own action: step 1 of Publish for
        // each book, stale files regenerated, one Google Play zip and one
        // KDP checklist for them all.
        form.post(route(form.data.action === 'prepare' ? 'admin.house.prepare' : 'admin.house.bulk'), { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
    };

    return (
        <Panel title="Bulk actions" detail={`${selected.length} of ${allIds.length} selected`} className="mt-4" data-a="admin:house:bulk">
            <form onSubmit={submit} className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <button type="button" data-a="admin:house:select-all" onClick={() => setSelected(all ? [] : allIds)} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                        {all ? 'Select none' : 'Select all'}
                    </button>
                    <label className="sr-only" htmlFor="house-bulk-action">Action</label>
                    <select id="house-bulk-action" data-a="admin:house:bulk:action" value={form.data.action} onChange={(e) => form.setData('action', e.target.value)} className={`${inputClass} w-auto`}>
                        <option value="export">Queue an export</option>
                        <option value="price">Set the price</option>
                        <option value="publish">Put on sale</option>
                        <option value="unpublish">Take off sale</option>
                        {styles.length > 0 && <option value="house_style">Apply a house style (design only)</option>}
                        <option value="prepare">Prepare for publishing (check, regenerate, Google + KDP)</option>
                    </select>
                    {form.data.action === 'export' && (
                        <>
                            <label className="sr-only" htmlFor="house-bulk-format">Format</label>
                            <select id="house-bulk-format" data-a="admin:house:bulk:format" value={form.data.format} onChange={(e) => form.setData('format', e.target.value)} className={`${inputClass} w-auto`}>
                                {options.exports.map(([k, l]) => (
                                    <option key={k} value={k}>{l}</option>
                                ))}
                            </select>
                            {form.data.format === 'cover_wrap' && (
                                <>
                                    <label className="sr-only" htmlFor="house-bulk-service">Print service</label>
                                    <select id="house-bulk-service" value={form.data.service} onChange={(e) => form.setData('service', e.target.value)} className={`${inputClass} w-auto`}>
                                        {options.services.map(([k, l]) => (
                                            <option key={k} value={k}>{l}</option>
                                        ))}
                                    </select>
                                </>
                            )}
                        </>
                    )}
                    {form.data.action === 'house_style' && (
                        <>
                            <label className="sr-only" htmlFor="house-bulk-style">House style</label>
                            <select id="house-bulk-style" data-a="admin:house:bulk:style" value={form.data.house_style_id} onChange={(e) => form.setData('house_style_id', Number(e.target.value))} className={`${inputClass} w-auto`}>
                                {styles.map((style) => (
                                    <option key={style.id} value={style.id}>{style.name}</option>
                                ))}
                            </select>
                            <label className="flex items-center gap-1.5 text-[12.5px] text-text-primary">
                                <input type="checkbox" data-a="admin:house:bulk:follow" checked={form.data.follow} onChange={(e) => form.setData('follow', e.target.checked)} />
                                Books follow the style
                            </label>
                        </>
                    )}
                    {form.data.action === 'price' && (
                        <>
                            <label className="sr-only" htmlFor="house-bulk-price">Price</label>
                            <input id="house-bulk-price" data-a="admin:house:bulk:price" type="number" min="0" step="0.01" required placeholder="Price" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} className={`${inputClass} w-28`} />
                            <label className="sr-only" htmlFor="house-bulk-currency">Currency</label>
                            <select id="house-bulk-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} className={`${inputClass} w-auto`}>
                                {options.currencies.map((c) => (
                                    <option key={c} value={c}>{c.toUpperCase()}</option>
                                ))}
                            </select>
                        </>
                    )}
                </div>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div className="min-w-0 flex-1">
                        <label htmlFor="house-bulk-reason" className="text-[12.5px] font-medium text-text-primary">Reason (kept in the audit log)</label>
                        <input id="house-bulk-reason" data-a="admin:house:bulk:reason" required minLength={4} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={`${inputClass} mt-1`} />
                    </div>
                    <button type="submit" data-a="admin:house:bulk:run" disabled={selected.length === 0 || form.processing} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                        Run on {selected.length} book{selected.length === 1 ? '' : 's'}
                    </button>
                </div>
                <Errors errors={form.errors} />
            </form>
        </Panel>
    );
}

const READY_TONE = { green: 'success', amber: 'warning', red: 'danger' };
const READY_LABEL = { green: 'ready', amber: 'ready, check', red: 'not ready' };

function Results({ results }) {
    const prepare = results.action === 'prepare';

    return (
        <Panel title="Last bulk action" detail={prepare ? 'prepare for publishing' : results.action} className="mt-4" data-a="admin:house:results">
            {prepare && results.links && (
                <div className="mb-3 flex flex-wrap gap-1.5">
                    <a href={results.links.google_play} data-a="admin:house:google-play" className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                        Google Play bulk upload (.zip)
                    </a>
                    <a href={results.links.kdp_checklist} data-a="admin:house:kdp-checklist" target="_blank" rel="noopener noreferrer" className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                        KDP checklist (one page per book)
                    </a>
                </div>
            )}
            <ul className="space-y-1 text-[12.5px]">
                {results.rows.map((r) => (
                    <li key={r.id} data-a={`admin:house:result:${r.id}`}>
                        {prepare ? (
                            <Badge tone={READY_TONE[r.status] ?? 'danger'}>{READY_LABEL[r.status] ?? 'error'}</Badge>
                        ) : (
                            <Badge tone={r.ok ? 'success' : 'danger'}>{r.ok ? 'done' : 'not done'}</Badge>
                        )}{' '}
                        <span className="font-semibold text-text-primary">#{r.id} {r.title}</span> <span className="text-text-secondary">{r.message}</span>
                        {prepare && r.problems?.length > 0 && (
                            <ul className="ml-5 list-disc text-[12px] text-status-danger">
                                {r.problems.map((p) => (
                                    <li key={p}>{p}</li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

const CHANNEL_TONE = { live: 'success', in_review: 'info', ready_to_submit: 'warning', not_submitted: 'neutral', unpublished: 'neutral' };
const CHANNEL_LABEL = { live: 'live', in_review: 'submitted', ready_to_submit: 'to upload', not_submitted: 'chosen', unpublished: 'unpublished' };

function BookRow({ book, options, can, selected, onToggle }) {
    const product = book.product;

    return (
        <li className="rounded-panel border border-border-default bg-bg-surface p-3 sm:p-4" data-a={`admin:house:book:${book.id}`}>
            <div className="flex gap-3">
                {can.manage && (
                    <input type="checkbox" aria-label={`Select ${book.title}`} data-a={`admin:house:select:${book.id}`} checked={selected} onChange={onToggle} className="mt-1 h-4 w-4 shrink-0 rounded border-border-input" />
                )}
                {book.cover && <img src={book.cover} alt="" loading="lazy" className="h-[84px] w-[56px] shrink-0 rounded-[3px] object-cover shadow-sm" />}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span className="min-w-0 break-words text-[14px] font-semibold text-text-primary">{book.title}</span>
                        <span className="text-[12px] text-text-muted">#{book.id}</span>
                    </div>
                    <p className="mt-0.5 text-[12px] text-text-secondary">
                        {book.house.name}
                        {book.series ? ` · ${book.series.name}${book.series.number ? ` ${book.series.number}` : ''}` : ''} · {book.words.toLocaleString()} words ·{' '}
                        {book.status} · updated {formatDate(book.updated_at, false)}
                    </p>
                    <div className="mt-1.5 flex flex-wrap gap-1.5 text-[11.5px]">
                        {product ? (
                            <Badge tone={STATE_TONE[product.state]}>
                                {STATE_LABEL[product.state]} · {money(product.price_cents, product.currency)}
                            </Badge>
                        ) : (
                            <Badge>No price</Badge>
                        )}
                        {book.sales.map((s) => (
                            <Badge key={s.currency} tone="info">
                                {s.units} sold · {money(s.gross_cents, s.currency)}
                            </Badge>
                        ))}
                        {options.exports.map(([key, label]) => {
                            const e = book.exports[key];
                            return (
                                <Badge key={key} tone={e ? EXPORT_TONE[e.status] ?? 'neutral' : 'neutral'}>
                                    {label}: {e ? e.status : 'none'}
                                </Badge>
                            );
                        })}
                        {(book.channels ?? []).map((c) => (
                            <Badge key={c.channel} tone={CHANNEL_TONE[c.status] ?? 'neutral'}>
                                {c.label}: {CHANNEL_LABEL[c.status] ?? c.status}
                                {c.external_id ? ` · ${c.external_id}` : ''}
                            </Badge>
                        ))}
                        {book.print.map((p) => (
                            <Badge key={p.service} tone={PRINT_TONE[p.status] ?? 'neutral'}>
                                {p.label}: {p.status === 'pass' ? 'print ready' : p.status === 'warn' ? 'warnings' : `${p.failing} to fix`}
                            </Badge>
                        ))}
                    </div>

                    <div className="mt-2.5 flex flex-wrap gap-1.5">
                        <a href={book.studio.edit} data-a={`admin:house:open:${book.id}`} className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                            Open in the studio
                        </a>
                        <a href={book.studio.publish} data-a={`admin:house:publish:${book.id}`} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                            Publish (3 steps)
                        </a>
                        <a href={book.studio.publishing} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                            Export &amp; declarations
                        </a>
                        {product?.state === 'on_sale' && (
                            <a href={product.url} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                                Store page
                            </a>
                        )}
                        {can.manage && <BookActions book={book} options={options} />}
                    </div>
                </div>
            </div>
        </li>
    );
}

function BookActions({ book, options }) {
    const product = book.product;

    return (
        <>
            <FormDialog
                label="Export"
                title={`Export “${book.title}”`}
                url={route('admin.house.export', book.id)}
                dataA={`admin:house:export:${book.id}`}
                initial={{ format: 'pdf', service: options.services[0]?.[0] ?? 'kdp' }}
                transform={(d) => ({ format: d.format, reason: d.reason, options: d.format === 'cover_wrap' ? { service: d.service } : {} })}
                fields={(form) => (
                    <>
                        <Field label="Format" id={`exp-${book.id}`}>
                            <select id={`exp-${book.id}`} value={form.data.format} onChange={(e) => form.setData('format', e.target.value)} className={inputClass}>
                                {options.exports.map(([k, l]) => (
                                    <option key={k} value={k}>{l}</option>
                                ))}
                            </select>
                        </Field>
                        {form.data.format === 'cover_wrap' && (
                            <Field label="Print service" id={`svc-${book.id}`}>
                                <select id={`svc-${book.id}`} value={form.data.service} onChange={(e) => form.setData('service', e.target.value)} className={inputClass}>
                                    {options.services.map(([k, l]) => (
                                        <option key={k} value={k}>{l}</option>
                                    ))}
                                </select>
                            </Field>
                        )}
                    </>
                )}
            />
            <FormDialog
                label="Price"
                title={`Price “${book.title}”`}
                url={route('admin.house.price', book.id)}
                dataA={`admin:house:price:${book.id}`}
                initial={{ price: product ? (product.price_cents / 100).toFixed(2) : '', currency: product?.currency ?? options.currencies[0], formats: product?.formats ?? options.formats }}
                fields={(form) => (
                    <>
                        <div className="grid grid-cols-[1fr_120px] gap-2">
                            <Field label="Price" id={`price-${book.id}`}>
                                <input id={`price-${book.id}`} type="number" min="0" step="0.01" required value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} className={inputClass} />
                            </Field>
                            <Field label="Currency" id={`cur-${book.id}`}>
                                <select id={`cur-${book.id}`} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} className={inputClass}>
                                    {options.currencies.map((c) => (
                                        <option key={c} value={c}>{c.toUpperCase()}</option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            {options.formats.map((f) => (
                                <Check
                                    key={f}
                                    id={`fmt-${book.id}-${f}`}
                                    label={f.toUpperCase()}
                                    checked={form.data.formats.includes(f)}
                                    onChange={(on) => form.setData('formats', on ? [...form.data.formats, f] : form.data.formats.filter((x) => x !== f))}
                                />
                            ))}
                        </div>
                    </>
                )}
            />
            {product && product.state === 'draft' && (
                <ReasonAction label="Put on sale" route={route('admin.house.publish', book.id)} dataA={`admin:house:publish:${book.id}`} />
            )}
            {product && product.state === 'on_sale' && (
                <ReasonAction label="Take off sale" route={route('admin.house.unpublish', book.id)} dataA={`admin:house:unpublish:${book.id}`} danger detail="Buyers keep what they bought." />
            )}
            <FormDialog
                label="ISBN plan"
                title={`ISBN plan for “${book.title}”`}
                url={route('admin.house.isbn-plan', book.id)}
                dataA={`admin:house:isbn:${book.id}`}
                detail="Per edition: the imprint's own ISBN, or one the store assigns (tied to that store)."
                initial={{ edition: options.isbn_editions[0], source: book.isbn_plan?.[options.isbn_editions[0]] ?? 'own' }}
                fields={(form) => (
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Edition" id={`ed-${book.id}`}>
                            <select id={`ed-${book.id}`} value={form.data.edition} onChange={(e) => form.setData({ ...form.data, edition: e.target.value, source: book.isbn_plan?.[e.target.value] ?? 'own' })} className={inputClass}>
                                {options.isbn_editions.map((e) => (
                                    <option key={e} value={e}>{e}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="ISBN" id={`src-${book.id}`}>
                            <select id={`src-${book.id}`} value={form.data.source} onChange={(e) => form.setData('source', e.target.value)} className={inputClass}>
                                {options.isbn_sources.map((s) => (
                                    <option key={s} value={s}>{s === 'own' ? 'Our own' : 'Store-assigned'}</option>
                                ))}
                            </select>
                        </Field>
                    </div>
                )}
            />
        </>
    );
}
