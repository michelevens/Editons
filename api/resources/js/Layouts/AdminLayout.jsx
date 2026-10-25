import Dialog from '@/Components/Dialog';
import Icon from '@/Components/Studio/Icon';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The admin console's shell: "Ecrivain Admin", unmistakably not the studio.
 *
 * Dark sidebar on the left at ≥1024px; below it a header with a menu button
 * that opens the same navigation as a drawer (Components/Dialog, so focus,
 * Tab and Escape behave as everywhere else — ADR-0023/0024). The page is one
 * full-height column, like every signed-in page, with its own scroll.
 */
export const ADMIN_NAV = [
    { key: 'dashboard', label: 'Dashboard', icon: 'chart', route: 'admin.dashboard' },
    { key: 'accounts', label: 'Accounts', icon: 'users', route: 'admin.accounts.index' },
    { key: 'support', label: 'Support', icon: 'help', route: 'admin.support.index' },
    { key: 'ai', label: 'AI usage', icon: 'bolt', route: 'admin.ai.index' },
    { key: 'operations', label: 'Jobs & health', icon: 'flow', route: 'admin.operations.index' },
    { key: 'mail', label: 'Email log', icon: 'bell', route: 'admin.mail.index' },
    { key: 'errors', label: 'Errors', icon: 'info', route: 'admin.errors.index' },
    { key: 'features', label: 'Feature flags', icon: 'list', route: 'admin.features.index' },
    { key: 'impersonations',label: 'Impersonation log', icon: 'eye', route: 'admin.impersonations.index' },
    { key: 'audit', label: 'Audit log', icon: 'history', route: 'admin.audit.index' },
    { key: 'settings', label: 'Settings', icon: 'gear', route: 'admin.settings.show' },
];

/** The business pages (ADR — console business): a group of their own. */
export const ADMIN_BUSINESS_NAV = [
    { key: 'billing', label: 'Billing', icon: 'card', route: 'admin.billing.index' },
    { key: 'plans', label: 'Plans', icon: 'layout', route: 'admin.plans.index' },
    { key: 'sales', label: 'Book sales', icon: 'sales', route: 'admin.sales.index' },
    { key: 'house', label: 'House catalog', icon: 'lib', route: 'admin.house.index' },
    { key: 'partners', label: 'Partners', icon: 'coin', route: 'admin.partners.index' },
    { key: 'growth', label: 'Growth', icon: 'rocket', route: 'admin.growth.acquisition' },
    { key: 'invitations', label: 'Invitations', icon: 'mega', route: 'admin.invitations.index' },
    { key: 'onboarding', label: 'Onboarding', icon: 'check', route: 'admin.onboarding.index' },
];

/**
 * Trust & operations: its own group, so it merges cleanly beside the
 * business pages. `owner` entries are hidden from support (the routes
 * refuse them anyway).
 */
export const ADMIN_TRUST_NAV = [
    { key: 'catalog', label: 'Catalog', icon: 'lib', route: 'admin.catalog.index' },
    { key: 'moderation', label: 'Moderation', icon: 'shieldDot', route: 'admin.moderation.index' },
    { key: 'security', label: 'Security', icon: 'lock', route: 'admin.security.index' },
    { key: 'logs', label: 'Logs', icon: 'code', route: 'admin.logs.index', owner: true },
    { key: 'data-health', label: 'Data health', icon: 'check', route: 'admin.data-health.index' },
    { key: 'status', label: 'Status', icon: 'globe', route: 'admin.status.index' },
    { key: 'offboarding', label: 'Offboarding', icon: 'user', route: 'admin.offboarding.index' },
    { key: 'master-data', label: 'Master data', icon: 'tmpl', route: 'admin.master-data.index' },
    { key: 'releases', label: 'Releases', icon: 'rocket', route: 'admin.releases.index' },
];

