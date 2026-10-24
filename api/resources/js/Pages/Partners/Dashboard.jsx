import { Agreement, Row, field } from '@/Components/Partners/PartnerKit';
import PartnerPageView, { ShareBar } from '@/Components/Partners/PartnerPageView';
import { money } from '@/Components/Store/money';
import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
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
    { key: 'overview', label: 'Overview' },
    { key: 'links', label: 'Links' },
    { key: 'page', label: 'My page' },
    { key: 'commissions', label: 'Commissions' },
    { key: 'payouts', label: 'Payouts' },
    { key: 'agreement', label: 'Agreement' },
];

const MOBILE = { overview: 'partner-overview', links: 'partner-links', page: 'partner-page', commissions: 'partner-earnings', payouts: 'partner-earnings', agreement: 'partner-overview' };

export default function Dashboard(props) {
    const { auth } = usePage().props;
    const { tab, partner } = props;
    const go = (key) => router.get(route('partners.dashboard', { tab: key }), {}, { preserveScroll: true, preserveState: key === tab });

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title="Partner dashboard" />
            <AppHeader active="books" user={auth?.user} account={auth?.account} />

            <main className="min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-[1100px] px-4 py-6 sm:px-8 sm:py-8">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div className="min-w-0">
                            <h1 className="font-display text-[clamp(22px,5vw,30px)] font-extrabold tracking-[-0.03em] text-text-primary">
                                {partner.display_name}
                            </h1>
                            <p className="text-[12.5px] text-text-secondary">
                                Écrivain Partner · code <span className="font-mono font-semibold text-text-primary">{partner.code}</span>
                            </p>
                        </div>
                        {partner.status === 'approved' && (
                            <a href={partner.url} target="_blank" rel="noreferrer" data-a="partner:open-page" className="text-[13px] font-semibold text-brand-primary hover:underline">
                                View my page
                            </a>
                        )}
                    </div>

                    <Status partner={partner} />

                    <nav aria-label="Partner dashboard" className="-mx-4 mt-4 overflow-x-auto border-b border-border-default px-4 sm:mx-0 sm:px-0">
                        <ul className="flex min-w-max gap-1">
                            {TABS.map((t) => (
                                <li key={t.key}>
                                    <Link
                                        href={route('partners.dashboard', { tab: t.key })}
                                        preserveScroll
                                        data-a={`partner:tab:${t.key}`}
                                        aria-current={t.key === tab ? 'page' : undefined}
                                        className={`tap -mb-px inline-flex h-11 items-center border-b-2 px-3 text-[13.5px] font-medium ${
                                            t.key === tab ? 'border-brand-primary text-text-primary' : 'border-transparent text-text-secondary hover:text-text-primary'
                                        }`}
                                    >
                                        {t.label}
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
    if (partner.status === 'applied') {
        return (
            <p role="status" data-a="partner:status:applied" className="mt-3 rounded-tile bg-status-info-bg px-3 py-2 text-[13px] text-status-info">
                Your application is waiting for review. You can prepare your page now; it goes public once you are approved.
            </p>
        );
    }

    if (partner.status === 'suspended') {
        return (
            <p role="status" data-a="partner:status:suspended" className="mt-3 rounded-tile bg-status-danger-bg px-3 py-2 text-[13px] text-status-danger">
                Your partner account is suspended{partner.suspend_reason ? `: ${partner.suspend_reason}` : '.'} Your page is offline, your links do not earn, and your balance is frozen.
            </p>
        );
    }

    if (partner.status === 'declined') {
        return (
            <p role="status" className="mt-3 rounded-tile bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                Your application was not approved. <Link href={route('partners.apply')} className="font-semibold underline">Update and send it again</Link>.
            </p>
        );
    }

    if (partner.restricted) {
        return (
            <p className="mt-3 text-[12.5px] text-text-secondary">
                You were invited by an author: you can sell their books. Écrivain can open the whole catalogue to you later.
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

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Tile label="Visits" value={stats.clicks.toLocaleString()} detail={`${stats.clicks_recent} in the last ${stats.days} days`} dataA="partner:stat:clicks" />
                <Tile label="Orders" value={stats.orders.toLocaleString()} detail={`${stats.orders_recent} in the last ${stats.days} days`} dataA="partner:stat:orders" />
                <Tile label="Conversion" value={stats.conversion === null ? '—' : `${stats.conversion} %`} detail="orders per visit" />
                <Tile label="Balance" value={money(main.balance_cents, main.currency)} detail={next?.eligible ? 'ready to be paid' : `paid from ${money(minimum, main.currency)}`} dataA="partner:stat:balance" />
            </div>

            {balances.map((b) => (
                <div key={b.currency} className="grid grid-cols-3 gap-3" data-a={`partner:earnings:${b.currency}`}>
                    <Tile label={`Pending (${holdDays}-day hold)`} value={money(b.pending_cents, b.currency)} dataA="partner:pending" />
                    <Tile label="Approved" value={money(b.earned_cents - b.paid_cents, b.currency)} />
                    <Tile label="Paid" value={money(b.paid_cents, b.currency)} />
                </div>
            ))}

            {next && !next.eligible && main.balance_cents > 0 && (
                <p className="text-[12.5px] text-text-secondary">Next payout: {next.reason}</p>
            )}

            <section className="rounded-panel border border-border-default bg-bg-surface p-4">
                <h2 className="text-[14px] font-semibold text-text-primary">Visits, last 30 days</h2>
                <div className="mt-3 flex h-24 items-end gap-[2px]" role="img" aria-label={`Visits per day: ${timeline.map((d) => d.clicks).join(', ')}`}>
                    {timeline.map((d) => (
                        <span
                            key={d.day}
                            title={`${d.day}: ${d.clicks} visits, ${d.orders} orders`}
                            className={`min-w-0 flex-1 rounded-t-[2px] ${d.orders > 0 ? 'bg-brand-primary' : 'bg-brand-fill/40'}`}
                            style={{ height: `${d.clicks === 0 ? 2 : Math.max(6, (d.clicks / max) * 96)}px` }}
                        />
                    ))}
                </div>
                <p className="mt-2 text-[11px] text-text-secondary">Darker bars are days with an order. Each visitor is counted once a day.</p>
            </section>
        </div>
    );
}

function Links({ page, catalogue, partner, cookie_days: cookieDays }) {
    const [query, setQuery] = useState('');
    const books = catalogue.filter((b) => !query || b.title.toLowerCase().includes(query.toLowerCase()));

    return (
        <div className="space-y-4">
            <ShareBar page={page} />
            <p className="text-[12.5px] text-text-secondary">
                Anybody who follows one of your links has {cookieDays} days to buy, and the credit is yours unless they follow another
                partner&rsquo;s link after yours. Your code <span className="font-mono font-semibold text-text-primary">{partner.code}</span>{' '}
                also works on any book: add <span className="font-mono">?partner={partner.code}</span> to its address.
            </p>

            <section className="rounded-panel border border-border-default bg-bg-surface p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-[14px] font-semibold text-text-primary">A link for one book</h2>
                    <input type="search" aria-label="Find a book" placeholder="Find a book" value={query} onChange={(e) => setQuery(e.target.value)} className={`${field} max-w-[240px]`} />
                </div>
                {books.length === 0 ? (
                    <p className="mt-3 text-[13px] text-text-secondary">No books are open to partners yet.</p>
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
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(book.buy_url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copy link', book.buy_url);
        }
    };
    const qr = (format) => `${route('partners.page.qr', [partner.handle, format])}?book=${encodeURIComponent(book.slug)}`;

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5" data-a="partner:link">
            <img src={book.cover_url} alt="" className="h-12 w-8 shrink-0 rounded-[3px] bg-bg-subtle object-cover" loading="lazy" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-[13.5px] font-semibold text-text-primary">{book.title}</p>
                <p className="text-[11.5px] text-text-secondary">
                    {money(book.price_cents, book.currency)} · you earn {book.rate} % of the net
                </p>
            </div>
            <div className="flex flex-wrap gap-1.5">
                <button type="button" onClick={copy} data-a="partner:link:copy" className="tap min-h-[36px] rounded-button border border-border-default px-2.5 text-[12px] font-semibold text-text-primary hover:bg-bg-subtle">
                    {copied ? 'Copied' : 'Copy link'}
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
            <div className="mb-3 flex gap-1 rounded-control bg-bg-subtle p-1 lg:hidden" role="tablist" aria-label="Edit or preview">
                {['edit', 'preview'].map((v) => (
                    <button key={v} type="button" role="tab" aria-selected={view === v} data-a={`partner:editor:${v}`} onClick={() => setView(v)} className={`tap min-h-[38px] flex-1 rounded-control text-[13px] font-semibold ${view === v ? 'bg-bg-surface text-text-primary shadow-sm' : 'text-text-secondary'}`}>
                        {v === 'edit' ? 'Edit' : 'Preview'}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
                <form onSubmit={save} data-a="partner:editor" className={`min-w-0 space-y-4 rounded-panel border border-border-default bg-bg-surface p-4 ${view === 'edit' ? '' : 'max-lg:hidden'}`}>
                    <Row label="Your page address" htmlFor="pe-handle" error={form.errors.handle ?? handleProblem} hint={partner.handle_change_from ? `You can change it once every 30 days; the old address keeps working.` : 'Lowercase letters, digits and hyphens. The old address keeps working if you change it.'}>
                        <div className="flex min-w-0 items-center gap-1">
                            <span className="shrink-0 text-[12.5px] text-text-secondary">/p/</span>
                            <input id="pe-handle" data-a="partner:editor:handle" maxLength={30} value={d.handle} onChange={(e) => form.setData('handle', e.target.value.toLowerCase())} onBlur={(e) => checkHandle(e.target.value)} className={field} />
                        </div>
                    </Row>
                    <Row label="Name" htmlFor="pe-name" error={form.errors.display_name}>
                        <input id="pe-name" data-a="partner:editor:name" maxLength={80} required value={d.display_name} onChange={(e) => form.setData('display_name', e.target.value)} className={field} />
                    </Row>
                    <Row label={`Tagline (${80 - d.tagline.length} left)`} htmlFor="pe-tagline" error={form.errors.tagline}>
                        <input id="pe-tagline" data-a="partner:editor:tagline" maxLength={80} value={d.tagline} onChange={(e) => form.setData('tagline', e.target.value)} className={field} />
                    </Row>
                    <Row label={`About you (${600 - d.bio.length} left)`} htmlFor="pe-bio" error={form.errors.bio} hint="Plain text. Line breaks are kept.">
                        <textarea id="pe-bio" data-a="partner:editor:bio" rows={4} maxLength={600} value={d.bio} onChange={(e) => form.setData('bio', e.target.value)} className={field} />
                    </Row>

                    <div className="grid grid-cols-2 gap-3">
                        <Row label="Accent colour" htmlFor="pe-accent" error={form.errors.accent} hint="Text on it is chosen to stay readable.">
                            <div className="flex items-center gap-2">
                                <input id="pe-accent" type="color" value={d.accent} onChange={(e) => form.setData('accent', e.target.value)} className="h-10 w-12 shrink-0 cursor-pointer rounded-control border border-border-input bg-bg-surface" />
                                <input aria-label="Accent colour as hex" maxLength={7} value={d.accent} onChange={(e) => form.setData('accent', e.target.value)} className={`${field} font-mono`} />
                            </div>
                        </Row>
                        <Row label="Page language" htmlFor="pe-locale" error={form.errors.page_locale} hint={d.page_locale === 'ht' ? 'Kreyòl wording is still being reviewed.' : null}>
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
                        <p className="text-[13px] font-medium text-text-primary">Logo or photo</p>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <input ref={logo} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={upload} data-a="partner:editor:logo" />
                            <button type="button" onClick={() => logo.current?.click()} className="tap min-h-[38px] rounded-button border border-border-default px-3 text-[12.5px] font-semibold text-text-primary hover:bg-bg-subtle">
                                Upload an image
                            </button>
                            {page.logo_url && (
                                <button type="button" onClick={() => router.delete(route('partners.logo.destroy'), { preserveScroll: true })} className="tap min-h-[38px] rounded-button px-3 text-[12.5px] text-text-secondary hover:bg-bg-subtle">
                                    Remove
                                </button>
                            )}
                        </div>
                        <p className="mt-1 text-[11.5px] text-text-secondary">JPEG, PNG or WebP, up to 4 MB. It is cropped to a square.</p>
                        {pageErrors.logo && <p className="mt-1 text-[12px] text-status-danger">{pageErrors.logo}</p>}
                    </div>

                    <fieldset>
                        <legend className="text-[13px] font-medium text-text-primary">Links (up to 6)</legend>
                        {d.socials.map((s, i) => (
                            <div key={i} className="mt-1.5 flex gap-1.5">
                                <select aria-label="Kind of link" value={s.kind} onChange={(e) => form.setData('socials', d.socials.map((x, j) => (j === i ? { ...x, kind: e.target.value } : x)))} className={`${field} w-[120px] shrink-0`}>
                                    {socialKinds.map((k) => (
                                        <option key={k} value={k}>
                                            {k}
                                        </option>
                                    ))}
                                </select>
                                <input aria-label="Address" placeholder={s.kind === 'whatsapp' ? '+509 … or https://' : 'https://'} value={s.value} onChange={(e) => form.setData('socials', d.socials.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} className={`${field} min-w-0`} />
                                <button type="button" aria-label="Remove link" onClick={() => form.setData('socials', d.socials.filter((_, j) => j !== i))} className="tap shrink-0 rounded-control px-2 text-text-secondary hover:bg-bg-subtle">
                                    ×
                                </button>
                            </div>
                        ))}
                        {Object.entries(form.errors).filter(([k]) => k.startsWith('socials')).map(([k, v]) => (
                            <p key={k} className="mt-1 text-[12px] text-status-danger">{v}</p>
                        ))}
                        {d.socials.length < 6 && (
                            <button type="button" onClick={() => form.setData('socials', [...d.socials, { kind: 'website', value: '' }])} className="mt-1.5 text-[12.5px] font-semibold text-brand-primary hover:underline">
                                Add a link
                            </button>
                        )}
                    </fieldset>

                    <fieldset data-a="partner:shelf">
                        <legend className="text-[13px] font-medium text-text-primary">
                            Books on your page ({d.shelf.length}/{shelfMax})
                        </legend>
                        <ol className="mt-1.5 space-y-2">
                            {d.shelf.map((item, i) => {
                                const book = bySlug[item.product];

                                return (
                                    <li key={item.product} className="rounded-tile border border-border-default p-2.5" data-a="partner:shelf:item">
                                        <div className="flex items-center gap-2">
                                            <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-text-primary">{book?.title ?? 'No longer available'}</span>
                                            <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)} className="tap h-8 w-8 rounded-control text-text-secondary hover:bg-bg-subtle disabled:opacity-30">↑</button>
                                            <button type="button" aria-label="Move down" disabled={i === d.shelf.length - 1} onClick={() => move(i, 1)} className="tap h-8 w-8 rounded-control text-text-secondary hover:bg-bg-subtle disabled:opacity-30">↓</button>
                                            <button type="button" aria-label="Remove from page" onClick={() => form.setData('shelf', d.shelf.filter((_, j) => j !== i))} className="tap h-8 w-8 rounded-control text-text-secondary hover:bg-bg-subtle">×</button>
                                        </div>
                                        <textarea aria-label={`Your note on ${book?.title ?? 'this book'}`} placeholder="Why you recommend it (optional)" rows={2} maxLength={200} value={item.note ?? ''} onChange={(e) => form.setData('shelf', d.shelf.map((x, j) => (j === i ? { ...x, note: e.target.value } : x)))} className={`${field} mt-1.5 text-[12.5px]`} />
                                    </li>
                                );
                            })}
                        </ol>
                        {form.errors.shelf && <p className="mt-1 text-[12px] text-status-danger">{form.errors.shelf}</p>}
                        {d.shelf.length < shelfMax && available.length > 0 && (
                            <select
                                aria-label="Add a book"
                                data-a="partner:shelf:add"
                                value=""
                                onChange={(e) => e.target.value && form.setData('shelf', [...d.shelf, { product: e.target.value, note: '' }])}
                                className={`${field} mt-2`}
                            >
                                <option value="">Add a book…</option>
                                {available.map((b) => (
                                    <option key={b.slug} value={b.slug}>
                                        {b.title}
                                        {b.author ? ` — ${b.author}` : ''} ({b.rate} %)
                                    </option>
                                ))}
                            </select>
                        )}
                        {catalogue.length === 0 && <p className="mt-1.5 text-[12px] text-text-secondary">No books are open to partners yet.</p>}
                    </fieldset>

                    <button type="submit" data-a="partner:editor:save" disabled={form.processing} className="tap min-h-[44px] w-full rounded-button bg-brand-primary px-5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60">
                        {form.processing ? 'Saving…' : 'Save my page'}
                    </button>
                </form>

                <div className={`min-w-0 ${view === 'preview' ? '' : 'max-lg:hidden'}`} data-a="partner:live-preview">
                    <p className="mb-2 text-[11.5px] font-semibold uppercase tracking-[0.08em] text-text-secondary">Live preview</p>
                    <div className="rounded-panel border border-dashed border-border-default bg-bg-app p-3 sm:p-4">
                        <PartnerPageView page={preview} interactive={false} />
                    </div>
                </div>
            </div>
        </div>
    );
}

function Commissions({ commissions, hold_days: holdDays }) {
    if (commissions.length === 0) {
        return <p className="rounded-panel border border-border-default bg-bg-surface p-4 text-[13px] text-text-secondary" data-a="partner:commissions:none">No commissions yet. They appear here the moment a reader pays.</p>;
    }

    return (
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
                                {c.status === 'pending' && c.hold_until && ` · payable from ${c.hold_until}`}
                                {c.note && ` · ${c.note}`}
                            </span>
                        </span>
                        <span className="flex items-center gap-2 tabular-nums">
                            <span className={c.net_cents < 0 ? 'text-status-danger' : 'text-text-primary'}>{money(c.net_cents, c.currency)}</span>
                            <StatusBadge status={c.status} />
                        </span>
                    </li>
                ))}
            </ul>
            <p className="mt-2 px-2 text-[11px] text-text-secondary">
                Pending commissions wait {holdDays} days (the refund window). A refund reduces a commission in proportion; one refunded after it was paid comes off your next earnings.
            </p>
        </section>
    );
}

function StatusBadge({ status }) {
    const tone = {
        pending: 'bg-status-info-bg text-status-info',
        approved: 'bg-status-success-bg text-status-success',
        paid: 'bg-bg-subtle text-text-secondary',
        reversed: 'bg-status-warning-bg text-status-warning',
    }[status] ?? 'bg-bg-subtle text-text-secondary';

    return <span className={`rounded-badge px-1.5 py-0.5 text-[10.5px] font-semibold ${tone}`} data-a={`partner:status-badge:${status}`}>{status}</span>;
}

function Payouts({ partner, balances, eligibility, payouts, payout_methods: methods, minimum_cents: minimum }) {
    const form = useForm({ payout_method: partner.payout_method ?? methods[0].key, payout_details: partner.payout_details ?? '' });

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section className="rounded-panel border border-border-default bg-bg-surface p-4">
                <h2 className="text-[14px] font-semibold text-text-primary">Balance</h2>
                {balances.length === 0 ? (
                    <p className="mt-2 text-[13px] text-text-secondary">Nothing earned yet.</p>
                ) : (
                    <ul className="mt-2 space-y-2">
                        {balances.map((b) => (
                            <li key={b.currency} className="text-[13px]">
                                <span className="font-display text-[20px] font-extrabold tabular-nums text-text-primary">{money(b.balance_cents, b.currency)}</span>
                                <span className="ml-2 text-text-secondary">{eligibility[b.currency]?.eligible ? 'will be paid in the next run' : eligibility[b.currency]?.reason}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-2 text-[11.5px] text-text-secondary">
                    Écrivain pays you outside the app once your balance reaches {money(minimum, balances[0]?.currency ?? 'usd')}. You are emailed each time.
                </p>

                <h3 className="mt-5 text-[13px] font-semibold text-text-primary">Payouts</h3>
                {payouts.length === 0 ? (
                    <p className="mt-1 text-[12.5px] text-text-secondary">None yet.</p>
                ) : (
                    <ul className="mt-1 divide-y divide-border-divider text-[12.5px]" data-a="partner:payouts">
                        {payouts.map((p) => (
                            <li key={p.id} className="flex justify-between gap-2 py-1.5">
                                <span>
                                    {p.paid_on} · {p.method}
                                    {p.reference ? ` · ${p.reference}` : ''}
                                </span>
                                <span className="tabular-nums font-semibold text-text-primary">{money(p.amount_cents, p.currency)}</span>
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
                <h2 className="text-[14px] font-semibold text-text-primary">Where to pay you</h2>
                <fieldset className="space-y-1.5">
                    <legend className="sr-only">Method</legend>
                    {methods.map((m) => (
                        <label key={m.key} className="flex min-h-[40px] items-center gap-2 rounded-tile border border-border-default px-3 text-[13px]">
                            <input type="radio" name="payout_method" value={m.key} checked={form.data.payout_method === m.key} onChange={() => form.setData('payout_method', m.key)} />
                            {m.label}
                        </label>
                    ))}
                    <p className="flex min-h-[40px] items-center gap-2 rounded-tile border border-dashed border-border-default px-3 text-[13px] text-text-secondary">
                        Stripe Connect — coming soon
                    </p>
                </fieldset>
                <Row label={form.data.payout_method === 'paypal' ? 'PayPal email address' : form.data.payout_method === 'moncash' ? 'MonCash phone number' : 'Bank transfer note (account name, bank, reference)'} htmlFor="pd-details" error={form.errors.payout_details}>
                    <textarea id="pd-details" data-a="partner:payout-details:value" rows={form.data.payout_method === 'bank_transfer' ? 3 : 1} maxLength={500} value={form.data.payout_details} onChange={(e) => form.setData('payout_details', e.target.value)} className={field} />
                </Row>
                <p className="text-[11.5px] text-text-secondary">Stored encrypted. Écrivain never asks for a tax id here; if one is needed we will write to you.</p>
                <button type="submit" disabled={form.processing} className="tap min-h-[42px] rounded-button bg-brand-primary px-4 text-[13.5px] font-semibold text-white disabled:opacity-60">
                    Save payout details
                </button>
            </form>
        </div>
    );
}

function AgreementTab({ partner, agreement }) {
    return (
        <div className="space-y-3">
            <p className="text-[13px] text-text-body">
                You accepted version <strong>{partner.agreement_version}</strong>
                {partner.agreement_accepted_at && ` on ${new Date(partner.agreement_accepted_at).toLocaleDateString()}`}.
                {partner.agreement_version !== agreement.version && ' A newer version exists; you will be asked to accept it.'}
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
