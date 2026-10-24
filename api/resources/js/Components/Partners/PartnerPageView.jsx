import ReportLink from '@/Components/Store/ReportLink';
import { money } from '@/Components/Store/money';
import { useState } from 'react';

/**
 * A partner's branded page: the public /p/{handle}, and the live preview in
 * the partner's own page editor (the same component, so the preview is the
 * page).
 *
 * Everything the partner typed is rendered as text — React escapes it, and
 * the bio keeps its line breaks through CSS, never HTML. Links come from
 * the server already checked to be https. The disclosure is part of the
 * page itself, not of anything the partner edits, so it cannot be removed.
 *
 * The chrome speaks the page's language. French and Haitian Creole are
 * drafts: the Kreyòl strings in particular need a native speaker's review
 * before launch (docs/PARTNERS.md).
 */
export const CHROME = {
    en: {
        books: 'Books I recommend',
        empty: 'No books here yet.',
        buy: 'Buy',
        share: 'Share this page',
        copy: 'Copy link',
        copied: 'Link copied',
        whatsapp: 'Share on WhatsApp',
        qr: 'QR code',
        report: 'Report this page',
        partner: 'Écrivain Partner',
        preview: 'Preview — this page is not public yet.',
        unavailable: 'This page is not available.',
        unavailableDetail: 'The link may be old, or the page may have been taken offline.',
        browse: 'Browse the Écrivain store',
        note: 'Why I recommend it',
    },
    fr: {
        books: 'Les livres que je recommande',
        empty: 'Aucun livre pour le moment.',
        buy: 'Acheter',
        share: 'Partager cette page',
        copy: 'Copier le lien',
        copied: 'Lien copié',
        whatsapp: 'Partager sur WhatsApp',
        qr: 'Code QR',
        report: 'Signaler cette page',
        partner: 'Partenaire Écrivain',
        preview: 'Aperçu — cette page n’est pas encore publique.',
        unavailable: 'Cette page n’est pas disponible.',
        unavailableDetail: 'Le lien est peut-être ancien, ou la page a été mise hors ligne.',
        browse: 'Voir la librairie Écrivain',
        note: 'Pourquoi je le recommande',
    },
    // Draft — needs a native Haitian Creole review.
    ht: {
        books: 'Liv mwen rekòmande',
        empty: 'Poko gen liv la a.',
        buy: 'Achte',
        share: 'Pataje paj sa a',
        copy: 'Kopye lyen an',
        copied: 'Lyen an kopye',
        whatsapp: 'Pataje sou WhatsApp',
        qr: 'Kòd QR',
        report: 'Siyale paj sa a',
        partner: 'Patnè Écrivain',
        preview: 'Apèsi — paj sa a poko piblik.',
        unavailable: 'Paj sa a pa disponib.',
        unavailableDetail: 'Petèt lyen an twò ansyen, oswa yo retire paj la.',
        browse: 'Gade libreri Écrivain an',
        note: 'Poukisa mwen rekòmande l',
    },
};

const SOCIAL_LABELS = {
    website: 'Website',
    facebook: 'Facebook',
    instagram: 'Instagram',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    x: 'X',
    whatsapp: 'WhatsApp',
    other: 'Link',
};

