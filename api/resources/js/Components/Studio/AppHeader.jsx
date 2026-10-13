import { Link } from '@inertiajs/react';
import Icon from './Icon';

/**
 * The product header: logo, top nav, search, bell, avatar.
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
    { key: 'design', label: 'Design', ready: false },
    { key: 'publishing', label: 'Publishing', ready: false },
    { key: 'sales', label: 'Sales', ready: false },
    { key: 'analytics', label: 'Analytics', ready: false },
];

export default function AppHeader({
    active,
    onSelect,
    user,
    unread = 0,
    onOpenPalette,
    onOpenNotifications,
}) {
    return (
        <header className="flex h-[54px] shrink-0 items-center border-b border-border-default bg-bg-surface px-5">
            <Link
                href={route('books.index')}
                className="flex items-center gap-2.5"
                aria-label="Publishing Studio home"
            >
                <span className="text-brand-primary">
                    <Icon name="lib" size={26} strokeWidth={1.6} />
                </span>
                <span className="text-[20px] font-bold tracking-[-0.01em] text-text-primary">
                    Publishing Studio
                </span>
            </Link>

            <nav
                className="ml-[46px] flex items-center gap-[34px] overflow-x-auto"
                aria-label="Main"
            >
                {TOP_NAV.map((item) => {
                    const isActive = active === item.key;

                    return (
                        <button
                            key={item.key}
                            data-a={`nav:${item.key}`}
                            onClick={() => onSelect?.(item)}
                            title={item.ready ? undefined : 'Not built yet'}
                            aria-current={isActive ? 'page' : undefined}
                            className={`relative whitespace-nowrap py-[17px] text-[14.5px] transition ${
                                isActive
                                    ? 'font-semibold text-brand-primary'
                                    : item.ready
                                      ? 'text-text-body hover:text-text-primary'
                                      : 'text-text-secondary/60'
                            }`}
                        >
                            {item.label}
                            {!item.ready && (
                                <span className="ml-1 align-super text-[9px]">•</span>
                            )}
                            {isActive && (
                                <span className="absolute inset-x-0 bottom-0 h-[3px] rounded-t bg-brand-primary" />
                            )}
                        </button>
                    );
                })}
            </nav>

            <div className="ml-auto flex items-center gap-3.5">
                <button
                    data-a="palette:open"
                    onClick={onOpenPalette}
                    className="flex h-[33px] w-[206px] items-center gap-2 rounded-control border border-border-input px-2.5 text-left text-[12.5px] text-text-secondary transition hover:border-brand-primary/40"
                >
                    <Icon name="search" size={15} />
                    <span className="flex-1">Search...</span>
                    <kbd className="font-sans text-[11px] text-text-secondary/80">
                        ⌘ K
                    </kbd>
                </button>

                <button
                    data-a="bell:open"
                    onClick={onOpenNotifications}
                    className="relative text-text-body transition hover:text-text-primary"
                    aria-label={
                        unread > 0 ? `Notifications (${unread} unread)` : 'Notifications'
                    }
                >
                    <Icon name="bell" size={20} />
                    {unread > 0 && (
                        <span className="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-status-danger ring-2 ring-bg-surface" />
                    )}
                </button>

                <span
                    className="grid h-[37px] w-[37px] shrink-0 place-items-center overflow-hidden rounded-full bg-brand-primary-subtle text-[12.5px] font-semibold text-brand-primary"
                    title={user?.name}
                >
                    {initials(user?.name)}
                </span>
            </div>
        </header>
    );
}

function initials(name) {
    if (!name) return '?';

    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}
