import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Dialog, { CloseButton } from '../Dialog';
import { useTyping } from '../Editor/useKeyboard';
import Icon from './Icon';
import { goTo } from './navigation';

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
 * `current` is the screen on show (a pane key such as 'manuscript' or
 * 'cover', or an account page). A page that switches panes in place (the
 * editor) passes onSelect; any other page lets the bar navigate.
 */
const T = {
    books: { label: 'Books', icon: 'lib' },
    continue: { label: 'Write', icon: 'doc' },
    'store-sales': { label: 'Sales', icon: 'sales' },
    billing: { label: 'Billing', icon: 'card' },
    overview: { label: 'Overview', icon: 'home' },
    manuscript: { label: 'Write', icon: 'doc' },
    ai: { label: 'AI Editor', icon: 'sugg' },
    research: { label: 'Research', icon: 'lib' },
    comments: { label: 'Comments', icon: 'chat' },
    workflow: { label: 'Workflow', icon: 'flow' },
    layout: { label: 'Design', icon: 'layout' },
    cover: { label: 'Cover', icon: 'cover' },
    export: { label: 'Export', icon: 'exp' },
    distribution: { label: 'Sales', icon: 'sales' },
    launch: { label: 'Launch', icon: 'rocket' },
    analytics: { label: 'Analytics', icon: 'chart' },
};

const SETS = {
    library: ['books', 'continue', 'store-sales', 'billing'],
    write: ['overview', 'manuscript', 'ai', 'research'],
    review: ['manuscript', 'ai', 'comments', 'workflow'],
    publish: ['manuscript', 'layout', 'cover', 'export'],
    sell: ['manuscript', 'distribution', 'launch', 'analytics'],
};

/** Which set of tabs a screen belongs to. */
export function tabSet(current, hasBook) {
    if (!hasBook) return 'library';

    if (['ai', 'suggestions', 'comments', 'workflow', 'team'].includes(current)) return 'review';
    if (['layout', 'cover', 'export', 'rights', 'editions', 'a11y', 'design', 'publishing'].includes(current)) return 'publish';
    if (['distribution', 'royalties', 'launch', 'marketing', 'analytics', 'sales'].includes(current)) return 'sell';
    if (['books', 'billing', 'store-sales', 'purchases', 'account'].includes(current)) return 'library';

    return 'write';
}