export default function AdminLayout({ title, active, children, actions = null, setupOnly = false }) {
    const { auth, platform } = usePage().props;
    const [open, setOpen] = useState(false);

    return (
        <div className="flex h-dvh overflow-hidden bg-bg-app font-ui text-text-body" data-a="admin:shell">
            <Head title={`${title} · Admin`} />

            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-control focus:bg-bg-surface focus:px-3 focus:py-2"
            >
                Skip to content
            </a>

            <aside className="hidden w-[232px] shrink-0 flex-col bg-bg-sidebar text-text-sidebar lg:flex">
                <Brand />
                <AdminNav active={active} setupOnly={setupOnly} />
                <SidebarFoot user={auth?.user} role={platform?.role} />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-[56px] shrink-0 items-center gap-3 border-b border-border-default bg-bg-surface px-4 sm:px-6">
                    <button
                        type="button"
                        data-a="admin:menu"
                        onClick={() => setOpen(true)}
                        aria-label="Open admin navigation"
                        className="tap -ml-2 grid h-10 w-10 place-items-center rounded-control text-text-secondary hover:bg-bg-subtle lg:hidden"
                    >
                        <Icon name="menu" size={20} />
                    </button>
                    <span className="font-display text-[15px] font-extrabold tracking-[-0.02em] text-text-primary lg:hidden">
                        Ecrivain <span className="text-status-danger">Admin</span>
                    </span>
                    <h1 className="min-w-0 flex-1 truncate font-display text-[17px] font-bold text-text-primary max-lg:sr-only">
                        {title}
                    </h1>
                    <div className="ml-auto flex items-center gap-2">{actions}</div>
                </header>

                <main id="main" tabIndex={-1} className="min-h-0 flex-1 overflow-y-auto outline-none">
                    <div className="mx-auto max-w-[1180px] px-4 py-5 sm:px-6 sm:py-7">
                        <h1 className="mb-4 font-display text-[22px] font-extrabold tracking-[-0.02em] text-text-primary lg:hidden">
                            {title}
                        </h1>
                        {children}
                    </div>
                </main>
            </div>

            {open && (
                <Dialog
                    label="Admin navigation"
                    variant="drawer"
                    onClose={() => setOpen(false)}
                    className="flex h-full w-[min(84vw,280px)] flex-col bg-bg-sidebar text-text-sidebar"
                >
                    <div className="flex items-center justify-between pr-2">
                        <Brand />
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            aria-label="Close navigation"
                            className="tap grid h-10 w-10 place-items-center rounded-control text-text-sidebar-muted hover:text-text-sidebar"
                        >
                            <Icon name="x" size={18} />
                        </button>
                    </div>
                    <AdminNav active={active} setupOnly={setupOnly} onNavigate={() => setOpen(false)} />
                    <SidebarFoot user={auth?.user} role={platform?.role} />
                </Dialog>
            )}
        </div>
    );
}

function Brand() {
    return (
        <div className="px-5 pb-4 pt-5">
            <p className="font-display text-[17px] font-extrabold tracking-[-0.02em]">
                Ecrivain <span className="rounded-badge bg-status-danger-fill px-1.5 py-0.5 text-[11px] uppercase tracking-[0.06em] text-white">Admin</span>
            </p>
            <p className="mt-1 text-[11.5px] text-text-sidebar-muted">Platform console</p>
        </div>
    );
}

function AdminNav({ active, setupOnly, onNavigate }) {
    const { platform } = usePage().props;
    const trust = ADMIN_TRUST_NAV.filter((item) => !item.owner || platform?.role === 'owner');

    return (
        <nav aria-label="Admin" className="flex-1 overflow-y-auto px-3 pb-3">
            <NavList items={ADMIN_NAV} active={active} setupOnly={setupOnly} onNavigate={onNavigate} />
            <h2 id="admin-nav-business" className="mb-1 mt-4 px-3 text-[10.5px] font-semibold uppercase tracking-[0.12em] text-text-sidebar-muted">
                Business
            </h2>
            <NavList items={ADMIN_BUSINESS_NAV} active={active} setupOnly={setupOnly} onNavigate={onNavigate} labelledBy="admin-nav-business" />
            <p className="mb-1 mt-4 px-3 text-[10.5px] font-semibold uppercase tracking-[0.12em] text-text-sidebar-muted" id="admin-nav-trust">
                Trust &amp; operations
            </p>
            <NavList items={trust} active={active} setupOnly={setupOnly} onNavigate={onNavigate} labelledBy="admin-nav-trust" />
        </nav>
    );
}

