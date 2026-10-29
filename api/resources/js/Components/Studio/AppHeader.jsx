import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { SIDEBAR_GROUPS } from './BookSidebar';
import CommandPalette from './CommandPalette';
import Icon from './Icon';
import { goTo } from './navigation';
import UserMenu from './UserMenu';
import { ariaKeys, matches, openShortcutSheet, shortcutLabel, usePrefs } from '../../shortcuts';

/**
 * The product header: logo, top nav, search, avatar.
 *
 * It navigates on its own: a page passes the book it is about (or none) and
 * the header knows where each area opens. A page that switches panes in place
 * — the editor — passes onSelect / onNavigate / onOpenChapter instead.
 *
 * The notification bell is gone until there are notifications to show: a
 * bell that only ever said "not built" read as a broken button.
 *
 * Measurements come from HANDOFF.md's layout table (h54, nav gap 34, search
 * 206x33, avatar 37). Nav items that have no screen behind them yet are still
 * shown — navigation that does not exist makes the product impossible to
 * evaluate — but they are marked and lead to an explicit empty state.
 */

export const TOP_NAV = [
    { key: 'books', label: 'My Books', ready: true },
    { key: 'writing', label: 'Writing Studio', ready: true },
    { key: 'ai', label: 'AI Editor', ready: true },
    { key: 'research', label: 'Research', ready: true },
    { key: 'design', label: 'Design', ready: true },
    { key: 'publishing', label: 'Publishing', ready: true },
    { key: 'sales', label: 'Sales', ready: true },
    { key: 'analytics', label: 'Analytics', ready: true },
];

/**
 * Move focus to the skip link's target: into its writing surface when it has
 * one, so the next key typed lands in the manuscript, else the target itself.
 */
function skip(event, selector) {
    const target = document.querySelector(selector);

    if (!target) return;

    event.preventDefault();

    const into = target.querySelector('[contenteditable="true"]') ?? target;

    if (!into.hasAttribute('tabindex') && into === target) into.setAttribute('tabindex', '-1');

    into.focus();
    into.scrollIntoView?.({ block: 'nearest' });
}