export default function PartnerPageView({ page, preview = false, interactive = true }) {
    const t = CHROME[page.locale] ?? CHROME.en;
    const accent = page.accent;
    const ink = page.accent_ink;

    return (
        <div data-a="partner:page" lang={page.locale === 'ht' ? 'ht' : page.locale} className="min-w-0">
            {preview && (
                <p role="status" data-a="partner:preview" className="mb-4 rounded-tile bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning">
                    {t.preview}
                </p>
            )}

            <header className="overflow-hidden rounded-panel border border-border-default bg-bg-surface">
                <div className="h-20 sm:h-24" style={{ backgroundColor: accent }} aria-hidden="true" />
                <div className="px-4 pb-5 sm:px-6">
                    <div className="-mt-10 flex flex-wrap items-end gap-3">
                        {page.logo_url ? (
                            <img
                                src={page.logo_url}
                                alt=""
                                width={80}
                                height={80}
                                className="h-20 w-20 shrink-0 rounded-full border-4 border-bg-surface bg-bg-surface object-cover"
                            />
                        ) : (
                            <span
                                aria-hidden="true"
                                className="grid h-20 w-20 shrink-0 place-items-center rounded-full border-4 border-bg-surface font-display text-[28px] font-extrabold"
                                style={{ backgroundColor: accent, color: ink }}
                            >
                                {initials(page.name)}
                            </span>
                        )}
                        <p className="mb-1 rounded-badge bg-bg-subtle px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{t.partner}</p>
                    </div>
                    <h1 data-a="partner:name" className="mt-3 break-words font-display text-[clamp(24px,5vw,34px)] font-extrabold leading-tight tracking-[-0.02em] text-text-primary">
                        {page.name}
                    </h1>
                    {page.tagline && (
                        <p data-a="partner:tagline" className="mt-1 break-words text-[15px] text-text-secondary">
                            {page.tagline}
                        </p>
                    )}
                    {page.bio && (
                        <p data-a="partner:bio" className="mt-3 whitespace-pre-line break-words text-[14px] leading-relaxed text-text-body">
                            {page.bio}
                        </p>
                    )}
                    {page.socials?.length > 0 && (
                        <ul className="mt-3 flex flex-wrap gap-2">
                            {page.socials.map((link, i) => (
                                <li key={i}>
                                    <a
                                        href={link.url}
                                        target="_blank"
                                        rel="noopener noreferrer nofollow"
                                        className="tap inline-flex min-h-[36px] items-center rounded-button border border-border-default px-3 text-[12.5px] font-medium text-text-primary hover:bg-bg-subtle"
                                    >
                                        {SOCIAL_LABELS[link.kind] ?? 'Link'}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                    {/* Required, and not something the partner edits. */}
                    <p data-a="partner:disclosure" className="mt-4 rounded-tile bg-bg-subtle px-3 py-2 text-[12px] leading-snug text-text-secondary">
                        {page.disclosure}
                    </p>
                </div>
            </header>

            <section className="mt-6">
                <h2 className="mb-3 text-[15px] font-semibold text-text-primary">{t.books}</h2>
                {page.books.length === 0 ? (
                    <p className="rounded-tile border border-border-default bg-bg-surface p-4 text-[13px] text-text-secondary">{t.empty}</p>
                ) : (
                    <ul className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-3">
                        {page.books.map((book) => (
                            <li key={book.slug} data-a="partner:book" className="flex min-w-0 gap-3 rounded-panel border border-border-default bg-bg-surface p-3">
                                <img src={book.cover_url} alt="" loading="lazy" className="aspect-[2/3] w-[84px] shrink-0 self-start rounded-tile bg-bg-subtle object-cover" />
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <p className="break-words text-[14px] font-semibold leading-snug text-text-primary">{book.title}</p>
                                    {book.author && <p className="text-[12px] text-text-secondary">{book.author}</p>}
                                    {book.note && (
                                        <p data-a="partner:note" className="mt-1.5 whitespace-pre-line break-words text-[12.5px] italic leading-snug text-text-body">
                                            <span className="sr-only">{t.note}: </span>“{book.note}”
                                        </p>
                                    )}
                                    <div className="mt-auto flex flex-wrap items-center justify-between gap-2 pt-2">
                                        <span className="text-[13px] font-semibold tabular-nums text-text-primary">
                                            {money(book.price_cents, book.currency)}
                                            <span className="ml-1 text-[11px] font-normal text-text-secondary">{book.formats.join(' + ')}</span>
                                        </span>
                                        <a
                                            href={interactive ? book.buy_url : undefined}
                                            data-a="partner:buy"
                                            aria-disabled={interactive ? undefined : 'true'}
                                            className="tap inline-flex min-h-[36px] items-center rounded-button px-3.5 text-[13px] font-semibold"
                                            style={{ backgroundColor: accent, color: ink }}
                                        >
                                            {t.buy}
                                        </a>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {interactive && <ShareBar page={page} t={t} />}

            {interactive && (
                <p className="mt-6 text-[11.5px] text-text-secondary">
                    <ReportLink url={page.report_url} label={t.report} />
                </p>
            )}
        </div>
    );
}

export function ShareBar({ page, t = CHROME.en, url = null, text = null }) {
    const link = url ?? page.url;
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(link);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt(t.copy, link);
        }
    };

    const whatsapp = `https://wa.me/?text=${encodeURIComponent(`${text ?? page.name} ${link}`)}`;

    return (
        <section data-a="partner:share" className="mt-6 rounded-panel border border-border-default bg-bg-surface p-4">
            <h2 className="text-[14px] font-semibold text-text-primary">{t.share}</h2>
            <p className="mt-1 break-all text-[12px] text-text-secondary">{link}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                <button type="button" data-a="partner:copy" onClick={copy} className="tap min-h-[40px] rounded-button border border-border-default px-3.5 text-[13px] font-semibold text-text-primary hover:bg-bg-subtle">
                    {copied ? t.copied : t.copy}
                </button>
                <a href={whatsapp} target="_blank" rel="noopener noreferrer" data-a="partner:whatsapp" className="tap inline-flex min-h-[40px] items-center rounded-button border border-border-default px-3.5 text-[13px] font-semibold text-text-primary hover:bg-bg-subtle">
                    {t.whatsapp}
                </a>
                {page.qr_png && (
                    <a href={page.qr_png} download data-a="partner:qr-png" className="tap inline-flex min-h-[40px] items-center rounded-button border border-border-default px-3.5 text-[13px] font-semibold text-text-primary hover:bg-bg-subtle">
                        {t.qr} (PNG)
                    </a>
                )}
                {page.qr_svg && (
                    <a href={page.qr_svg} download data-a="partner:qr-svg" className="tap inline-flex min-h-[40px] items-center rounded-button border border-border-default px-3.5 text-[13px] font-semibold text-text-primary hover:bg-bg-subtle">
                        {t.qr} (SVG)
                    </a>
                )}
            </div>
        </section>
    );
}

function initials(name) {
    return String(name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');
}