function NavList({ items, active, setupOnly, onNavigate, labelledBy }) {
    return (
        <ul className="space-y-0.5" aria-labelledby={labelledBy}>
            {items.map((item) => {
                const current = item.key === active;

                return (
                    <li key={item.key}>
                        {setupOnly ? (
                            <span className="flex h-10 items-center gap-3 rounded-control px-3 text-[13.5px] text-text-sidebar-muted opacity-60">
                                <Icon name={item.icon} size={17} />
                                {item.label}
                                <Icon name="lock" size={13} className="ml-auto" />
                            </span>
                        ) : (
                            <Link
                                href={route(item.route)}
                                data-a={`admin:nav:${item.key}`}
                                aria-current={current ? 'page' : undefined}
                                onClick={onNavigate}
                                className={`flex h-10 items-center gap-3 rounded-control px-3 text-[13.5px] transition ${
                                    current
                                        ? 'bg-bg-sidebar-active font-semibold text-text-sidebar'
                                        : 'text-text-sidebar-muted hover:bg-bg-sidebar-active hover:text-text-sidebar'
                                }`}
                            >
                                <Icon name={item.icon} size={17} />
                                {item.label}
                            </Link>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function SidebarFoot({ user, role }) {
    return (
        <div className="border-t border-border-sidebar px-5 py-4 text-[12px]">
            <p className="truncate font-semibold">{user?.name}</p>
            <p className="truncate text-text-sidebar-muted">
                {user?.email} · {role === 'owner' ? 'Owner' : 'Support'}
            </p>
            <div className="mt-3 flex gap-3">
                <Link href={route('books.index')} data-a="admin:back-to-studio" className="underline-offset-2 hover:underline">
                    Back to the studio
                </Link>
                <Link href={route('logout')} method="post" as="button" className="text-text-sidebar-muted underline-offset-2 hover:underline">
                    Sign out
                </Link>
            </div>
        </div>
    );
}

/** A white panel with a heading, the console's basic block. */
export function Panel({ title, detail, children, className = '', actions = null, ...rest }) {
    return (
        <section className={`rounded-panel border border-border-default bg-bg-surface ${className}`} {...rest}>
            {(title || actions) && (
                <div className="flex flex-wrap items-start justify-between gap-2 border-b border-border-divider px-4 py-3">
                    <div className="min-w-0">
                        {title && <h2 className="text-[14.5px] font-bold text-text-primary">{title}</h2>}
                        {detail && <p className="mt-0.5 text-[12px] text-text-secondary">{detail}</p>}
                    </div>
                    {actions}
                </div>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

export function formatDate(iso, withTime = true) {
    if (!iso) return '—';

    const date = new Date(iso);

    return withTime
        ? date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
        : date.toLocaleDateString(undefined, { dateStyle: 'medium' });
}

/** Dollars: four decimals under a cent's worth of precision matters, two above. */
export function usd(value) {
    if (value === null || value === undefined) return 'unknown';

    const n = Number(value);

    return `$${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: n !== 0 && Math.abs(n) < 1 ? 4 : 2 })}`;
}

/** "3 h", "2 d": how long something has waited. */
export function age(iso) {
    if (!iso) return '—';

    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));

    if (minutes < 60) return `${minutes} min`;
    if (minutes < 60 * 48) return `${Math.round(minutes / 60)} h`;

    return `${Math.round(minutes / 1440)} d`;
}

export const inputClass =
    'w-full rounded-control border border-border-input bg-bg-surface px-3 py-2 text-[13.5px] text-text-primary placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20';

export const buttonClass =
    'tap inline-flex items-center justify-center gap-2 rounded-button px-3.5 py-2 text-[13px] font-semibold transition disabled:opacity-50';
