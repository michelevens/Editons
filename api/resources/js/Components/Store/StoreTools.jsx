import { money } from '@/Components/Store/money';
import { useI18n } from '@/i18n';
import { useCallback, useEffect, useState } from 'react';

/**
 * An author's own storefront tools on the Sales page (ADR — per-format
 * pricing, sales, author codes, bundles and pre-orders): discount codes
 * for their books, and bundles of their books. The server scopes both to
 * the signed-in seller; another author's code or bundle answers 404.
 */
export default function StoreTools() {
    return (
        <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <DiscountCodes />
            <Bundles />
        </div>
    );
}

const input = 'w-full rounded-input border border-border-input bg-bg-surface px-2.5 py-1.5 text-[13px] text-text-primary';

function useResource(url) {
    const [data, setData] = useState(null);
    const load = useCallback(async () => {
        try {
            setData((await window.axios.get(url)).data);
        } catch {
            setData(false);
        }
    }, [url]);

    useEffect(() => {
        load();
    }, [load]);

    return [data, setData];
}

function firstError(errors, key) {
    const e = errors[key];

    return e ? <p className="mt-1 text-[11.5px] text-status-danger">{Array.isArray(e) ? e[0] : e}</p> : null;
}

/** A list of the seller's books (and bundles) to tick. */
function Picker({ items, selected, onChange, name }) {
    return (
        <div className="max-h-[150px] space-y-1 overflow-y-auto rounded-input border border-border-default p-2">
            {items.map((item) => (
                <label key={item.id} className="flex items-center gap-2 text-[12.5px] text-text-body">
                    <input
                        type="checkbox"
                        data-a={`${name}:${item.id}`}
                        checked={selected.includes(item.id)}
                        onChange={() => onChange(selected.includes(item.id) ? selected.filter((id) => id !== item.id) : [...selected, item.id])}
                    />
                    <span className="min-w-0 truncate">{item.title}</span>
                </label>
            ))}
        </div>
    );
}

