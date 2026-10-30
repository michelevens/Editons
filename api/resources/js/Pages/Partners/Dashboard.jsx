import { Agreement, Row, field } from '@/Components/Partners/PartnerKit';
import PartnerPageView, { ShareBar } from '@/Components/Partners/PartnerPageView';
import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import { useI18n } from '@/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

/**
 * The partner's dashboard: overview, links, the page editor, commissions,
 * payouts and the agreement. One page with a tab in the URL (?tab=), so a
 * tab can be linked from an email and the phone's tab bar can open one.
 *
 * Buyers are never named: a commission says at most "a reader in Canada".
 */
const TABS = [
    { key: 'overview', tKey: 'partner.tab_overview' },
    { key: 'links', tKey: 'partner.tab_links' },
    { key: 'page', tKey: 'partner.tab_page' },
    { key: 'commissions', tKey: 'partner.tab_commissions' },
    { key: 'payouts', tKey: 'partner.tab_payouts' },
    { key: 'agreement', tKey: 'partner.tab_agreement' },
];

const MOBILE = { overview: 'partner-overview', links: 'partner-links', page: 'partner-page', commissions: 'partner-earnings', payouts: 'partner-earnings', agreement: 'partner-overview' };

export default function Dashboard(props) {
    const { auth } = usePage().props;
    const { tab, partner } = props;
    const { t } = useI18n();
    const go = (key) => router.get(route('partners.dashboard', { tab: key }), {}, { preserveScroll: true, preserveState: key === tab });

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('partner.dashboard_title')} />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[1100px] px-4 py-6 sm:px-8 sm:py-8">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div className="min-w-0">
                            <h1 className="font-display text-[clamp(22px,5vw,30px)] font-extrabold tracking-[-0.03em] text-text-primary">
                                {partner.display_name}
                            </h1>
                            <p className="text-[12.5px] text-text-secondary">
                                {t('partner.code_line')} <span className="font-mono font-semibold text-text-primary">{partner.code}</span>
                            </p>
                        </div>
                        {partner.status === 'approved' && (
                            <a href={partner.url} target="_blank" rel="noreferrer" data-a="partner:open-page" className="text-[13px] font-semibold text-brand-primary hover:underline">
                                {t('partner.view_my_page')}
                            </a>
                        )}
                    </div>

                    <Status partner={partner} />

                    <nav aria-label={t('partner.dashboard_title')} className="-mx-4 mt-4 overflow-x-auto border-b border-border-default px-4 sm:mx-0 sm:px-0">
                        <ul className="flex min-w-max gap-1">
                            {TABS.map((item) => (
                                <li key={item.key}>
                                    <Link
                                        href={route('partners.dashboard', { tab: item.key })}
                                        preserveScroll
                                        data-a={`partner:tab:${item.key}`}
                                        aria-current={item.key === tab ? 'page' : undefined}
                                        className={`tap -mb-px inline-flex h-11 items-center border-b-2 px-3 text-[13.5px] font-medium ${
                                            item.key === tab ? 'border-brand-primary text-text-primary' : 'border-transparent text-text-secondary hover:text-text-primary'
                                        }`}
                                    >
                                        {t(item.tKey)}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    <div className="mt-5">
                        {tab === 'overview' && <Overview {...props} />}
                        {tab === 'links' && <Links {...props} />}
                        {tab === 'page' && <PageEditor {...props} />}
                        {tab === 'commissions' && <Commissions {...props} />}
                        {tab === 'payouts' && <Payouts {...props} />}
                        {tab === 'agreement' && <AgreementTab {...props} />}
                    </div>
                </div>
            </main>

            <MobileNav current={MOBILE[tab]} onSelect={(key) => go({ 'partner-overview': 'overview', 'partner-links': 'links', 'partner-page': 'page', 'partner-earnings': 'commissions' }[key])} />
        </div>
    );
}

function Status({ partner }) {
    const { t } = useI18n();

    if (partner.status === 'applied') {
        return (
            <p role="status" data-a="partner:status:applied" className="mt-3 rounded-tile bg-status-info-bg px-3 py-2 text-[13px] text-status-info">
                {t('partner.status_applied')}
            </p>
        );
    }

    if (partner.status === 'suspended') {
        return (
            <p role="status" data-a="partner:status:suspended" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[13px] text-status-danger">
                {partner.suspend_reason ? t('partner.status_suspended_reason', { reason: partner.suspend_reason }) : t('partner.status_suspended')}
            </p>
        );
    }

    if (partner.status === 'declined') {
        return (
            <p role="status" className="mt-3 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                {t('partner.status_declined')} <Link href={route('partners.apply')} className="font-semibold underline">{t('partner.status_declined_link')}</Link>.
            </p>
        );
    }

    if (partner.restricted) {
        return (
            <p className="mt-3 text-[12.5px] text-text-secondary">
                {t('partner.status_restricted')}
            </p>
        );
    }

    return null;
}

function Tile({ label, value, detail = null, dataA }) {
    return (
        <div className="min-w-0 rounded-panel border border-border-default bg-bg-surface p-3.5" data-a={dataA}>
            <p className="text-[11.5px] font-medium text-text-secondary">{label}</p>
            <p className="mt-1 truncate font-display text-[22px] font-extrabold tabular-nums text-text-primary">{value}</p>
            {detail && <p className="mt-0.5 text-[11px] text-text-secondary">{detail}</p>}
        </div>
    );
}

function Overview({ stats, balances, eligibility, timeline, minimum_cents: minimum, hold_days: holdDays }) {
    const main = balances[0] ?? { currency: 'usd', pending_cents: 0, earned_cents: 0, paid_cents: 0, balance_cents: 0 };
    const next = eligibility[main.currency];
    const max = Math.max(1, ...timeline.map((d) => d.clicks));
    const { t, formatNumber, formatMoney } = useI18n();

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Tile label={t('partner.stat_visits')} value={formatNumber(stats.clicks)} detail={t('partner.stat_recent', { n: stats.clicks_recent, days: stats.days })} dataA="partner:stat:clicks" />
                <Tile label={t('partner.stat_orders')} value={formatNumber(stats.orders)} detail={t('partner.stat_recent', { n: stats.orders_recent, days: stats.days })} dataA="partner:stat:orders" />
                <Tile label={t('partner.stat_conversion')} value={stats.conversion === null ? '—' : `${stats.conversion} %`} detail={t('partner.stat_conversion_detail')} />
                <Tile label={t('partner.stat_balance')} value={formatMoney(main.balance_cents, main.currency)} detail={next?.eligible ? t('partner.stat_ready_to_pay') : t('partner.stat_paid_from', { amount: formatMoney(minimum, main.currency) })} dataA="partner:stat:balance" />
            </div>

            {balances.map((b) => (
                <div key={b.currency} className="grid grid-cols-3 gap-3" data-a={`partner:earnings:${b.currency}`}>
                    <Tile label={t('partner.earnings_pending', { days: holdDays })} value={formatMoney(b.pending_cents, b.currency)} dataA="partner:pending" />
                    <Tile label={t('partner.earnings_approved')} value={formatMoney(b.earned_cents - b.paid_cents, b.currency)} />
                    <Tile label={t('partner.earnings_paid')} value={formatMoney(b.paid_cents, b.currency)} />
                </div>
            ))}

            {next && !next.eligible && main.balance_cents > 0 && (
                <p className="text-[12.5px] text-text-secondary">{t('partner.next_payout', { reason: next.reason })}</p>
            )}

            <section className="rounded-panel border border-border-default bg-bg-surface p-4">
                <h2 className="text-[14px] font-semibold text-text-primary">{t('partner.visits_30_days')}</h2>
                <div className="mt-3 flex h-24 items-end gap-[2px]" role="img" aria-label={t('partner.visits_per_day', { list: timeline.map((d) => d.clicks).join(', ') })}>
                    {timeline.map((d) => (
                        <span
                            key={d.day}
                            title={t('partner.visits_bar', { day: d.day, clicks: d.clicks, orders: d.orders })}
                            className={`min-w-0 flex-1 rounded-t-[2px] ${d.orders > 0 ? 'bg-brand-primary' : 'bg-brand-fill/40'}`}
                            style={{ height: `${d.clicks === 0 ? 2 : Math.max(6, (d.clicks / max) * 96)}px` }}
                        />
                    ))}
                </div>
                <p className="mt-2 text-[11px] text-text-secondary">{t('partner.visits_legend')}</p>
            </section>
        </div>
    );
}