export default function AppHeader({
    active,
    bookId = null,
    onSelect,
    onNavigate,
    chapters = [],
    onOpenChapter,
    user,
    account,
    skipTo = '#main',
    skipLabel = 'Skip to content',
    // The studio's View commands (toggle a panel, apply a layout), listed
    // with the rest in search.
    extraCommands = [],
    // Hidden by the workspace or distraction-free: no bar, but search and
    // its Ctrl+K stay, as does the skip link.
    bare = false,
}) {
    const { recent_book_id: recentBookId } = usePage().props;
    const book = bookId ?? recentBookId ?? null;
    const [palette, setPalette] = useState(false);
    // Re-rendered when the author remaps search, so its label follows.
    usePrefs();

    const select = (item) => (onSelect ? onSelect(item) : goTo(book, item.key));
    const navigate = (key) => (onNavigate ? onNavigate(key) : goTo(book, key));

    // ⌘K / Ctrl+K from anywhere, as the label has always promised.
    useEffect(() => {
        const onKey = (event) => {
            if (matches('app.search', event)) {
                event.preventDefault();
                setPalette((open) => !open);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const commands = useMemo(() => {
        const list = TOP_NAV.map((item) => ({
            key: `nav:${item.key}`,
            label: item.label,
            group: 'Go to',
            icon: 'search',
            run: () => select(item),
        }));

        if (book) {
            for (const group of SIDEBAR_GROUPS) {
                for (const item of group.items) {
                    list.push({
                        key: `side:${item.key}`,
                        label: item.label,
                        group: group.group
                            ? group.group[0] + group.group.slice(1).toLowerCase()
                            : 'Book',
                        icon: item.icon,
                        run: () => navigate(item.key),
                    });
                }
            }
        }

        for (const chapter of chapters) {
            list.push({
                key: `chapter:${chapter.id}`,
                label: chapter.title,
                group: 'Chapter',
                icon: 'doc',
                run: () => onOpenChapter?.(chapter.id),
            });
        }

        list.push({
            key: 'help:shortcuts',
            label: 'Keyboard shortcuts',
            group: 'Help',
            icon: 'help',
            shortcut: '?',
            run: openShortcutSheet,
        });

        return [...extraCommands, ...list];
    }, [book, chapters, onSelect, onNavigate, onOpenChapter, extraCommands]);

    const skipLink = (
        <a
            href={skipTo}
            onClick={(event) => skip(event, skipTo)}
            data-a="skip"
            className="sr-only rounded-control bg-brand-fill px-3 py-2 text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-3 focus:top-2 focus:z-[60]"
        >
            {skipLabel}
        </a>
    );

    const paletteDialog = (
        <CommandPalette
            open={palette}
            onClose={() => setPalette(false)}
            commands={commands}
        />
    );

    if (bare) {
        return (
            <>
                {skipLink}
                {paletteDialog}
            </>
        );
    }

    return (
        <header className="flex h-[54px] shrink-0 items-center gap-3 border-b border-border-default bg-bg-surface px-3 sm:px-5">
            {/* First stop for a keyboard: past the header and the sidebar
                (about 40 Tab presses) straight to the page's work. */}
            {skipLink}

            {/*
              * The brand mark, from ecrivain-brand-assets. The symbol is the
              * packaged SVG rather than the icon set's `lib` glyph, so the
              * logo in the product is the same file as the logo everywhere
              * else. The wordmark is the brand name alone: a "Publishing
              * Studio" suffix beside it wrapped to two lines and pushed the
              * nav into a horizontal scrollbar.
              */}
            <Link
                href={route('books.index')}
                className="flex items-center gap-2.5"
                aria-label="Ecrivain home"
            >
                <img
                    src="/brand/symbol.svg"
                    alt=""
                    width={26}
                    height={26}
                    className="shrink-0"
                />
                <span className="whitespace-nowrap font-display text-[20px] font-bold tracking-[-0.01em] text-text-primary">
                    Ecrivain
                </span>
            </Link>

            {/*
              * The nav shrinks before it scrolls. overflow-x-auto put a
              * scrollbar across the header the moment the viewport was
              * narrower than the full set of labels, which is not what the
              * design does and reads like a layout fault. The gap tightens
              * instead, and the whole bar is hidden below the breakpoint
              * where the book sidebar becomes a drawer.
              */}
            <nav
                className="ml-4 hidden min-w-0 shrink items-center gap-4 lg:flex xl:ml-[46px] xl:gap-[34px]"
                aria-label="Main"
            >
                {TOP_NAV.map((item) => {
                    const isActive = active === item.key;

                    return (
                        <button
                            key={item.key}
                            data-a={`nav:${item.key}`}
                            onClick={() => select(item)}
                            title={item.ready ? undefined : 'Not built yet'}
                            aria-current={isActive ? 'page' : undefined}
                            className={`relative whitespace-nowrap py-[17px] text-[14.5px] transition ${
                                isActive
                                    ? 'font-semibold text-brand-primary'
                                    : item.ready
                                      ? 'text-text-body hover:text-text-primary'
                                      : 'text-text-secondary'
                            }`}
                        >
                            {item.label}
                            {!item.ready && (
                                <span className="ml-1 align-super text-[9px]">•</span>
                            )}
                            {isActive && (
                                <span className="absolute inset-x-0 bottom-0 h-[3px] rounded-t bg-brand-fill" />
                            )}
                        </button>
                    );
                })}
            </nav>

            <div className="ml-auto flex shrink-0 items-center gap-3.5">
                {/*
                  * Icon-only below 1280px. At full width between 1024 and
                  * about 1115px the nav ran under it and "Analytics" was
                  * printed over "Search...". On a phone it is the one way to
                  * every area of the studio, so it is shown there too.
                  */}
                <button
                    data-a="palette:open"
                    onClick={() => setPalette(true)}
                    aria-label={`Search (${shortcutLabel('app.search')})`}
                    aria-keyshortcuts={ariaKeys('app.search')}
                    className="flex h-[33px] w-[33px] items-center justify-center gap-2 rounded-control border border-border-input text-left text-[12.5px] text-text-secondary transition hover:border-brand-primary/40 xl:w-[206px] xl:justify-start xl:px-2.5"
                >
                    <Icon name="search" size={15} />
                    <span className="hidden flex-1 xl:inline" aria-hidden="true">Search...</span>
                    <kbd className="hidden font-sans text-[11px] text-text-secondary xl:inline" aria-hidden="true">
                        {shortcutLabel('app.search')}
                    </kbd>
                </button>

                {/* Was a <span> with a title attribute, which meant billing,
                    the profile and sign out were unreachable from anywhere
                    in the studio. */}
                <UserMenu user={user} account={account} bookId={book} />
            </div>

            {paletteDialog}
        </header>
    );
}
