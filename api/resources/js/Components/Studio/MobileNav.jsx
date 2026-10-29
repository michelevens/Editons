import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useI18n } from '../../i18n';
import Dialog, { CloseButton } from '../Dialog';
import { useTyping } from '../Editor/useKeyboard';
import Icon from './Icon';
import { goTo } from './navigation';
import { NAV_FEATURES, useFeatures } from '../../features';
import { PREFS_REGISTRY, savePrefs, usePrefs } from '../../shortcuts';

/**
 * The tab bar along the bottom of a phone or tablet.
 *
 * Below 1024px the header hides its nav and the book sidebar becomes a
 * drawer, which left every area of the studio two taps behind a hamburger,
 * and the editor reached its binder and AI panel through pills that floated
 * over the text. A thumb expects the places it goes most at the bottom edge,
 * so they are here, on every signed-in page, in the same order; everything
 * else is one tap away under More.
 *
 * It is a row of the page's flex column, not a fixed overlay, so nothing
 * scrolls underneath it and no page has to pad itself to stay visible.
 *
 * The four tabs follow where the author is, the way a phone app's bar does:
 * writing, reviewing, publishing and selling each put their own screens
 * under the thumb, and every book set keeps a way back to the manuscript.
 * More is always last and always holds everything.
 *
 * An author can pin their own tabs (one to four) for each of those places,
 * from "Edit tabs" in More; they are saved on the account (ADR —
 * Configurable shortcuts and mobile tabs) and the server checks them
 * against resources/js/prefs.json. Without a choice, the sets below.
 *
 * `current` is the screen on show (a pane key such as 'manuscript' or
 * 'cover', or an account page). A page that switches panes in place (the
 * editor) passes onSelect; any other page lets the bar navigate.
 */
const T = {
    books: { label: 'Books', tKey: 'tab.books', icon: 'lib' },
    continue: { label: 'Write', tKey: 'tab.continue', icon: 'doc' },
    'store-sales': { label: 'Sales', tKey: 'tab.store_sales', icon: 'sales' },
    inbox: { label: 'Inbox', tKey: 'tab.inbox', icon: 'bell' },
    overview: { label: 'Overview', tKey: 'tab.overview', icon: 'home' },
    manuscript: { label: 'Write', tKey: 'tab.manuscript', icon: 'doc' },
    ai: { label: 'AI Editor', tKey: 'tab.ai', icon: 'sugg' },
    research: { label: 'Research', tKey: 'tab.research', icon: 'lib' },
    comments: { label: 'Comments', tKey: 'tab.comments', icon: 'chat' },
    workflow: { label: 'Workflow', tKey: 'tab.workflow', icon: 'flow' },
    layout: { label: 'Design', tKey: 'tab.layout', icon: 'layout' },
    cover: { label: 'Cover', tKey: 'tab.cover', icon: 'cover' },
    export: { label: 'Export', tKey: 'tab.export', icon: 'exp' },
    publish: { label: 'Publish', tKey: 'tab.publish', icon: 'rocket' },
    distribution: { label: 'Sales', tKey: 'tab.distribution', icon: 'sales' },
    launch: { label: 'Launch', tKey: 'tab.launch', icon: 'rocket' },
    analytics: { label: 'Analytics', tKey: 'tab.analytics', icon: 'chart' },
    // Écrivain Partners: the dashboard's own tabs, on its pages.
    'partner-overview': { label: 'Partner', tKey: 'tab.partner_overview', icon: 'home' },
    'partner-links': { label: 'Links', tKey: 'tab.partner_links', icon: 'mega' },
    'partner-page': { label: 'My page', tKey: 'tab.partner_page', icon: 'layout' },
    'partner-earnings': { label: 'Earnings', tKey: 'tab.partner_earnings', icon: 'coin' },
    // Only as pinned tabs: the rest of More.
    billing: { label: 'Billing', tKey: 'tab.billing', icon: 'card' },
    purchases: { label: 'Purchases', tKey: 'tab.purchases', icon: 'doc' },
    account: { label: 'Account', tKey: 'tab.account', icon: 'user' },
    support: { label: 'Help', tKey: 'tab.support', icon: 'help' },
    terms: { label: 'Terms', tKey: 'tab.terms', icon: 'users' },
    references: { label: 'Citations', tKey: 'tab.references', icon: 'refs' },
    notes: { label: 'Notes', tKey: 'tab.notes', icon: 'note' },
    templates: { label: 'Templates', tKey: 'tab.templates', icon: 'tmpl' },
    settings: { label: 'Settings', tKey: 'tab.settings', icon: 'gear' },
    team: { label: 'Team', tKey: 'tab.team', icon: 'users' },
    preview: { label: 'Preview', tKey: 'tab.preview', icon: 'eye' },
    rights: { label: 'ISBN', tKey: 'tab.rights', icon: 'hash' },
    editions: { label: 'Editions', tKey: 'tab.editions', icon: 'copies' },
    a11y: { label: 'Access', tKey: 'tab.a11y', icon: 'access' },
    royalties: { label: 'Royalties', tKey: 'tab.royalties', icon: 'coin' },
    marketing: { label: 'Marketing', tKey: 'tab.marketing', icon: 'mega' },
};