function Links({ page, catalogue, partner, cookie_days: cookieDays }) {
    const { t } = useI18n();
    const [query, setQuery] = useState('');
    const books = catalogue.filter((b) => !query || b.title.toLowerCase().includes(query.toLowerCase()));

    return (
        <div className="space-y-4">
            <ShareBar page={page} />
            <p className="text-[12.5px] text-text-secondary">
                {t('partner.links_cookie', { days: cookieDays })} <span className="font-mono font-semibold text-text-primary">{partner.code}</span>{' '}
                {t('partner.links_code_any_book')} <span className="font-mono">?partner={partner.code}</span> {t('partner.links_to_its_address')}
            </p>

            <section className="rounded-panel border border-border-default bg-bg-surface p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-[14px] font-semibold text-text-primary">{t('partner.links_one_book')}</h2>
                    <input type="search" aria-label={t('partner.links_find_book')} placeholder={t('partner.links_find_book')} value={query} onChange={(e) => setQuery(e.target.value)} className={`${field} max-w-[240px]`} />
                </div>
                {books.length === 0 ? (
                    <p className="mt-3 text-[13px] text-text-secondary">{t('partner.no_open_books')}</p>
                ) : (
                    <ul className="mt-3 divide-y divide-border-divider">
                        {books.slice(0, 60).map((book) => (
                            <BookLink key={book.slug} book={book} partner={partner} />
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function BookLink({ book, partner }) {
    const { t, formatMoney } = useI18n();
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(book.buy_url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt(t('partner.copy_link'), book.buy_url);
        }
    };
    const qr = (format) => `${route('partners.page.qr', [partner.handle, format])}?book=${encodeURIComponent(book.slug)}`;

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5" data-a="partner:link">
            <img src={book.cover_url} alt="" className="h-12 w-8 shrink-0 rounded-[3px] bg-bg-subtle object-cover" loading="lazy" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-[13.5px] font-semibold text-text-primary">{book.title}</p>
                <p className="text-[11.5px] text-text-secondary">
                    {formatMoney(book.price_cents, book.currency)} · {t('partner.you_earn', { rate: book.rate })}
                </p>
            </div>
            <div className="flex flex-wrap gap-1.5">
                <button type="button" onClick={copy} data-a="partner:link:copy" className="tap min-h-[36px] rounded-button border border-border-default px-2.5 text-[12px] font-semibold text-text-primary hover:bg-bg-subtle">
                    {copied ? t('partner.copied') : t('partner.copy_link')}
                </button>
                <a href={`https://wa.me/?text=${encodeURIComponent(`${book.title} ${book.buy_url}`)}`} target="_blank" rel="noopener noreferrer" className="tap inline-flex min-h-[36px] items-center rounded-button border border-border-default px-2.5 text-[12px] font-semibold text-text-primary hover:bg-bg-subtle">
                    WhatsApp
                </a>
                <a href={qr('png')} download className="tap inline-flex min-h-[36px] items-center rounded-button border border-border-default px-2.5 text-[12px] font-semibold text-text-primary hover:bg-bg-subtle">
                    QR
                </a>
            </div>
        </li>
    );
}

function PageEditor({ partner, page, shelf, catalogue, shelf_max: shelfMax, locales, social_kinds: socialKinds }) {
    const form = useForm({
        handle: partner.handle,
        display_name: partner.display_name,
        tagline: partner.tagline ?? '',
        bio: partner.bio ?? '',
        accent: partner.accent ?? '#2166f5',
        page_locale: partner.page_locale,
        socials: (partner.socials ?? []).map((s) => ({ kind: s.kind, value: s.kind === 'whatsapp' ? s.url.replace('https://wa.me/', '+') : s.url })),
        shelf: shelf,
    });
    const pageErrors = usePage().props.errors ?? {};
    const { t } = useI18n();
    const [view, setView] = useState('edit');
    const [handleProblem, setHandleProblem] = useState(null);
    const logo = useRef(null);
    const bySlug = useMemo(() => Object.fromEntries(catalogue.map((b) => [b.slug, b])), [catalogue]);
    const d = form.data;

    const preview = {
        ...page,
        name: d.display_name || page.name,
        tagline: d.tagline,
        bio: d.bio,
        accent: d.accent,
        accent_ink: readableInk(d.accent),
        locale: d.page_locale,
        socials: d.socials.filter((s) => s.value).map((s) => ({ kind: s.kind, url: s.value })),
        disclosure: disclosure(d.page_locale, d.display_name || page.name),
        books: d.shelf.map((item) => (bySlug[item.product] ? { ...bySlug[item.product], note: item.note } : null)).filter(Boolean),
    };

    const save = (event) => {
        event.preventDefault();
        form.put(route('partners.page.update'), { preserveScroll: true });
    };

    const checkHandle = async (value) => {
        try {
            const response = await window.axios.get(route('partners.handle.check'), { params: { handle: value } });
            setHandleProblem(response.data.problem);
        } catch {
            setHandleProblem(null);
        }
    };

    const move = (index, delta) => {
        const next = [...d.shelf];
        const [item] = next.splice(index, 1);
        next.splice(index + delta, 0, item);
        form.setData('shelf', next);
    };

    const available = catalogue.filter((b) => !d.shelf.some((s) => s.product === b.slug));

    const upload = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        router.post(route('partners.logo'), { logo: file }, { forceFormData: true, preserveScroll: true });
    };

    return (
        <div>
            <div className="mb-3 flex gap-1 rounded-control bg-bg-subtle p-1 lg:hidden" role="tablist" aria-label={t('partner.editor_edit_or_preview')}>
                {['edit', 'preview'].map((v) => (
                    <button key={v} type="button" role="tab" aria-selected={view === v} data-a={`partner:editor:${v}`} onClick={() => setView(v)} className={`tap min-h-[38px] flex-1 rounded-control text-[13px] font-semibold ${view === v ? 'bg-bg-surface text-text-primary shadow-sm' : 'text-text-secondary'}`}>
                        {v === 'edit' ? t('partner.editor_edit') : t('partner.editor_preview')}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
                <form onSubmit={save} data-a="partner:editor" className={`min-w-0 space-y-4 rounded-panel border border-border-default bg-bg-surface p-4 ${view === 'edit' ? '' : 'max-lg:hidden'}`}>
                    <Row label={t('partner.editor_address')} htmlFor="pe-handle" error={form.errors.handle ?? handleProblem} hint={partner.handle_change_from ? t('partner.editor_address_hint_limited') : t('partner.editor_address_hint')}>
                        <div className="flex min-w-0 items-center gap-1">
                            <span className="shrink-0 text-[12.5px] text-text-secondary">/p/</span>
                            <input id="pe-handle" data-a="partner:editor:handle" maxLength={30} value={d.handle} onChange={(e) => form.setData('handle', e.target.value.toLowerCase())} onBlur={(e) => checkHandle(e.target.value)} className={field} />
                        </div>
                    </Row>
                    <Row label={t('partner.editor_name')} htmlFor="pe-name" error={form.errors.display_name}>
                        <input id="pe-name" data-a="partner:editor:name" maxLength={80} required value={d.display_name} onChange={(e) => form.setData('display_name', e.target.value)} className={field} />
                    </Row>
                    <Row label={t('partner.editor_tagline', { n: 80 - d.tagline.length })} htmlFor="pe-tagline" error={form.errors.tagline}>
                        <input id="pe-tagline" data-a="partner:editor:tagline" maxLength={80} value={d.tagline} onChange={(e) => form.setData('tagline', e.target.value)} className={field} />
                    </Row>
                    <Row label={t('partner.editor_bio', { n: 600 - d.bio.length })} htmlFor="pe-bio" error={form.errors.bio} hint={t('partner.editor_bio_hint')}>
                        <textarea id="pe-bio" data-a="partner:editor:bio" rows={4} maxLength={600} value={d.bio} onChange={(e) => form.setData('bio', e.target.value)} className={field} />
                    </Row>

                    <div className="grid grid-cols-2 gap-3">
                        <Row label={t('partner.editor_accent')} htmlFor="pe-accent" error={form.errors.accent} hint={t('partner.editor_accent_hint')}>
                            <div className="flex items-center gap-2">
                                <input id="pe-accent" type="color" value={d.accent} onChange={(e) => form.setData('accent', e.target.value)} className="h-10 w-12 shrink-0 cursor-pointer rounded-control border border-border-input bg-bg-surface" />
                                <input aria-label={t('partner.editor_accent_hex')} maxLength={7} value={d.accent} onChange={(e) => form.setData('accent', e.target.value)} className={`${field} font-mono`} />
                            </div>
                        </Row>
                        <Row label={t('partner.editor_language')} htmlFor="pe-locale" error={form.errors.page_locale} hint={d.page_locale === 'ht' ? t('partner.editor_language_ht_hint') : null}>
                            <select id="pe-locale" value={d.page_locale} onChange={(e) => form.setData('page_locale', e.target.value)} className={field}>
                                {locales.map((l) => (
                                    <option key={l} value={l}>
                                        {{ en: 'English', fr: 'Français', ht: 'Kreyòl ayisyen' }[l]}
                                    </option>
                                ))}
                            </select>
                        </Row>
                    </div>

                    <div>
                        <p className="text-[13px] font-medium text-text-primary">{t('partner.editor_logo')}</p>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <input ref={logo} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={upload} data-a="partner:editor:logo" />
                            <button type="button" onClick={() => logo.current?.click()} className="tap min-h-[38px] rounded-button border border-border-default px-3 text-[12.5px] font-semibold text-text-primary hover:bg-bg-subtle">
                                {t('partner.editor_upload_image')}
                            </button>
                            {page.logo_url && (
                                <button type="button" onClick={() => router.delete(route('partners.logo.destroy'), { preserveScroll: true })} className="tap min-h-[38px] rounded-button px-3 text-[12.5px] text-text-secondary hover:bg-bg-subtle">
                                    {t('partner.editor_remove')}
                                </button>
                            )}
                        </div>
                        <p className="mt-1 text-[11.5px] text-text-secondary">{t('partner.editor_logo_hint')}</p>
                        {pageErrors.logo && <p className="mt-1 text-[12px] text-status-danger">{pageErrors.logo}</p>}
                    </div>

                    <fieldset>
                        <legend className="text-[13px] font-medium text-text-primary">{t('partner.editor_links')}</legend>
                        {d.socials.map((s, i) => (
                            <div key={i} className="mt-1.5 flex gap-1.5">
                                <select aria-label={t('partner.editor_link_kind')} value={s.kind} onChange={(e) => form.setData('socials', d.socials.map((x, j) => (j === i ? { ...x, kind: e.target.value } : x)))} className={`${field} w-[120px] shrink-0`}>
                                    {socialKinds.map((k) => (
                                        <option key={k} value={k}>
                                            {k}
                                        </option>
                                    ))}
                                </select>
                                <input aria-label={t('partner.editor_link_address')} placeholder={s.kind === 'whatsapp' ? t('partner.editor_whatsapp_placeholder') : 'https://'} value={s.value} onChange={(e) => form.setData('socials', d.socials.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} className={`${field} min-w-0`} />
                                <button type="button" aria-label={t('partner.editor_remove_link')} onClick={() => form.setData('socials', d.socials.filter((_, j) => j !== i))} className="tap shrink-0 rounded-control px-2 text-text-secondary hover:bg-bg-subtle">
                                    ×
                                </button>
                            </div>
                        ))}
                        {Object.entries(form.errors).filter(([k]) => k.startsWith('socials')).map(([k, v]) => (
                            <p key={k} className="mt-1 text-[12px] text-status-danger">{v}</p>
                        ))}
                        {d.socials.length < 6 && (
                            <button type="button" onClick={() => form.setData('socials', [...d.socials, { kind: 'website', value: '' }])} className="mt-1.5 text-[12.5px] font-semibold text-brand-primary hover:underline">
                                {t('partner.editor_add_link')}
                            </button>
                        )}
                    </fieldset>

                    <fieldset data-a="partner:shelf">
                        <legend className="text-[13px] font-medium text-text-primary">
                            {t('partner.editor_shelf', { count: d.shelf.length, max: shelfMax })}
                        </legend>
                        <ol className="mt-1.5 space-y-2">
                            {d.shelf.map((item, i) => {
                                const book = bySlug[item.product];

                                return (
                                    <li key={item.product} className="rounded-tile border border-border-default p-2.5" data-a="partner:shelf:item">
                                        <div className="flex items-center gap-2">
                                            <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-text-primary">{book?.title ?? t('partner.editor_no_longer_available')}</span>
                                            <button type="button" aria-label={t('partner.editor_move_up')} disabled={i === 0} onClick={() => move(i, -1)} className="tap h-8 w-8 rounded-control text-text-secondary hover:bg-bg-subtle disabled:opacity-30">↑</button>
                                            <button type="button" aria-label={t('partner.editor_move_down')} disabled={i === d.shelf.length - 1} onClick={() => move(i, 1)} className="tap h-8 w-8 rounded-control text-text-secondary hover:bg-bg-subtle disabled:opacity-30">↓</button>
                                            <button type="button" aria-label={t('partner.editor_remove_from_page')} onClick={() => form.setData('shelf', d.shelf.filter((_, j) => j !== i))} className="tap h-8 w-8 rounded-control text-text-secondary hover:bg-bg-subtle">×</button>
                                        </div>
                                        <textarea aria-label={t('partner.editor_note_on', { title: book?.title ?? t('partner.editor_this_book') })} placeholder={t('partner.editor_note_placeholder')} rows={2} maxLength={200} value={item.note ?? ''} onChange={(e) => form.setData('shelf', d.shelf.map((x, j) => (j === i ? { ...x, note: e.target.value } : x)))} className={`${field} mt-1.5 text-[12.5px]`} />
                                    </li>
                                );
                            })}
                        </ol>
                        {form.errors.shelf && <p className="mt-1 text-[12px] text-status-danger">{form.errors.shelf}</p>}
                        {d.shelf.length < shelfMax && available.length > 0 && (
                            <select
                                aria-label={t('partner.editor_add_book')}
                                data-a="partner:shelf:add"
                                value=""
                                onChange={(e) => e.target.value && form.setData('shelf', [...d.shelf, { product: e.target.value, note: '' }])}
                                className={`${field} mt-2`}
                            >
                                <option value="">{t('partner.editor_add_book_option')}</option>
                                {available.map((b) => (
                                    <option key={b.slug} value={b.slug}>
                                        {b.title}
                                        {b.author ? ` — ${b.author}` : ''} ({b.rate} %)
                                    </option>
                                ))}
                            </select>
                        )}
                        {catalogue.length === 0 && <p className="mt-1.5 text-[12px] text-text-secondary">{t('partner.no_open_books')}</p>}
                    </fieldset>

                    <button type="submit" data-a="partner:editor:save" disabled={form.processing} className="tap min-h-[44px] w-full rounded-button bg-brand-primary px-5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60">
                        {form.processing ? t('partner.editor_saving') : t('partner.editor_save')}
                    </button>
                </form>

                <div className={`min-w-0 ${view === 'preview' ? '' : 'max-lg:hidden'}`} data-a="partner:live-preview">
                    <p className="mb-2 text-[11.5px] font-semibold uppercase tracking-[0.08em] text-text-secondary">{t('partner.editor_live_preview')}</p>
                    <div className="rounded-panel border border-dashed border-border-default bg-bg-app p-3 sm:p-4">
                        <PartnerPageView page={preview} interactive={false} />
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * The range the Commissions tab shows (all time unless one is picked) and
 * its totals, with a CSV of the same rows: only this partner's own.
 */
function EarningsRange({ earnings }) {
    const { t, formatMoney } = useI18n();
    const range = earnings.range;
    const [preset, setPreset] = useState(range.preset);
    const [custom, setCustom] = useState({ from: range.from, to: range.to });
    const control = 'h-[34px] min-w-0 rounded-control border border-border-input bg-bg-surface px-2.5 text-[12.5px] text-text-primary';
    const go = (next) => router.get(route('partners.dashboard'), { tab: 'commissions', ...(next.range === 'all' ? {} : next) }, { preserveScroll: true, preserveState: true });
    const current = range.preset === 'custom' ? { range: 'custom', from: range.from, to: range.to } : range.preset === 'all' ? {} : { range: range.preset };
    const search = new URLSearchParams(current).toString();

    return (
        <section className="mb-3 rounded-panel border border-border-default bg-bg-surface p-3" data-a="partner:earnings">
            <div className="flex flex-wrap items-end gap-2">
                <label className="flex flex-col gap-0.5 text-[10.5px] font-medium uppercase tracking-wide text-text-secondary">
                    {t('partner.range_label')}
                    <select
                        data-a="partner:earnings:range"
                        value={preset}
                        onChange={(e) => {
                            setPreset(e.target.value);
                            if (e.target.value !== 'custom') go({ range: e.target.value });
                        }}
                        className={control}
                    >
                        {earnings.presets.map((p) => (
                            <option key={p.key} value={p.key}>{p.label}</option>
                        ))}
                    </select>
                </label>
                {preset === 'custom' && (
                    <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); go({ range: 'custom', ...custom }); }}>
                        <input type="date" aria-label={t('partner.range_from')} required value={custom.from} onChange={(e) => setCustom({ ...custom, from: e.target.value })} className={control} />
                        <input type="date" aria-label={t('partner.range_to')} required value={custom.to} onChange={(e) => setCustom({ ...custom, to: e.target.value })} className={control} />
                        <button type="submit" className="tap h-[34px] rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white">{t('partner.range_apply')}</button>
                    </form>
                )}
                <a
                    href={route('partners.commissions.export') + (search ? `?${search}` : '')}
                    data-a="partner:earnings:csv"
                    download
                    className="tap ml-auto inline-flex min-h-[34px] items-center rounded-control border border-border-input px-3 text-[12.5px] font-semibold text-text-body"
                >
                    {t('partner.range_download_csv')}
                </a>
            </div>
            <p className="mt-2 text-[11.5px] text-text-secondary">
                {range.span}
                {earnings.totals.map((total) => ` · ${t('partner.range_total', { count: total.sales, earned: formatMoney(total.net_cents, total.currency), pending: formatMoney(total.pending_cents, total.currency) })}`).join('')}
            </p>
        </section>
    );
}

function Commissions({ commissions, earnings, hold_days: holdDays }) {
    const { t, formatMoney } = useI18n();
    const range = earnings ? <EarningsRange earnings={earnings} /> : null;

    if (commissions.length === 0) {
        return (
            <>
                {range}
                <p className="rounded-panel border border-border-default bg-bg-surface p-4 text-[13px] text-text-secondary" data-a="partner:commissions:none">
                    {earnings && earnings.range.preset !== 'all' ? t('partner.commissions_none_in_range') : t('partner.commissions_none')}
                </p>
            </>
        );
    }

    return (
        <>
        {range}
        <section className="rounded-panel border border-border-default bg-bg-surface p-2 sm:p-4">
            <ul className="divide-y divide-border-divider" data-a="partner:commissions">
                {commissions.map((c) => (
                    <li key={c.id} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-2 py-2.5 text-[13px]" data-a="partner:commission">
                        <span className="min-w-0">
                            <span className="font-semibold text-text-primary">{c.title}</span>
                            <span className="block text-[11.5px] text-text-secondary">
                                {c.date}
                                {c.kind === 'sale' && ` · ${c.rate} %`}
                                {c.reader && ` · ${c.reader}`}
                                {c.status === 'pending' && c.hold_until && ` · ${t('partner.commission_payable_from', { date: c.hold_until })}`}
                                {c.note && ` · ${c.note}`}
                            </span>
                        </span>
                        <span className="flex items-center gap-2 tabular-nums">
                            <span className={c.net_cents < 0 ? 'text-status-danger' : 'text-text-primary'}>{formatMoney(c.net_cents, c.currency)}</span>
                            <StatusBadge status={c.status} />
                        </span>
                    </li>
                ))}
            </ul>
            <p className="mt-2 px-2 text-[11px] text-text-secondary">
                {t('partner.commissions_explained', { days: holdDays })}
            </p>
        </section>
        </>
    );
}

function StatusBadge({ status }) {
    const { t } = useI18n();
    const label = {
        pending: t('partner.commission_status_pending'),
        approved: t('partner.commission_status_approved'),
        paid: t('partner.commission_status_paid'),
        reversed: t('partner.commission_status_reversed'),
    }[status] ?? status;
    const tone = {
        pending: 'bg-status-info-bg text-status-info',
        approved: 'bg-status-success-bg text-status-success',
        paid: 'bg-bg-subtle text-text-secondary',
        reversed: 'bg-status-warning-bg text-status-warning',
    }[status] ?? 'bg-bg-subtle text-text-secondary';

    return <span className={`rounded-badge px-1.5 py-0.5 text-[10.5px] font-semibold ${tone}`} data-a={`partner:status-badge:${status}`}>{label}</span>;
}

function Payouts({ partner, balances, eligibility, payouts, payout_methods: methods, minimum_cents: minimum }) {
    const { t, formatMoney } = useI18n();
    const form = useForm({ payout_method: partner.payout_method ?? methods[0].key, payout_details: partner.payout_details ?? '' });

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section className="rounded-panel border border-border-default bg-bg-surface p-4">
                <h2 className="text-[14px] font-semibold text-text-primary">{t('partner.stat_balance')}</h2>
                {balances.length === 0 ? (
                    <p className="mt-2 text-[13px] text-text-secondary">{t('partner.payouts_nothing_earned')}</p>
                ) : (
                    <ul className="mt-2 space-y-2">
                        {balances.map((b) => (
                            <li key={b.currency} className="text-[13px]">
                                <span className="font-display text-[20px] font-extrabold tabular-nums text-text-primary">{formatMoney(b.balance_cents, b.currency)}</span>
                                <span className="ml-2 text-text-secondary">{eligibility[b.currency]?.eligible ? t('partner.payouts_next_run') : eligibility[b.currency]?.reason}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    {t('partner.payouts_explained', { amount: formatMoney(minimum, balances[0]?.currency ?? 'usd') })}
                </p>

                <h3 className="mt-5 text-[13px] font-semibold text-text-primary">{t('partner.tab_payouts')}</h3>
                {payouts.length === 0 ? (
                    <p className="mt-1 text-[12.5px] text-text-secondary">{t('partner.payouts_none')}</p>
                ) : (
                    <ul className="mt-1 divide-y divide-border-divider text-[12.5px]" data-a="partner:payouts">
                        {payouts.map((p) => (
                            <li key={p.id} className="flex justify-between gap-2 py-1.5">
                                <span>
                                    {p.paid_on} · {p.method}
                                    {p.reference ? ` · ${p.reference}` : ''}
                                </span>
                                <span className="tabular-nums font-semibold text-text-primary">{formatMoney(p.amount_cents, p.currency)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(route('partners.payout-details'), { preserveScroll: true });
                }}
                className="space-y-3 rounded-panel border border-border-default bg-bg-surface p-4"
                data-a="partner:payout-details"
            >
                <h2 className="text-[14px] font-semibold text-text-primary">{t('partner.payouts_where')}</h2>
                <fieldset className="space-y-1.5">
                    <legend className="sr-only">{t('partner.payouts_method')}</legend>
                    {methods.map((m) => (
                        <label key={m.key} className="flex min-h-[40px] items-center gap-2 rounded-tile border border-border-default px-3 text-[13px]">
                            <input type="radio" name="payout_method" value={m.key} checked={form.data.payout_method === m.key} onChange={() => form.setData('payout_method', m.key)} />
                            {m.label}
                        </label>
                    ))}
                    <p className="flex min-h-[40px] items-center gap-2 rounded-tile border border-dashed border-border-default px-3 text-[13px] text-text-secondary">
                        {t('partner.payouts_stripe_soon')}
                    </p>
                </fieldset>
                <Row label={form.data.payout_method === 'paypal' ? t('partner.payouts_paypal_email') : form.data.payout_method === 'moncash' ? t('partner.payouts_moncash_phone') : t('partner.payouts_bank_note')} htmlFor="pd-details" error={form.errors.payout_details}>
                    <textarea id="pd-details" data-a="partner:payout-details:value" rows={form.data.payout_method === 'bank_transfer' ? 3 : 1} maxLength={500} value={form.data.payout_details} onChange={(e) => form.setData('payout_details', e.target.value)} className={field} />
                </Row>
                <p className="text-[11.5px] text-text-secondary">{t('partner.payouts_stored_encrypted')}</p>
                <button type="submit" disabled={form.processing} className="tap min-h-[42px] rounded-button bg-brand-primary px-4 text-[13.5px] font-semibold text-white disabled:opacity-60">
                    {t('partner.payouts_save')}
                </button>
            </form>
        </div>
    );
}

function AgreementTab({ partner, agreement }) {
    const { t, formatDate } = useI18n();

    return (
        <div className="space-y-3">
            <p className="text-[13px] text-text-body">
                {t('partner.agreement_accepted_version')} <strong>{partner.agreement_version}</strong>
                {partner.agreement_accepted_at && ` ${t('partner.agreement_accepted_on', { date: formatDate(partner.agreement_accepted_at, {}) })}`}.
                {partner.agreement_version !== agreement.version && ` ${t('partner.agreement_newer_version')}`}
            </p>
            <Agreement agreement={agreement} />
        </div>
    );
}

/** The server's PartnerPages::readableInk, for the live preview. */
function readableInk(hex) {
    const lum = (h) => {
        const m = /^#?([0-9a-f]{6})$/i.exec(h ?? '');
        const v = m ? m[1] : '000000';
        const c = [0, 2, 4].map((i) => parseInt(v.slice(i, i + 2), 16) / 255).map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));

        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    };
    const l = lum(hex);

    return (l + 0.05) / (lum('#111827') + 0.05) >= 1.05 / (l + 0.05) ? '#111827' : '#ffffff';
}

function disclosure(locale, name) {
    const text = {
        en: ':name earns a commission on purchases made through this page.',
        fr: ':name touche une commission sur les achats effectués depuis cette page.',
        ht: ':name touche yon komisyon sou acha moun fè atravè paj sa a.',
    };

    return (text[locale] ?? text.en).replace(':name', name);
}
