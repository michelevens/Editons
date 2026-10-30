import { useCallback, useEffect, useRef, useState } from 'react';
import { useI18n } from '../../i18n';
import Icon from './Icon';

/**
 * The print-on-demand kit, inside Export & Publish.
 *
 * Print settings, the print checks for each service, the cover wrap
 * (preview and file), the price and royalty calculator and what each
 * ebook store still needs. Every number comes from the server, which
 * reads it from config/publishing.php: this screen does no arithmetic of
 * its own, so it cannot drift from the stores' published figures.
 */
export default function PrintKit({ bookId, book, refreshKey, save, generate, busy, rendering, locked = false, onNotify }) {
    const { t, locale, formatNumber, formatMoney } = useI18n();
    // English keeps the figures exactly as they always read; other
    // languages get their own decimal comma and currency layout.
    const inches = (n, digits = 3) => (locale === 'en' ? n.toFixed(digits) : formatNumber(n, { minimumFractionDigits: digits, maximumFractionDigits: digits }));
    const usd = (n) => (locale === 'en' ? `$${n.toFixed(2)}` : formatMoney(Math.round(n * 100), 'USD'));
    const [kit, setKit] = useState(null);
    const [service, setService] = useState('kdp');
    const [wrapService, setWrapService] = useState('kdp');
    const [paper, setPaper] = useState(null);
    const [spine, setSpine] = useState('');
    const [calc, setCalc] = useState(null);
    const [discount, setDiscount] = useState('0.55');
    const [uploading, setUploading] = useState(false);
    const alive = useRef(true);
    const pricing = useRef(null);

    const load = useCallback(async () => {
        try {
            const response = await window.axios.get(`/books/${bookId}/print-kit`);
            if (alive.current) {
                setKit(response.data);
                setCalc(response.data.calculator);
            }
        } catch {
            // Shown as "Loading…" until the next change retries.
        }
    }, [bookId]);

    useEffect(() => {
        alive.current = true;

        return () => {
            alive.current = false;
        };
    }, []);

    // Again when the listing or the files change, once typing settles: the
    // checks read the description, the ISBN and the typeset page count.
    useEffect(() => {
        const timer = setTimeout(load, kit ? 800 : 0);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load, refreshKey]);

    // The calculator follows the prices typed, a moment after typing stops.
    useEffect(() => {
        if (!kit) return undefined;

        clearTimeout(pricing.current);
        pricing.current = setTimeout(async () => {
            try {
                const response = await window.axios.get(`/books/${bookId}/print-kit/prices`, {
                    params: {
                        ebook: book.list_price || undefined,
                        print: book.print_price || undefined,
                        discount: discount || undefined,
                    },
                });
                if (alive.current) setCalc(response.data);
            } catch {
                // A price the server refuses keeps the last result.
            }
        }, 400);

        return () => clearTimeout(pricing.current);
    }, [bookId, book.list_price, book.print_price, discount, kit]);

    const updatePrint = async (changes) => {
        try {
            const response = await window.axios.patch(`/books/${bookId}/print-kit`, changes);
            setKit(response.data);
            setCalc(response.data.calculator);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('printkit.setting_not_saved'));
        }
    };

    const uploadBack = async (file) => {
        if (!file) return;
        setUploading(true);

        try {
            const form = new FormData();
            form.append('back', file);
            const response = await window.axios.post(`/books/${bookId}/print-kit/back`, form);
            setKit(response.data);
            onNotify?.(t('printkit.back_added'));
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('printkit.image_unusable'));
        } finally {
            setUploading(false);
        }
    };

    const removeBack = async () => {
        try {
            const response = await window.axios.delete(`/books/${bookId}/print-kit/back`);
            setKit(response.data);
        } catch (error) {
            onNotify?.(error.response?.data?.message ?? t('printkit.back_not_removed'));
        }
    };

    if (!kit) {
        return (
            <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                <p className="text-[11.5px] text-text-secondary">{t('printkit.loading')}</p>
            </section>
        );
    }

    const check = kit.checks.find((item) => item.service === service) ?? kit.checks[0];
    const wrap = kit.services.find((item) => item.service === wrapService);
    const wrapPaper = wrap.papers.includes(paper ?? kit.print.paper) ? (paper ?? kit.print.paper) : wrap.papers[0];
    const needsSpine = wrap.spine_entered;
    const spineOk = !needsSpine || (Number(spine) > 0 && Number(spine) <= 4);
    const options = { service: wrapService, paper: wrapPaper, ...(needsSpine ? { spine } : {}) };
    const previewUrl = spineOk
        ? `/books/${bookId}/print-kit/cover-preview?${new URLSearchParams(options)}&k=${encodeURIComponent(refreshKey ?? '')}`
        : null;
    const pages = kit.pages;

    return (
        <section
            data-a="kit"
            className="space-y-4 rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <div>
                <h3 className="text-[13px] font-semibold text-text-primary">{t('printkit.title')}</h3>
                <p className="mt-0.5 text-[11px] leading-snug text-text-secondary">
                    {t('printkit.intro', { date: kit.checked })}
                </p>
            </div>

            {/* Print settings */}
            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                <Select
                    label={t('printkit.interior')}
                    value={kit.print.ink}
                    data="kit:ink"
                    onChange={(value) => updatePrint({ ink: value })}
                    options={{ bw: t('printkit.ink_bw'), standard_color: t('printkit.ink_standard'), premium_color: t('printkit.ink_premium') }}
                />
                <Select
                    label={t('printkit.paper')}
                    value={kit.print.paper}
                    data="kit:paper"
                    disabled={kit.print.ink !== 'bw'}
                    onChange={(value) => updatePrint({ paper: value })}
                    options={{ white: t('printkit.paper_white'), cream: t('printkit.paper_cream') }}
                />
                <Select
                    label={t('printkit.finish')}
                    value={kit.print.finish}
                    data="kit:finish"
                    onChange={(value) => updatePrint({ finish: value })}
                    options={{ matte: t('printkit.finish_matte'), glossy: t('printkit.finish_glossy') }}
                />
            </div>

            {/* The spine is what shows on a shelf: matching the cover's
                edge suits one book, a chosen colour suits a series. */}
            <div className="flex flex-wrap items-end gap-2.5">
                <Select
                    label={t('printkit.spine_colour')}
                    value={kit.print.spine_color ? 'custom' : 'auto'}
                    data="kit:spine-mode"
                    onChange={(value) => updatePrint({ spine_color: value === 'auto' ? null : kit.print.spine_color || '#06243e' })}
                    options={{ auto: t('printkit.spine_match'), custom: t('printkit.spine_choose') }}
                />
                {kit.print.spine_color && (
                    <label className="flex items-center gap-2 pb-1 text-[12.5px] text-text-body">
                        <input
                            type="color"
                            value={kit.print.spine_color}
                            data-a="kit:spine-color"
                            onChange={(event) => updatePrint({ spine_color: event.target.value })}
                            className="h-9 w-12 cursor-pointer rounded-control border border-border-input bg-transparent p-0.5"
                            aria-label={t('printkit.spine_colour')}
                        />
                        {kit.print.spine_color}
                    </label>
                )}
            </div>
            <p data-a="kit:pages" className="text-[11.5px] text-text-body">
                {t('printkit.trim_margins', { trim: kit.trim, margins: kit.margins })}{' '}
                {pages.exact
                    ? t('printkit.pages_exact', { count: pages.pages, at: pages.at })
                    : t('printkit.pages_estimated', { count: pages.pages })}
                {t('printkit.set_in_design')}
            </p>

            {/* Print checks */}
            <div>
                <div role="tablist" aria-label={t('printkit.print_service')} className="mb-2 flex flex-wrap gap-1.5">
                    {kit.checks.map((item) => (
                        <button
                            key={item.service}
                            type="button"
                            role="tab"
                            aria-selected={item.service === check.service}
                            data-a={`kit:service:${item.service}`}
                            onClick={() => setService(item.service)}
                            className={`inline-flex items-center gap-1.5 rounded-control border px-2.5 py-1.5 text-[12px] font-medium transition ${
                                item.service === check.service
                                    ? 'border-brand-primary bg-brand-primary/10 text-text-primary'
                                    : 'border-border-input text-text-body hover:border-brand-primary/40'
                            }`}
                        >
                            <Dot status={item.status} />
                            {item.label}
                        </button>
                    ))}
                </div>
                <ul role="tabpanel" data-a={`kit:check:${check.service}`} className="space-y-1.5">
                    {check.items.map((item, index) => (
                        <li
                            key={`${item.key}-${index}`}
                            data-a={`kit:item:${item.key}`}
                            data-status={item.status}
                            className="flex gap-2 text-[11.5px] leading-snug"
                        >
                            <Dot status={item.status} />
                            <span className="min-w-0">
                                <span className="text-text-body">{item.message}</span>
                                {item.fix && item.status !== 'pass' && (
                                    <span className="block font-medium text-text-primary">{item.fix}</span>
                                )}
                                {item.fix && item.status === 'pass' && (
                                    <span className="block text-text-secondary">{item.fix}</span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            {/* Cover wrap */}
            <div className="border-t border-border-default pt-3">
                <h4 className="text-[12.5px] font-semibold text-text-primary">{t('printkit.print_cover')}</h4>
                <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                    {t('printkit.print_cover_intro')}
                </p>
                <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                    <Select
                        label={t('printkit.service')}
                        value={wrapService}
                        data="kit:cover:service"
                        onChange={setWrapService}
                        options={Object.fromEntries(kit.services.map((item) => [item.service, item.label]))}
                    />
                    <Select
                        label={t('printkit.paper')}
                        value={wrapPaper}
                        data="kit:cover:paper"
                        disabled={kit.print.ink !== 'bw' || wrap.papers.length === 1}
                        onChange={setPaper}
                        options={Object.fromEntries(wrap.papers.map((item) => [item, item === 'white' ? t('printkit.paper_white') : t('printkit.paper_cream')]))}
                    />
                    {needsSpine && (
                        <label className="block">
                            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                                {t('printkit.spine_width')}
                            </span>
                            <input
                                value={spine}
                                inputMode="decimal"
                                data-a="kit:cover:spine"
                                placeholder={t('printkit.from_template')}
                                onChange={(event) => setSpine(event.target.value)}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            />
                        </label>
                    )}
                </div>
                {needsSpine && (
                    <p className="mt-1 text-[11px] leading-snug text-text-secondary">
                        {t('printkit.spine_from_generator', { service: wrap.label, count: pages.pages })}
                    </p>
                )}
                {wrap.size && (
                    <p data-a="kit:cover:size" className="mt-1.5 text-[11.5px] text-text-body">
                        {t('printkit.wrap_size', {
                            width: inches(wrap.size.width),
                            height: inches(wrap.size.height),
                            spine: inches(wrap.size.spine),
                            bleed: locale === 'en' ? wrap.size.bleed : formatNumber(wrap.size.bleed),
                        })}{' '}
                        {wrap.size.spine_text
                            ? t('printkit.spine_text')
                            : t('printkit.no_spine_text', { count: wrap.spine_text_min_pages })}
                    </p>
                )}
                {previewUrl && (
                    <img
                        src={previewUrl}
                        data-a="kit:cover:preview"
                        alt={t('printkit.preview_alt', { service: wrap.label })}
                        className="mt-2 w-full max-w-[640px] rounded-control border border-border-default"
                    />
                )}
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        data-a="kit:cover:make"
                        disabled={locked || !spineOk || busy === 'format:cover_wrap' || rendering('cover_wrap')}
                        title={locked ? t('printkit.plan_no_pdf') : undefined}
                        onClick={() => generate('cover_wrap', options)}
                        className="inline-flex items-center gap-1.5 rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        <Icon name="cover" size={13} strokeWidth={2} />
                        {busy === 'format:cover_wrap' || rendering('cover_wrap')
                            ? t('printkit.making_cover')
                            : t('printkit.make_cover', { service: wrap.label })}
                    </button>
                    <label className="inline-flex cursor-pointer items-center rounded-control border border-border-input px-2.5 py-1.5 text-[11.5px] font-medium text-text-body focus-within:ring-2 focus-within:ring-brand-primary hover:border-brand-primary/40">
                        {uploading ? t('printkit.uploading') : kit.back.uploaded ? t('printkit.replace_back') : t('printkit.upload_back')}
                        <input
                            type="file"
                            accept="image/jpeg,image/png"
                            data-a="kit:back:upload"
                            className="sr-only"
                            onChange={(event) => uploadBack(event.target.files?.[0])}
                        />
                    </label>
                    {kit.back.uploaded && (
                        <button
                            type="button"
                            data-a="kit:back:remove"
                            onClick={removeBack}
                            className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                        >
                            {t('printkit.use_designed_back')}
                        </button>
                    )}
                </div>
                <p className="mt-1.5 text-[11px] leading-snug text-text-secondary">
                    {kit.back.uploaded
                        ? t('printkit.back_uploaded')
                        : kit.back.has_copy
                          ? t('printkit.back_designed')
                          : t('printkit.back_title_only')}{' '}
                    {t('printkit.back_place')}
                </p>
            </div>

            {/* Prices and royalties */}
            <div className="border-t border-border-default pt-3">
                <h4 className="text-[12.5px] font-semibold text-text-primary">{t('printkit.prices')}</h4>
                <div className="mt-2 grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                    <PriceInput
                        label={t('printkit.ebook_price')}
                        value={book.list_price}
                        data="kit:price:ebook"
                        onChange={(value) => save({ list_price: value || null })}
                    />
                    <PriceInput
                        label={t('printkit.paperback_price')}
                        value={book.print_price}
                        data="kit:price:print"
                        onChange={(value) => save({ print_price: value || null })}
                    />
                    <label className="block">
                        <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                            {t('printkit.ingram_discount')}
                        </span>
                        <select
                            value={discount}
                            data-a="kit:discount"
                            onChange={(event) => setDiscount(event.target.value)}
                            className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                        >
                            {[0.4, 0.45, 0.5, 0.55].map((value) => (
                                <option key={value} value={String(value)}>
                                    {t('printkit.percent', { n: Math.round(value * 100) })}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                {calc && (
                    <ul className="mt-2 divide-y divide-border-default">
                        {[...calc.print, ...calc.ebook].map((row) => (
                            <li key={row.channel} data-a={`kit:royalty:${row.channel}`} className="py-2">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span className="text-[12.5px] font-medium text-text-primary">{row.label}</span>
                                    <span className="text-[12.5px] tabular-nums text-text-primary">
                                        {row.royalty !== null && row.royalty !== undefined
                                            ? t('printkit.royalty_a_copy', { amount: usd(row.royalty) })
                                            : row.wholesale !== null && row.wholesale !== undefined
                                              ? t('printkit.before_print_cost', { amount: usd(row.wholesale) })
                                              : t('printkit.set_a_price')}
                                        {row.rate ? t('printkit.rate', { n: Math.round(row.rate * 100) }) : ''}
                                    </span>
                                </div>
                                {row.print_cost !== null && row.print_cost !== undefined && (
                                    <p className="text-[11px] text-text-body">
                                        {t('printkit.print_cost', {
                                            cost: usd(row.print_cost),
                                            minimum: row.minimum_price != null ? usd(row.minimum_price) : '$',
                                        })}
                                        {row.expanded_royalty !== null &&
                                            t('printkit.expanded', { amount: usd(row.expanded_royalty) })}
                                    </p>
                                )}
                                {row.problem && (
                                    <p className="text-[11px] font-medium text-status-warning">{row.problem}</p>
                                )}
                                <p className="text-[11px] leading-snug text-text-secondary">
                                    {row.note}{' '}
                                    <a
                                        href={row.source}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-brand-primary hover:underline"
                                    >
                                        {t('printkit.source')}
                                    </a>
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-1 text-[10.5px] leading-snug text-text-secondary">
                    {t('printkit.figures_note', { date: kit.checked })}
                </p>
            </div>

            {/* Ebook stores */}
            <div className="border-t border-border-default pt-3">
                <h4 className="mb-1.5 text-[12.5px] font-semibold text-text-primary">{t('printkit.ebook_stores')}</h4>
                <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    {kit.stores.map((store) => (
                        <div key={store.channel} data-a={`kit:store:${store.channel}`} data-status={store.status}>
                            <p className="flex items-center gap-1.5 text-[12px] font-medium text-text-primary">
                                <Dot status={store.status} />
                                {store.label}
                            </p>
                            <ul className="mt-0.5 space-y-0.5 pl-4">
                                {store.items
                                    .filter((item) => item.status !== 'pass')
                                    .map((item) => (
                                        <li key={item.key} className="text-[11px] leading-snug text-text-body">
                                            {item.message} {item.fix && <span className="font-medium">{item.fix}</span>}
                                        </li>
                                    ))}
                                {store.items.every((item) => item.status === 'pass') && (
                                    <li className="text-[11px] text-text-secondary">{t('printkit.store_complete')}</li>
                                )}
                            </ul>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

const STATUS = {
    pass: ['bg-status-success', 'printkit.status_pass'],
    warn: ['bg-status-warning', 'printkit.status_warn'],
    fail: ['bg-status-danger', 'printkit.status_fail'],
};

function Dot({ status }) {
    const { t } = useI18n();
    const [colour, key] = STATUS[status] ?? STATUS.warn;
    const label = t(key);

    return (
        <span className="mt-[3px] inline-flex shrink-0" title={label}>
            <span className={`h-2 w-2 rounded-full ${colour}`} aria-hidden="true" />
            <span className="sr-only">{t('printkit.status_sr', { label })}</span>
        </span>
    );
}

function Select({ label, value, options, onChange, data, disabled = false }) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">{label}</span>
            <select
                value={value}
                data-a={data}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0 disabled:opacity-60"
            >
                {Object.entries(options).map(([key, text]) => (
                    <option key={key} value={key}>
                        {text}
                    </option>
                ))}
            </select>
        </label>
    );
}

function PriceInput({ label, value, onChange, data }) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">{label}</span>
            <input
                value={value ?? ''}
                inputMode="decimal"
                data-a={data}
                onChange={(event) => onChange(event.target.value)}
                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
            />
        </label>
    );
}