export default function MobileNav({
    current = null,
    bookId = null,
    bookTitle = null,
    aiCount = 0,
    onSelect,
    onChapters,
    onAi,
}) {
    const { recent_book_id: recentBookId } = usePage().props;
    // On a book's own pages the tabs are that book's. On the shelf and the
    // account pages they are the library's, with "Write" reopening the book
    // last worked on.
    const book = bookId ?? recentBookId ?? null;
    const set = tabSet(current, Boolean(bookId));
    const tabs = SETS[set].filter((key) => key !== 'continue' || book);
    const [sheet, setSheet] = useState(false);
    // Out of the way while the keyboard is up: the keys, the formatting
    // bar and the text are what a phone has room for then.
    const typing = useTyping();

    // A screen of the book: the editor switches panes in place, any other
    // page opens the editor on it.
    const open = (key) => {
        setSheet(false);
        (onSelect ?? ((k) => goTo(book, k)))(key);
    };

    const go = (key) => {
        if (key === 'books') return router.get(route('books.index'));
        if (key === 'billing') return router.get(route('billing.show'));
        if (key === 'store-sales') return router.get(route('sales.overview'));
        if (key === 'continue') return goTo(book, 'manuscript');

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
                aria-label="Sections"
                data-a="mobile:tabs"
                className={`safe-bottom shrink-0 border-t border-border-default bg-bg-surface lg:hidden ${typing ? 'hidden' : ''}`}
            >
                <ul className="mx-auto grid h-[58px] max-w-[640px] grid-cols-5">
                    {[...tabs, 'more'].map((key) => {
                        const tab = key === 'more' ? { label: 'More', icon: 'menu' } : T[key];
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
                                    <span>{tab.label}</span>
                                    {key === 'ai' && aiCount > 0 && (
                                        <span className="absolute left-1/2 top-1.5 ml-2 min-w-[18px] rounded-badge bg-brand-fill px-1 text-center text-[10.5px] font-semibold leading-[18px] text-white">
                                            {aiCount > 99 ? '99+' : aiCount}
                                            <span className="sr-only"> open findings</span>
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
        items: [
            { key: 'overview', label: 'Overview', icon: 'home' },
            { key: 'manuscript', label: 'Manuscript', icon: 'doc' },
            { key: 'research', label: 'Research', icon: 'lib' },
            { key: 'terms', label: 'Terms', icon: 'users' },
            { key: 'references', label: 'Citations', icon: 'refs' },
            { key: 'notes', label: 'Notes', icon: 'note' },
            { key: 'templates', label: 'Templates', icon: 'tmpl' },
            { key: 'settings', label: 'Settings', icon: 'gear' },
        ],
    },
    {
        title: 'Review',
        items: [
            { key: 'comments', label: 'Comments', icon: 'chat' },
            { key: 'workflow', label: 'Workflow', icon: 'flow' },
            { key: 'team', label: 'Team', icon: 'users' },
            { key: 'analytics', label: 'Analytics', icon: 'chart' },
        ],
    },
    {
        title: 'Publish',
        items: [
            { key: 'layout', label: 'Design', icon: 'layout' },
            { key: 'cover', label: 'Cover', icon: 'cover' },
            { key: 'export', label: 'Export', icon: 'exp' },
            { key: 'rights', label: 'ISBN & Rights', icon: 'hash' },
            { key: 'editions', label: 'Editions', icon: 'copies' },
            { key: 'a11y', label: 'Accessibility', icon: 'access' },
        ],
    },
    {
        title: 'Sell & grow',
        items: [
            { key: 'distribution', label: 'Sales', icon: 'sales' },
            { key: 'royalties', label: 'Royalties', icon: 'coin' },
            { key: 'launch', label: 'Launch', icon: 'rocket' },
            { key: 'marketing', label: 'Marketing', icon: 'mega' },
        ],
    },
];

function MoreSheet({ book, title, onOpen, onClose }) {
    const account = [
        { label: 'Plans & billing', icon: 'card', href: route('billing.show') },
        { label: 'Storefront sales', icon: 'sales', href: route('sales.overview') },
        { label: 'Your purchases', icon: 'doc', href: route('purchases.index') },
        { label: 'Account settings', icon: 'user', href: route('profile.edit') },
    ];

    return (
        <Dialog
            label="Menu"
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
                    <p className="text-[16px] font-semibold text-text-primary">Menu</p>
                    {book && title && (
                        <p className="truncate text-[12.5px] text-text-secondary">{title}</p>
                    )}
                </div>
                <CloseButton onClick={onClose} label="Close menu" className="h-10 w-10" />
            </div>

            <div className="safe-bottom min-h-0 overflow-y-auto px-3 pb-3">
                {book &&
                    BOOK_GROUPS.map((group) => (
                        <section key={group.title} className="mb-3 rounded-panel bg-bg-surface p-2">
                            <h3 className="px-2 pt-1 pb-1 text-[11.5px] font-semibold tracking-[0.08em] text-text-secondary uppercase">
                                {group.title}
                            </h3>
                            <ul className="grid grid-cols-4">
                                {group.items.map((item) => (
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
                                                {item.label}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}

                <section className="rounded-panel bg-bg-surface p-1">
                    <h3 className="px-3 pt-2 pb-1 text-[11.5px] font-semibold tracking-[0.08em] text-text-secondary uppercase">
                        Account
                    </h3>
                    <ul>
                        {account.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    onClick={onClose}
                                    className="flex min-h-[48px] items-center gap-3 rounded-control px-3 text-[15px] text-text-body transition hover:bg-bg-subtle"
                                >
                                    <Icon name={link.icon} size={18} />
                                    {link.label}
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
                                Log out
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </Dialog>
    );
}
