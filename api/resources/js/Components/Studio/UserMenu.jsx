import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { goTo } from './navigation';

/**
 * The account menu behind the avatar.
 *
 * The avatar in the studio header was a <span> with a title attribute, so
 * there was no way to reach billing, the profile, or sign out from anywhere
 * inside the studio — only by typing a URL or going back to the Breeze
 * layout. Entitlements::summaryFor() has carried a `credits_pct` field
 * commented "for the little bar in the account menu" since it was written.
 *
 * Items follow the prototype. The ones with no screen behind them are shown
 * and marked rather than hidden, as the sidebar does: navigation that does
 * not exist makes the product impossible to evaluate, and a menu that
 * silently omits half its items looks finished when it is not.
 */

/**
 * `route` is the destination; `book` items open a screen of the current book.
 *
 * Clients, Integrations, Audit log, Help center and Keyboard shortcuts were
 * listed here with nowhere to go, so clicking them only closed the menu. They
 * come back when they exist.
 */
const ITEMS = [
    [
        { key: 'billing', label: 'Plans & billing', icon: 'card', route: 'billing.show' },
        { key: 'sales', label: 'Storefront sales', icon: 'sales', route: 'sales.overview' },
        { key: 'purchases', label: 'Your purchases', icon: 'doc', route: 'purchases.index' },
    ],
    [
        { key: 'books', label: 'My books', icon: 'doc', route: 'books.index' },
        // Team is per book, so it needs one; called without, route()
        // threw and the menu stayed open.
        { key: 'team', label: 'Team members', icon: 'users', book: 'team' },
        { key: 'account', label: 'Account settings', icon: 'user', route: 'profile.edit' },
    ],
];

export default function UserMenu({ user, account, bookId = null }) {
    const [open, setOpen] = useState(false);
    const wrap = useRef(null);

    // Close on an outside click or Escape. Without both, a menu that opens
    // over the manuscript has to be dismissed by navigating away.
    useEffect(() => {
        if (!open) return undefined;

        const onClick = (event) => {
            if (wrap.current && !wrap.current.contains(event.target)) setOpen(false);
        };
        const onKey = (event) => event.key === 'Escape' && setOpen(false);

        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div ref={wrap} className="relative shrink-0">
            <button
                data-a="user:menu"
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Account"
                className="grid h-[37px] w-[37px] shrink-0 place-items-center overflow-hidden rounded-full bg-brand-primary-subtle text-[12.5px] font-semibold text-brand-primary transition hover:ring-2 hover:ring-brand-primary/25"
            >
                {initials(user?.name)}
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 z-50 mt-2 w-[264px] overflow-hidden rounded-panel border border-border-default bg-bg-surface shadow-[0_18px_44px_-12px_rgba(15,23,42,0.28)]"
                >
                    <div className="border-b border-border-divider px-4 pb-3.5 pt-3.5">
                        <p className="truncate text-[15px] font-bold text-text-primary">
                            {user?.name ?? 'Signed in'}
                        </p>
                        <p className="mt-0.5 truncate text-[12px] text-text-secondary">
                            {account?.plan_name ?? 'Free'} plan
                            {typeof account?.books_active === 'number' &&
                                ` · ${account.books_active} active book${
                                    account.books_active === 1 ? '' : 's'
                                }`}
                        </p>

                        {account && (
                            <>
                                <p
                                    className={`mt-2.5 flex items-center gap-1.5 text-[13px] font-semibold ${
                                        account.low ? 'text-status-warning' : 'text-brand-primary'
                                    }`}
                                >
                                    <Icon name="bolt" size={15} />
                                    {account.credits.toLocaleString()} AI credits left
                                </p>

                                {/* Only when there is a monthly allowance to be a
                                    fraction of. A bar at 0% on a plan with no
                                    allowance reads as an empty account. */}
                                {account.monthly_credits > 0 && (
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-bg-subtle">
                                        <div
                                            className={`h-full rounded-full ${
                                                account.low
                                                    ? 'bg-status-warning'
                                                    : 'bg-brand-primary'
                                            }`}
                                            style={{ width: `${account.credits_pct}%` }}
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    {ITEMS.map((group, index) => (
                        <div
                            key={index}
                            className={index > 0 ? 'border-t border-border-divider py-1' : 'py-1'}
                        >
                            {group.filter((item) => !item.book || bookId).map((item) => (
                                <MenuItem
                                    key={item.key}
                                    item={item}
                                    bookId={bookId}
                                    onDone={() => setOpen(false)}
                                />
                            ))}
                        </div>
                    ))}

                    <div className="border-t border-border-divider py-1">
                        <Link
                            as="button"
                            method="post"
                            href={route('logout')}
                            data-a="user:signout"
                            role="menuitem"
                            className="flex h-[38px] w-full items-center gap-3 px-4 text-left text-[13.5px] text-text-body transition hover:bg-bg-subtle"
                        >
                            <Icon name="go" size={17} className="shrink-0 opacity-70" />
                            Sign out
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}

function MenuItem({ item, bookId, onDone }) {
    return (
        <button
            data-a={`user:${item.key}`}
            role="menuitem"
            onClick={() => {
                onDone();
                if (item.book) goTo(bookId, item.book);
                else router.get(route(item.route));
            }}
            className="flex h-[38px] w-full items-center gap-3 px-4 text-left text-[13.5px] text-text-body transition hover:bg-bg-subtle"
        >
            <Icon name={item.icon} size={17} className="shrink-0 opacity-70" />
            <span className="flex-1 truncate">{item.label}</span>
        </button>
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