const SETS = {
    // Billing lives under More's account list; the inbox is checked daily.
    library: ['books', 'continue', 'store-sales', 'inbox'],
    write: ['overview', 'manuscript', 'ai', 'research'],
    review: ['manuscript', 'ai', 'comments', 'workflow'],
    publish: ['manuscript', 'layout', 'cover', 'export'],
    sell: ['manuscript', 'distribution', 'launch', 'analytics'],
    partner: ['partner-overview', 'partner-links', 'partner-page', 'partner-earnings'],
};

/** Which set of tabs a screen belongs to. */
export function tabSet(current, hasBook) {
    if (String(current ?? '').startsWith('partner-')) return 'partner';
    if (!hasBook) return 'library';

    if (['ai', 'suggestions', 'comments', 'workflow', 'team'].includes(current)) return 'review';
    if (['layout', 'cover', 'export', 'publish', 'preview', 'rights', 'editions', 'a11y', 'design', 'publishing'].includes(current)) return 'publish';
    if (['distribution', 'royalties', 'launch', 'marketing', 'analytics', 'sales'].includes(current)) return 'sell';
    if (['books', 'inbox', 'billing', 'store-sales', 'purchases', 'account'].includes(current)) return 'library';

    return 'write';
}

export default function MobileNav({
    current = null,
    bookId = null,
    bookTitle = null,
    aiCount = 0,
    inboxCount = null,
    onSelect,
    onChapters,
    onAi,
}) {
    const { recent_book_id: recentBookId, inbox_unread: sharedUnread = 0 } = usePage().props;
    // The inbox page passes its own live count; elsewhere the shared one.
    const unread = inboxCount ?? sharedUnread ?? 0;
    // On a book's own pages the tabs are that book's. On the shelf and the
    // account pages they are the library's, with "Write" reopening the book
    // last worked on.
    const book = bookId ?? recentBookId ?? null;
    const set = tabSet(current, Boolean(bookId));
    const on = useFeatures();
    const prefs = usePrefs();
    // The author's pinned tabs for this place, or the set it always had. A
    // module switched off for this account leaves the bar (App\Platform\Features).
    const tabs = (prefs.mobileTabs[set] ?? SETS[set]).filter((key) => T[key] && (key !== 'continue' || book) && on(NAV_FEATURES[key]));
    const [sheet, setSheet] = useState(false);
    // Out of the way while the keyboard is up: the keys, the formatting
    // bar and the text are what a phone has room for then.
    const typing = useTyping();
    const { t } = useI18n();

    // A screen of the book: the editor switches panes in place, any other
    // page opens the editor on it.
    const open = (key) => {
        setSheet(false);
        (onSelect ?? ((k) => goTo(book, k)))(key);
    };

    const go = (key) => {
        if (key === 'books') return router.get(route('books.index'));
        if (key === 'inbox') return router.get(route('inbox.index'));
        if (key === 'store-sales') return router.get(route('sales.overview'));
        if (key === 'continue') return goTo(book, 'manuscript');
        if (key === 'billing') return router.get(route('billing.show'));
        if (key === 'purchases') return router.get(route('purchases.index'));
        if (key === 'account') return router.get(route('profile.edit'));
        if (key === 'support') return router.get(route('support.index'));
        if (key.startsWith('partner-')) {
            const tab = { 'partner-overview': 'overview', 'partner-links': 'links', 'partner-page': 'page', 'partner-earnings': 'commissions' }[key];

            return onSelect ? onSelect(key) : router.get(route('partners.dashboard', { tab }));
        }

        // Write while writing: the chapter list, which is what a second tap
        // on the tab you are on means in most apps.
        if (key === 'manuscript' && current === 'manuscript' && onChapters) return onChapters();

        // AI beside the manuscript: this chapter's findings in a drawer.
        if (key === 'ai' && current === 'manuscript' && onAi) return onAi();

        return open(key);
    };

    return (
        <>
            <nav
                aria-label={t('tab.sections')}
                data-a="mobile:tabs"
                className={`safe-bottom shrink-0 border-t border-border-default bg-bg-surface lg:hidden ${typing ? 'hidden' : ''}`}
            >
                <ul className="mx-auto grid h-[58px] max-w-[640px] grid-cols-5">
                    {[...tabs, 'more'].map((key) => {
                        const tab = key === 'more' ? { label: 'More', tKey: 'tab.more', icon: 'menu' } : T[key];
                        const on = key === current || (key === 'ai' && current === 'suggestions');

                        return (
                            <li key={key} className="flex">
                                <button
                                    type="button"
                                    data-a={`mobile:${key}`}
                                    onClick={() => (key === 'more' ? setSheet(true) : go(key))}
                                    aria-current={on ? 'page' : undefined}
                                    aria-haspopup={key === 'more' ? 'dialog' : undefined}
                                    className={`relative flex flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium transition ${
                                        on ? 'text-brand-primary' : 'text-text-secondary hover:text-text-primary'
                                    }`}
                                >
                                    <Icon name={tab.icon} size={21} strokeWidth={on ? 2.1 : 1.8} />
                                    <span>{t(tab.tKey)}</span>
                                    {key === 'inbox' && unread > 0 && (
                                        <span
                                            data-a="mobile:inbox-count"
                                            className="absolute left-1/2 top-1.5 ml-2 min-w-[18px] rounded-badge bg-brand-fill px-1 text-center text-[10.5px] font-semibold leading-[18px] text-white"
                                        >
                                            {unread > 99 ? '99+' : unread}
                                            <span className="sr-only"> {t('common.unread')}</span>
                                        </span>
                                    )}
                                    {key === 'ai' && aiCount > 0 && (
                                        <span className="absolute left-1/2 top-1.5 ml-2 min-w-[18px] rounded-badge bg-brand-fill px-1 text-center text-[10.5px] font-semibold leading-[18px] text-white">
                                            {aiCount > 99 ? '99+' : aiCount}
                                            <span className="sr-only"> {t('tab.open_findings')}</span>
                                        </span>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            {sheet && (
                <MoreSheet
                    book={book}
                    title={bookTitle}
                    context={set}
                    pinned={tabs}
                    onOpen={open}
                    onClose={() => setSheet(false)}
                />
            )}
        </>
    );
}

/**
 * More: every other place in the studio, as a grid of tiles in a sheet from
 * the bottom edge, the way a phone app's More tab works. On a desktop the
 * same places are the sidebar; this is that list arranged for a thumb.
 *
 * Without a book (a new account) only the account's own places are shown.
 */
const BOOK_GROUPS = [
    {
        title: 'Book',
        tKey: 'more_group.book',
        items: [
            { key: 'overview', label: 'Overview', tKey: 'more.overview', icon: 'home' },
            { key: 'manuscript', label: 'Manuscript', tKey: 'more.manuscript', icon: 'doc' },
            { key: 'research', label: 'Research', tKey: 'more.research', icon: 'lib' },
            { key: 'terms', label: 'Terms', tKey: 'more.terms', icon: 'users' },
            { key: 'references', label: 'Citations', tKey: 'more.references', icon: 'refs' },
            { key: 'notes', label: 'Notes', tKey: 'more.notes', icon: 'note' },
            { key: 'templates', label: 'Templates', tKey: 'more.templates', icon: 'tmpl' },
            { key: 'settings', label: 'Settings', tKey: 'more.settings', icon: 'gear' },
        ],
    },
    {
        title: 'Review',
        tKey: 'more_group.review',
        items: [
            { key: 'comments', label: 'Comments', tKey: 'more.comments', icon: 'chat' },
            { key: 'workflow', label: 'Workflow', tKey: 'more.workflow', icon: 'flow' },
            { key: 'team', label: 'Team', tKey: 'more.team', icon: 'users' },
            { key: 'analytics', label: 'Analytics', tKey: 'more.analytics', icon: 'chart' },
        ],
    },
    {
        title: 'Publish',
        tKey: 'more_group.publish',
        items: [
            { key: 'layout', label: 'Design', tKey: 'more.layout', icon: 'layout' },
            { key: 'cover', label: 'Cover', tKey: 'more.cover', icon: 'cover' },
            { key: 'publish', label: 'Publish', tKey: 'more.publish', icon: 'rocket' },
            { key: 'export', label: 'Export', tKey: 'more.export', icon: 'exp' },
            { key: 'preview', label: 'Preview', tKey: 'more.preview', icon: 'eye' },
            { key: 'rights', label: 'ISBN & Rights', tKey: 'more.rights', icon: 'hash' },
            { key: 'editions', label: 'Editions', tKey: 'more.editions', icon: 'copies' },
            { key: 'a11y', label: 'Accessibility', tKey: 'more.a11y', icon: 'access' },
        ],
    },
    {
        title: 'Sell & grow',
        tKey: 'more_group.sell',
        items: [
            { key: 'distribution', label: 'Sales', tKey: 'more.distribution', icon: 'sales' },
            { key: 'royalties', label: 'Royalties', tKey: 'more.royalties', icon: 'coin' },
            { key: 'launch', label: 'Launch', tKey: 'more.launch', icon: 'rocket' },
            { key: 'marketing', label: 'Marketing', tKey: 'more.marketing', icon: 'mega' },
        ],
    },
];

function MoreSheet({ book, title, context, pinned, onOpen, onClose }) {
    const on = useFeatures();
    const [editing, setEditing] = useState(false);
    const customisable = Boolean(PREFS_REGISTRY.mobileTabs[context]);
    const { t } = useI18n();
    const account = [
        { key: 'inbox', label: 'Inbox', tKey: 'menu.inbox', icon: 'bell', href: route('inbox.index') },
        { key: 'billing', label: 'Plans & billing', tKey: 'menu.billing', icon: 'card', href: route('billing.show') },
        { key: 'store-sales', label: 'Storefront sales', tKey: 'menu.store_sales', icon: 'sales', href: route('sales.overview') },
        { key: 'purchases', label: 'Your purchases', tKey: 'menu.purchases', icon: 'doc', href: route('purchases.index') },
        { key: 'partner', label: 'Partner program', tKey: 'menu.partner', icon: 'coin', href: route('partners.dashboard') },
        { key: 'account', label: 'Account settings', tKey: 'menu.account', icon: 'user', href: route('profile.edit') },
        { key: 'support', label: 'Help', tKey: 'menu.support', icon: 'help', href: route('support.index') },
    ].filter((link) => on(NAV_FEATURES[link.key]));

    return (
        <Dialog
            label={t('more.menu')}
            onClose={onClose}
            data-a="sheet:more"
            overlayClassName="!items-end !p-0 !pt-8"
            className="flex max-h-[88dvh] w-full max-w-[640px] flex-col rounded-t-[18px] bg-bg-app shadow-popover"
        >
            {/* The grab handle says "this slides up"; Escape, the close
                button and a tap above the sheet all close it. */}
            <div className="flex shrink-0 justify-center pt-2" aria-hidden="true">
                <span className="h-1 w-10 rounded-full bg-border-default" />
            </div>
            <div className="flex shrink-0 items-center gap-3 px-4 pb-2 pt-1">
                <div className="min-w-0 flex-1">
                    <p className="text-[16px] font-semibold text-text-primary">{t('more.menu')}</p>
                    {book && title && (
                        <p className="truncate text-[12.5px] text-text-secondary">{title}</p>
                    )}
                </div>
                {customisable && !editing && (
                    <button
                        type="button"
                        data-a="sheet:edit-tabs"
                        onClick={() => setEditing(true)}
                        className="rounded-control px-2 py-1.5 text-[13px] font-medium text-brand-primary hover:bg-bg-subtle"
                    >
                        {t('more.edit_tabs')}
                    </button>
                )}
                <CloseButton onClick={onClose} label={t('more.close')} className="h-10 w-10" />
            </div>

            {editing ? (
                <TabPicker context={context} pinned={pinned} hasBook={Boolean(book)} onDone={() => setEditing(false)} />
            ) : (

            <div className="safe-bottom min-h-0 overflow-y-auto px-3 pb-3">
                {book &&
                    BOOK_GROUPS.map((group) => (
                        <section key={group.title} className="mb-3 rounded-panel bg-bg-surface p-2">
                            <h3 className="px-2 pt-1 pb-1 text-[11.5px] font-semibold tracking-[0.08em] text-text-secondary uppercase">
                                {t(group.tKey)}
                            </h3>
                            <ul className="grid grid-cols-4">
                                {group.items.filter((item) => on(NAV_FEATURES[item.key])).map((item) => (
                                    <li key={item.key} className="flex">
                                        <button
                                            type="button"
                                            data-a={`sheet:${item.key}`}
                                            onClick={() => onOpen(item.key)}
                                            className="flex min-h-[76px] flex-1 flex-col items-center gap-1.5 rounded-control px-1 pt-2 text-center transition hover:bg-bg-subtle active:bg-bg-subtle"
                                        >
                                            <span className="grid h-10 w-10 place-items-center rounded-[12px] bg-brand-primary-subtle text-brand-primary">
                                                <Icon name={item.icon} size={20} />
                                            </span>
                                            <span className="text-[11.5px] leading-tight text-text-body">
                                                {t(item.tKey)}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}

                <section className="rounded-panel bg-bg-surface p-1">
                    <h3 className="px-3 pt-2 pb-1 text-[11.5px] font-semibold tracking-[0.08em] text-text-secondary uppercase">
                        {t('menu.account_label')}
                    </h3>
                    <ul>
                        {account.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    data-a={`sheet:account:${link.key}`}
                                    onClick={onClose}
                                    className="flex min-h-[48px] items-center gap-3 rounded-control px-3 text-[15px] text-text-body transition hover:bg-bg-subtle"
                                >
                                    <Icon name={link.icon} size={18} />
                                    {t(link.tKey)}
                                </Link>
                            </li>
                        ))}
                        <li className="border-t border-border-divider">
                            <Link
                                href={route('logout')}
                                method="post"
                                as="button"
                                className="flex min-h-[48px] w-full items-center gap-3 rounded-control px-3 text-left text-[15px] text-text-body transition hover:bg-bg-subtle"
                            >
                                <Icon name="go" size={18} />
                                {t('more.log_out')}
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
            )}
        </Dialog>
    );
}

/**
 * Edit tabs: which of this place's screens sit on the bar, in the order
 * tapped (one to four; More is always added). Saved on the account.
 */
function TabPicker({ context, pinned, hasBook, onDone }) {
    const on = useFeatures();
    const meta = PREFS_REGISTRY.mobileTabs[context];
    const max = PREFS_REGISTRY.maxTabs;
    const choices = (meta.allowed === 'book' ? PREFS_REGISTRY.bookTabs : meta.allowed).filter(
        (key) => T[key] && (key !== 'continue' || hasBook) && on(NAV_FEATURES[key]),
    );
    const [picked, setPicked] = useState(pinned);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const { t } = useI18n();

    const toggle = (key) => {
        setError(null);
        setPicked((list) => (list.includes(key) ? list.filter((k) => k !== key) : list.length < max ? [...list, key] : list));
    };

    const save = async (value) => {
        setBusy(true);

        try {
            await savePrefs({ mobileTabs: { [context]: value } });
            onDone();
        } catch (errors) {
            setError(Object.values(errors).flat()[0] ?? t('common.not_saved_retry'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="safe-bottom min-h-0 overflow-y-auto px-3 pb-3" data-a="sheet:tab-picker">
            <section className="rounded-panel bg-bg-surface p-3">
                <h3 className="text-[14px] font-semibold text-text-primary">{t('more.tabs_while', { place: t(`tab_context.${context}`).toLowerCase() })}</h3>
                <p className="mt-0.5 text-[12.5px] text-text-secondary">
                    {t('more.pick_up_to', { max })}
                </p>
                <p className="mt-2 text-[12.5px] text-text-body" aria-live="polite" data-a="sheet:tab-count">
                    {t('more.pinned', { count: picked.length, max })}
                </p>
                <ul className="mt-2 grid grid-cols-4 gap-1">
                    {choices.map((key) => {
                        const at = picked.indexOf(key);

                        return (
                            <li key={key} className="flex">
                                <button
                                    type="button"
                                    data-a={`sheet:pin:${key}`}
                                    aria-pressed={at >= 0}
                                    disabled={at < 0 && picked.length >= max}
                                    onClick={() => toggle(key)}
                                    className={`relative flex min-h-[70px] flex-1 flex-col items-center gap-1 rounded-control border px-1 pt-2 text-center text-[11.5px] transition disabled:opacity-40 ${
                                        at >= 0 ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-divider text-text-body'
                                    }`}
                                >
                                    <Icon name={T[key].icon} size={19} />
                                    {t(T[key].tKey)}
                                    {at >= 0 && (
                                        <span className="absolute right-1 top-1 grid h-4 w-4 place-items-center rounded-full bg-brand-fill text-[10px] font-semibold text-white" aria-hidden="true">
                                            {at + 1}
                                        </span>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
                {error && (
                    <p role="alert" className="mt-2 text-[12.5px] text-status-danger">
                        {error}
                    </p>
                )}
                <div className="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        data-a="sheet:tabs-save"
                        disabled={busy || picked.length === 0}
                        onClick={() => save(picked)}
                        className="h-10 rounded-control bg-brand-fill px-4 text-[13.5px] font-semibold text-white disabled:opacity-50"
                    >
                        {t('more.save_tabs')}
                    </button>
                    <button
                        type="button"
                        data-a="sheet:tabs-reset"
                        disabled={busy}
                        onClick={() => save(null)}
                        className="h-10 rounded-control border border-border-default px-3 text-[13.5px] text-text-body"
                    >
                        {t('more.usual_tabs')}
                    </button>
                    <button type="button" onClick={onDone} className="h-10 px-3 text-[13.5px] text-text-secondary">
                        {t('common.cancel')}
                    </button>
                </div>
            </section>
        </div>
    );
}
