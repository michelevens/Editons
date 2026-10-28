import { useCallback, useEffect, useState } from 'react';

/**
 * Sell this book from your own storefront.
 *
 * Price, currency, the formats a buyer downloads (the latest EPUB/PDF from
 * Export & Publish), the license, and whether it is on sale. Saving as a
 * draft always works; putting it on sale needs a ready export of each
 * format, and the server says which one is missing.
 *
 * Owner only: the server answers 403 to anyone else, and this hides itself.
 */
export default function ProductEditor({ bookId, onSaved }) {
    const [data, setData] = useState(null);
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [hidden, setHidden] = useState(false);
    const [notice, setNotice] = useState(null);

    const load = useCallback(async () => {
        try {
            const response = await window.axios.get(`/books/${bookId}/product`);
            setData(response.data);
            setForm(toForm(response.data));
        } catch {
            setHidden(true);
        }
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    if (hidden || !data) {
        return null;
    }

    if (!data.enabled) {
        return (
            <section
                data-a="store:off"
                className="rounded-tile border border-border-default bg-bg-surface p-3.5 text-[12px] text-text-secondary"
            >
                <h3 className="mb-1 text-[13px] font-semibold text-text-primary">Your storefront</h3>
                Selling directly to readers turns on once card payments are connected.
            </section>
        );
    }

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

    const toggleFormat = (format) =>
        set(
            'formats',
            form.formats.includes(format)
                ? form.formats.filter((f) => f !== format)
                : [...form.formats, format],
        );

    const save = async (published) => {
        setSaving(true);
        setErrors({});
        setNotice(null);

        try {
            const response = await window.axios.put(`/books/${bookId}/product`, {
                ...form,
                published,
            });
            setData(response.data);
            setForm(toForm(response.data));
            setNotice(published ? 'On sale.' : 'Saved. Not on sale.');
            onSaved?.();
        } catch (error) {
            setErrors(error.response?.data?.errors ?? { form: ['Could not save. Try again.'] });
        } finally {
            setSaving(false);
        }
    };

    const product = data.product;
    const license = data.options.licenses.find((l) => l.key === form.license);

    return (
        <section
            data-a="store:product"
            className="rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <div className="mb-2.5 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">Your storefront</h3>
                {product?.published ? (
                    <span className="rounded-badge bg-status-success-bg px-1.5 py-0.5 text-[10.5px] font-semibold text-status-success">
                        On sale
                    </span>
                ) : (
                    <span className="rounded-badge bg-bg-subtle px-1.5 py-0.5 text-[10.5px] font-semibold text-text-secondary">
                        Not on sale
                    </span>
                )}
            </div>

            {product?.taken_down && (
                <p role="status" data-a="store:taken-down" className="mb-3 rounded-tile bg-status-danger-bg px-2.5 py-2 text-[12px] text-status-danger">
                    Ecrivain took this store page down: {product.taken_down.reason} Readers who bought it keep their copy. Write
                    to support from Help if you think it is a mistake.
                </p>
            )}

            {product?.url && (
                <p className="mb-3 text-[12px]">
                    <a
                        data-a="store:link"
                        href={product.url}
                        target="_blank"
                        rel="noreferrer"
                        className="font-semibold text-brand-primary hover:underline"
                    >
                        View the book&rsquo;s page
                    </a>
                    {data.store_url && (
                        <>
                            <span className="mx-1.5 text-text-secondary">·</span>
                            <a
                                href={data.store_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-brand-primary hover:underline"
                            >
                                Your shelf
                            </a>
                        </>
                    )}
                </p>
            )}

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_110px]">
                <Field label="Price" error={errors.price}>
                    <input
                        data-a="store:price"
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={form.price}
                        onChange={(e) => set('price', e.target.value)}
                        className="w-full rounded-input border border-border-input bg-bg-surface px-2.5 py-1.5 text-[13px] text-text-primary"
                    />
                </Field>
                <Field label="Currency" error={errors.currency}>
                    <select
                        data-a="store:currency"
                        value={form.currency}
                        onChange={(e) => set('currency', e.target.value)}
                        className="w-full rounded-input border border-border-input bg-bg-surface px-2 py-1.5 text-[13px] text-text-primary"
                    >
                        {data.options.currencies.map((c) => (
                            <option key={c} value={c}>
                                {c.toUpperCase()}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <Field label="Buyers download" error={errors.formats ?? errors['formats.0']}>
                <div className="flex flex-wrap gap-3">
                    {Object.entries(data.exports).map(([format, info]) => (
                        <label key={format} className="flex items-center gap-1.5 text-[12.5px] text-text-body">
                            <input
                                data-a={`store:format:${format}`}
                                type="checkbox"
                                checked={form.formats.includes(format)}
                                onChange={() => toggleFormat(format)}
                            />
                            {info.label}
                            <span className="text-[11px] text-text-secondary">
                                {info.ready ? `exported ${info.created_at}` : 'not exported yet'}
                            </span>
                        </label>
                    ))}
                </div>
            </Field>

            <Field label="License" error={errors.license}>
                <select
                    data-a="store:license"
                    value={form.license}
                    onChange={(e) => set('license', e.target.value)}
                    className="w-full rounded-input border border-border-input bg-bg-surface px-2 py-1.5 text-[13px] text-text-primary"
                >
                    {data.options.licenses.map((l) => (
                        <option key={l.key} value={l.key}>
                            {l.label}
                        </option>
                    ))}
                </select>
                {license && (
                    <p className="mt-1 text-[11px] leading-snug text-text-secondary">{license.terms}</p>
                )}
            </Field>

            <Field label="Title on the storefront" error={errors.title}>
                <input
                    type="text"
                    value={form.title}
                    placeholder={data.defaults.title}
                    onChange={(e) => set('title', e.target.value)}
                    className="w-full rounded-input border border-border-input bg-bg-surface px-2.5 py-1.5 text-[13px] text-text-primary"
                />
            </Field>

            <Field label="Description" error={errors.description}>
                <textarea
                    data-a="store:description"
                    rows={4}
                    value={form.description}
                    placeholder={data.defaults.description || 'What a reader should know before buying.'}
                    onChange={(e) => set('description', e.target.value)}
                    className="w-full rounded-input border border-border-input bg-bg-surface px-2.5 py-1.5 text-[12.5px] leading-relaxed text-text-primary"
                />
                <p className="mt-1 text-[11px] text-text-secondary">Plain text. Line breaks are kept.</p>
            </Field>

            {/* "Read a sample" (App\Commerce\Sample): a Sample-profile
                export made on Export & Publish, linked from the page. */}
            <div className="mb-3 rounded-input border border-border-default p-2.5">
                <label className="flex items-start gap-2 text-[12.5px] text-text-body">
                    <input
                        type="checkbox"
                        data-a="store:sample"
                        checked={!!form.sample_enabled}
                        onChange={(e) => set('sample_enabled', e.target.checked)}
                        className="mt-0.5"
                    />
                    <span>
                        Offer “Read a sample” on the page
                        <span className="block text-[11px] leading-snug text-text-secondary">
                            {data.samples?.length
                                ? `Readers get your latest Sample file (${data.samples.map((f) => f.toUpperCase()).join(', ')}).`
                                : 'Make an EPUB or PDF with the Sample profile on Export & Publish first; until then no link shows.'}
                        </span>
                    </span>
                </label>
            </div>

            {data.partners && (
                <PartnerOptions
                    partners={data.partners}
                    form={form}
                    set={set}
                    errors={errors}
                />
            )}

            <p className="mb-3 text-[11px] leading-snug text-text-secondary">
                {data.defaults.author_name
                    ? `The page shows the author as “${data.defaults.author_name}”.`
                    : 'This book has no author name, so the page shows none and it is not listed on any author shelf.'}
            </p>

            {(errors.published || errors.form) && (
                <p className="mb-2 text-[11.5px] text-status-danger">{errors.published ?? errors.form}</p>
            )}
            {notice && (
                <p role="status" className="mb-2 text-[11.5px] text-status-success">
                    {notice}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <button
                    data-a="store:publish"
                    type="button"
                    disabled={saving}
                    onClick={() => save(true)}
                    className="rounded-button bg-brand-primary px-3 py-1.5 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60"
                >
                    {product?.published ? 'Save' : 'Put on sale'}
                </button>
                <button
                    data-a="store:draft"
                    type="button"
                    disabled={saving}
                    onClick={() => save(false)}
                    className="rounded-button border border-border-default px-3 py-1.5 text-[12.5px] font-semibold text-text-body hover:bg-bg-subtle disabled:opacity-60"
                >
                    {product?.published ? 'Take off sale' : 'Save draft'}
                </button>
            </div>
        </section>
    );
}

function toForm(data) {
    const p = data.product;

    return {
        price: p?.price ?? data.defaults.price ?? '',
        currency: p?.currency ?? data.options.currencies[0],
        formats: p?.formats ?? Object.keys(data.exports).filter((f) => data.exports[f].ready),
        license: p?.license ?? data.options.licenses[0]?.key,
        title: p?.title ?? '',
        description: p?.description ?? '',
        sample_enabled: p?.sample_enabled ?? false,
        // Sent only when the partner program is on for this account.
        ...(data.partners
            ? {
                  partner_enabled: p?.partner_enabled ?? false,
                  partner_rate: p?.partner_rate ?? data.partners.rates.default,
              }
            : {}),
    };
}

/**
 * The same arithmetic as App\Commerce\Partners\Commissions::split, for the
 * preview only: what one sale pays the author with and without a partner.
 * The server works the real figures out when an order is paid.
 */
export function partnerSplit(priceCents, ratePercent, fee, platformRate) {
    const gross = Math.max(0, Math.round(priceCents));
    const feeCents = gross <= 0 ? 0 : Math.min(gross, Math.round((gross * fee.percent) / 100) + fee.fixed_cents);
    const net = Math.max(0, gross - feeCents);
    const royalty = Math.max(0, net - Math.round(net * platformRate));
    const commission = Math.min(royalty, net <= 0 ? 0 : Math.floor((net * ratePercent * 100 + 5000) / 10000));

    return { fee: feeCents, without: royalty, with: royalty - commission, commission };
}

/**
 * "Let partners sell this book": the switch, the rate, and — before it is
 * switched on — what one sale would pay with and without a partner.
 */
function PartnerOptions({ partners, form, set, errors }) {
    const priceCents = Math.round((parseFloat(form.price) || 0) * 100);
    const rate = Number(form.partner_rate) || partners.rates.default;
    const split = partnerSplit(priceCents, rate, partners.fee, partners.platform_rate);
    const format = (cents) =>
        new Intl.NumberFormat(undefined, { style: 'currency', currency: String(form.currency || 'usd').toUpperCase() }).format(cents / 100);

    return (
        <div data-a="store:partners" className="mb-3 rounded-tile border border-border-default bg-bg-subtle/50 p-3">
            <label className="flex items-start gap-2 text-[12.5px] font-semibold text-text-primary">
                <input
                    data-a="store:partners:toggle"
                    type="checkbox"
                    className="mt-0.5"
                    checked={!!form.partner_enabled}
                    onChange={(e) => set('partner_enabled', e.target.checked)}
                />
                <span>
                    Let partners sell this book
                    <span className="mt-0.5 block text-[11px] font-normal leading-snug text-text-secondary">
                        Teachers, bookstores and others share it from their own Écrivain Partners page and earn a
                        commission on each copy they sell. It comes out of your royalty.
                    </span>
                </span>
            </label>

            <div className="mt-2.5 flex flex-wrap items-center gap-2 text-[12.5px]">
                <label htmlFor="partner-rate" className="text-text-secondary">
                    Partner commission
                </label>
                <input
                    id="partner-rate"
                    data-a="store:partners:rate"
                    type="number"
                    min={partners.rates.min}
                    max={partners.rates.max}
                    step="1"
                    value={form.partner_rate ?? ''}
                    onChange={(e) => set('partner_rate', e.target.value === '' ? '' : Number(e.target.value))}
                    className="w-[72px] rounded-input border border-border-input bg-bg-surface px-2 py-1 text-[13px] text-text-primary"
                />
                <span className="text-text-secondary">
                    % of the net ({partners.rates.min}–{partners.rates.max} %)
                </span>
            </div>
            {errors.partner_rate && <p className="mt-1 text-[11.5px] text-status-danger">{[].concat(errors.partner_rate)[0]}</p>}

            {priceCents > 0 && (
                <dl data-a="store:partners:preview" className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-1 text-[12px] tabular-nums">
                    <dt className="text-text-secondary">A sale without a partner pays you</dt>
                    <dd className="text-right font-semibold text-text-primary" data-a="store:partners:without">{format(split.without)}</dd>
                    <dt className="text-text-secondary">A sale through a partner pays you</dt>
                    <dd className="text-right font-semibold text-text-primary" data-a="store:partners:with">{format(split.with)}</dd>
                    <dt className="text-text-secondary">and the partner</dt>
                    <dd className="text-right text-text-body">{format(split.commission)}</dd>
                </dl>
            )}
            <p className="mt-1.5 text-[10.5px] leading-snug text-text-secondary">
                At {format(priceCents)}, after the estimated card fee ({format(split.fee)}). Discounts lower both. The rate is fixed
                when a reader starts checkout.
            </p>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div className="mb-3">
            <p className="mb-1 text-[11px] font-medium uppercase tracking-wide text-text-secondary">
                {label}
            </p>
            {children}
            {error && (
                <p className="mt-1 text-[11.5px] text-status-danger">
                    {Array.isArray(error) ? error[0] : error}
                </p>
            )}
        </div>
    );
}
