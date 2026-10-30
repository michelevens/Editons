import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import { Agreement, Row, field } from '@/Components/Partners/PartnerKit';
import { useI18n } from '@/i18n';
import { Head, useForm, usePage } from '@inertiajs/react';

/**
 * Applying to Écrivain Partners. Anybody with an account — a teacher, a
 * bookstore, a church, somebody with a following — can sell books on
 * commission from a page of their own. A person reads every application.
 */
export default function Apply({ declined, previous, promotion, agreement, rates, hold_days: holdDays }) {
    const { auth } = usePage().props;
    const { t } = useI18n();
    const form = useForm({
        display_name: previous?.display_name ?? auth?.user?.name ?? '',
        country: previous?.country ?? '',
        promotion_type: previous?.promotion_type ?? '',
        promotion_note: previous?.promotion_note ?? '',
        links: [0, 1, 2].map((i) => previous?.links?.[i] ?? ''),
        agreement_version: agreement.version,
        accept: false,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('partners.apply.store'));
    };

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('partner.apply_title')} />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[720px] px-4 py-8 sm:px-8">
                    <h1 className="font-display text-[clamp(24px,5vw,30px)] font-extrabold tracking-[-0.03em] text-text-primary">
                        {t('partner.apply_heading')}
                    </h1>
                    <p className="mt-2 text-[14px] leading-relaxed text-text-body">
                        {t('partner.apply_intro', { min: rates.min, max: rates.max, usual: rates.default, days: holdDays })}
                    </p>

                    {declined && (
                        <p role="status" data-a="partners:declined" className="mt-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                            {declined.reason ? t('partner.apply_declined_reason', { reason: declined.reason }) : t('partner.apply_declined')}
                        </p>
                    )}

                    <form onSubmit={submit} data-a="partners:apply" className="mt-6 space-y-4 rounded-panel border border-border-default bg-bg-surface p-4 sm:p-5">
                        <Row label={t('partner.apply_name')} error={form.errors.display_name} htmlFor="p-name" hint={t('partner.apply_name_hint')}>
                            <input id="p-name" data-a="partners:apply:name" required maxLength={80} value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} className={field} />
                        </Row>

                        <Row label={t('partner.apply_country')} error={form.errors.country} htmlFor="p-country">
                            <input id="p-country" maxLength={2} autoCapitalize="characters" placeholder="HT" value={form.data.country} onChange={(e) => form.setData('country', e.target.value.toUpperCase())} className={`${field} max-w-[90px] uppercase`} />
                        </Row>

                        <fieldset>
                            <legend className="text-[13px] font-medium text-text-primary">{t('partner.apply_how_share')}</legend>
                            <div className="mt-1.5 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                {promotion.map((p) => (
                                    <label key={p.key} className="flex min-h-[40px] items-center gap-2 rounded-tile border border-border-default px-3 text-[13.5px]">
                                        <input type="radio" name="promotion_type" value={p.key} data-a={`partners:apply:type:${p.key}`} required checked={form.data.promotion_type === p.key} onChange={() => form.setData('promotion_type', p.key)} />
                                        {p.label}
                                    </label>
                                ))}
                            </div>
                            {form.errors.promotion_type && <p className="mt-1 text-[12px] text-status-danger">{form.errors.promotion_type}</p>}
                        </fieldset>

                        <Row label={t('partner.apply_more')} error={form.errors.promotion_note} htmlFor="p-note">
                            <textarea id="p-note" rows={3} maxLength={1000} value={form.data.promotion_note} onChange={(e) => form.setData('promotion_note', e.target.value)} className={field} placeholder={t('partner.apply_more_placeholder')} />
                        </Row>

                        <div>
                            <p className="text-[13px] font-medium text-text-primary">{t('partner.apply_links')}</p>
                            {form.data.links.map((link, i) => (
                                <div key={i} className="mt-1.5">
                                    <input
                                        aria-label={t('partner.apply_link_n', { n: i + 1 })}
                                        type="url"
                                        inputMode="url"
                                        placeholder="https://"
                                        value={link}
                                        onChange={(e) => form.setData('links', form.data.links.map((l, j) => (j === i ? e.target.value : l)))}
                                        className={field}
                                    />
                                    {form.errors[`links.${i}`] && <p className="mt-1 text-[12px] text-status-danger">{form.errors[`links.${i}`]}</p>}
                                </div>
                            ))}
                        </div>

                        <Agreement agreement={agreement} />

                        <label className="flex items-start gap-2 text-[13.5px] text-text-primary">
                            <input type="checkbox" data-a="partners:apply:accept" className="mt-1" checked={form.data.accept} onChange={(e) => form.setData('accept', e.target.checked)} required />
                            <span>{t('partner.apply_accept', { version: agreement.version })}</span>
                        </label>
                        {(form.errors.accept || form.errors.agreement) && <p className="text-[12px] text-status-danger">{form.errors.accept ?? form.errors.agreement}</p>}

                        <button type="submit" data-a="partners:apply:send" disabled={form.processing} className="tap min-h-[44px] w-full rounded-button bg-brand-primary px-5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60 sm:w-auto">
                            {form.processing ? t('partner.apply_sending') : t('partner.apply_send')}
                        </button>
                    </form>
                </div>
            </main>

            <MobileNav current="partner" />
        </div>
    );
}