function DiscountCodes() {
    const { t } = useI18n();
    const [data, setData] = useResource('/sales/discounts');
    const blank = { code: '', kind: 'percent', percent_off: 10, amount_off: '', currency: 'usd', product_ids: [], bundle_ids: [], max_redemptions: '', expires_on: '' };
    const [form, setForm] = useState(blank);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    if (!data) return null;

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

    const create = async (event) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});

        try {
            setData((await window.axios.post('/sales/discounts', { ...form, max_redemptions: form.max_redemptions || null, expires_on: form.expires_on || null })).data);
            setForm(blank);
        } catch (error) {
            setErrors(error.response?.data?.errors ?? { code: [t('tools.failed')] });
        } finally {
            setBusy(false);
        }
    };

    const toggle = async (code) => {
        setData(
            (
                await window.axios.put(`/sales/discounts/${code.id}`, {
                    active: !code.active,
                    description: code.description,
                    product_ids: code.product_ids,
                    bundle_ids: code.bundle_ids,
                    max_redemptions: code.max_redemptions,
                    expires_on: code.expires_on,
                })
            ).data,
        );
    };

    return (
        <section data-a="tools:codes" className="rounded-panel border border-border-default bg-bg-surface p-4">
            <h2 className="text-[15px] font-semibold text-text-primary">{t('tools.codes_title')}</h2>
            <p className="mt-0.5 text-[12px] leading-snug text-text-secondary">{t('tools.codes_help')}</p>

            {data.codes.length > 0 && (
                <ul className="mt-3 divide-y divide-border-default">
                    {data.codes.map((code) => (
                        <li key={code.id} data-a="tools:code" className="flex flex-wrap items-center justify-between gap-2 py-2 text-[12.5px]">
                            <span className="min-w-0">
                                <span className="font-mono font-semibold text-text-primary">{code.code}</span>
                                <span className="ml-2 text-text-secondary">
                                    {code.kind === 'percent'
                                        ? t('tools.percent_off', { percent: code.percent_off })
                                        : t('tools.amount_off', { amount: money(code.amount_off_cents, code.currency) })}
                                    {' · '}
                                    {code.max_redemptions
                                        ? t('tools.used_of', { used: code.used, max: code.max_redemptions })
                                        : t('tools.used', { used: code.used })}
                                    {code.expires_on && ` · ${t('tools.until', { date: code.expires_on })}`}
                                    {code.product_ids || code.bundle_ids ? ` · ${t('tools.some_books')}` : ` · ${t('tools.all_books')}`}
                                </span>
                            </span>
                            <button
                                type="button"
                                data-a={`tools:code-toggle:${code.code}`}
                                onClick={() => toggle(code)}
                                className={`rounded-badge px-2 py-0.5 text-[11px] font-semibold ${code.active && !code.expired ? 'bg-status-success-bg text-status-success' : 'bg-bg-subtle text-text-secondary'}`}
                            >
                                {code.expired ? t('tools.expired') : code.active ? t('tools.active') : t('tools.paused')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <form onSubmit={create} className="mt-3 space-y-2 border-t border-border-default pt-3">
                <div className="grid grid-cols-2 gap-2">
                    <label className="text-[11px] text-text-secondary">
                        {t('tools.code')}
                        <input
                            data-a="tools:code-name"
                            value={form.code}
                            maxLength={40}
                            onChange={(e) => set('code', e.target.value.toUpperCase())}
                            className={`${input} font-mono uppercase`}
                        />
                        {firstError(errors, 'code')}
                    </label>
                    <label className="text-[11px] text-text-secondary">
                        {t('tools.kind')}
                        <select value={form.kind} onChange={(e) => set('kind', e.target.value)} className={input} data-a="tools:code-kind">
                            <option value="percent">{t('tools.kind_percent')}</option>
                            <option value="amount">{t('tools.kind_amount')}</option>
                        </select>
                    </label>
                    {form.kind === 'percent' ? (
                        <label className="text-[11px] text-text-secondary">
                            {t('tools.percent')}
                            <input type="number" min="1" max="100" value={form.percent_off} onChange={(e) => set('percent_off', e.target.value)} className={input} data-a="tools:code-percent" />
                            {firstError(errors, 'percent_off')}
                        </label>
                    ) : (
                        <div className="grid grid-cols-[1fr_80px] gap-2">
                            <label className="text-[11px] text-text-secondary">
                                {t('tools.amount')}
                                <input type="number" min="0" step="0.01" value={form.amount_off} onChange={(e) => set('amount_off', e.target.value)} className={input} />
                                {firstError(errors, 'amount_off')}
                            </label>
                            <label className="text-[11px] text-text-secondary">
                                {t('tools.currency')}
                                <select value={form.currency} onChange={(e) => set('currency', e.target.value)} className={input}>
                                    {data.currencies.map((c) => (
                                        <option key={c} value={c}>
                                            {c.toUpperCase()}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    )}
                    <label className="text-[11px] text-text-secondary">
                        {t('tools.max_uses')}
                        <input type="number" min="1" value={form.max_redemptions} placeholder={t('tools.unlimited')} onChange={(e) => set('max_redemptions', e.target.value)} className={input} />
                    </label>
                    <label className="col-span-2 text-[11px] text-text-secondary sm:col-span-1">
                        {t('tools.expires_on')}
                        <input type="date" value={form.expires_on} onChange={(e) => set('expires_on', e.target.value)} className={input} />
                        {firstError(errors, 'expires_on')}
                    </label>
                </div>
                {data.products.length > 0 && (
                    <div>
                        <p className="mb-1 text-[11px] text-text-secondary">{t('tools.code_books')}</p>
                        <Picker items={data.products} selected={form.product_ids} onChange={(ids) => set('product_ids', ids)} name="tools:code-product" />
                        {firstError(errors, 'product_ids')}
                    </div>
                )}
                {data.bundle_choices.length > 0 && (
                    <Picker items={data.bundle_choices} selected={form.bundle_ids} onChange={(ids) => set('bundle_ids', ids)} name="tools:code-bundle" />
                )}
                <button
                    type="submit"
                    data-a="tools:code-create"
                    disabled={busy || !form.code}
                    className="rounded-button bg-brand-primary px-3 py-1.5 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    {t('tools.create_code')}
                </button>
            </form>
        </section>
    );
}

function Bundles() {
    const { t } = useI18n();
    const [data, setData] = useResource('/sales/bundles');
    const blank = { id: null, title: '', description: '', price: '', currency: 'usd', product_ids: [] };
    const [form, setForm] = useState(blank);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    if (!data) return null;

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const chosen = data.products.filter((p) => form.product_ids.includes(p.id));
    const separately = chosen.reduce((sum, p) => sum + p.price_cents, 0);

    const save = async (published) => {
        setBusy(true);
        setErrors({});

        try {
            const payload = { ...form, published };
            const response = form.id
                ? await window.axios.put(`/sales/bundles/${form.id}`, payload)
                : await window.axios.post('/sales/bundles', payload);
            setData(response.data);
            setForm(blank);
        } catch (error) {
            setErrors(error.response?.data?.errors ?? { title: [t('tools.failed')] });
        } finally {
            setBusy(false);
        }
    };

    const remove = async (bundle) => {
        if (window.confirm(t('tools.delete_bundle_confirm', { title: bundle.title }))) {
            setData((await window.axios.delete(`/sales/bundles/${bundle.id}`)).data);
        }
    };

    return (
        <section data-a="tools:bundles" className="rounded-panel border border-border-default bg-bg-surface p-4">
            <h2 className="text-[15px] font-semibold text-text-primary">{t('tools.bundles_title')}</h2>
            <p className="mt-0.5 text-[12px] leading-snug text-text-secondary">{t('tools.bundles_help')}</p>

            {data.bundles.length > 0 && (
                <ul className="mt-3 divide-y divide-border-default">
                    {data.bundles.map((bundle) => (
                        <li key={bundle.id} data-a="tools:bundle" className="flex flex-wrap items-center justify-between gap-2 py-2 text-[12.5px]">
                            <span className="min-w-0">
                                <span className="font-semibold text-text-primary">{bundle.title}</span>
                                <span className="ml-2 text-text-secondary">
                                    {t('tools.bundle_line', {
                                        count: bundle.product_ids.length,
                                        price: money(bundle.price_cents, bundle.currency),
                                        separately: money(bundle.separately_cents, bundle.currency),
                                    })}
                                </span>
                                {' · '}
                                {bundle.url ? (
                                    <a href={bundle.url} className="text-brand-primary hover:underline">
                                        {t('sales.on_sale')}
                                    </a>
                                ) : (
                                    <span className="text-text-secondary">{bundle.published ? t('tools.waiting_books') : t('sales.not_on_sale')}</span>
                                )}
                            </span>
                            <span className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => setForm({ ...bundle })}
                                    className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                >
                                    {t('tools.edit')}
                                </button>
                                <button type="button" onClick={() => remove(bundle)} className="text-[11.5px] font-semibold text-status-danger hover:underline">
                                    {t('tools.delete')}
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <div className="mt-3 space-y-2 border-t border-border-default pt-3">
                <label className="block text-[11px] text-text-secondary">
                    {t('tools.bundle_name')}
                    <input data-a="tools:bundle-title" value={form.title} onChange={(e) => set('title', e.target.value)} className={input} />
                    {firstError(errors, 'title')}
                </label>
                <div className="grid grid-cols-[1fr_90px] gap-2">
                    <label className="text-[11px] text-text-secondary">
                        {t('tools.bundle_price')}
                        <input data-a="tools:bundle-price" type="number" min="0" step="0.01" value={form.price} onChange={(e) => set('price', e.target.value)} className={input} />
                        {firstError(errors, 'price')}
                    </label>
                    <label className="text-[11px] text-text-secondary">
                        {t('tools.currency')}
                        <select value={form.currency} onChange={(e) => set('currency', e.target.value)} className={input}>
                            {data.currencies.map((c) => (
                                <option key={c} value={c}>
                                    {c.toUpperCase()}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div>
                    <p className="mb-1 text-[11px] text-text-secondary">{t('tools.bundle_books')}</p>
                    <Picker items={data.products} selected={form.product_ids} onChange={(ids) => set('product_ids', ids)} name="tools:bundle-product" />
                    {firstError(errors, 'product_ids')}
                    {chosen.length > 1 && (
                        <p className="mt-1 text-[11px] text-text-secondary">
                            {t('tools.bundle_separately', { price: money(separately, form.currency) })}
                        </p>
                    )}
                </div>
                <label className="block text-[11px] text-text-secondary">
                    {t('tools.bundle_description')}
                    <textarea rows={2} value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} className={input} />
                </label>
                <p className="text-[11px] leading-snug text-text-secondary">{t('tools.bundle_royalty_rule')}</p>
                {firstError(errors, 'published')}
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        data-a="tools:bundle-publish"
                        disabled={busy}
                        onClick={() => save(true)}
                        className="rounded-button bg-brand-primary px-3 py-1.5 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                    >
                        {t('tools.bundle_publish')}
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => save(false)}
                        className="rounded-button border border-border-default px-3 py-1.5 text-[12.5px] font-semibold text-text-body hover:bg-bg-subtle disabled:opacity-60"
                    >
                        {t('tools.bundle_draft')}
                    </button>
                    {form.id && (
                        <button type="button" onClick={() => setForm(blank)} className="text-[12px] text-text-secondary hover:underline">
                            {t('tools.cancel')}
                        </button>
                    )}
                </div>
            </div>
        </section>
    );
}
