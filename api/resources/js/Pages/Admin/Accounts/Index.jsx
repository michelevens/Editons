import AdminLayout, { buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Badge, Pager } from '@/Components/Admin/Kit';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

const FILTERS = [
    ['', 'All'],
    ['suspended', 'Suspended'],
    ['complimentary', 'Complimentary'],
    ['staff', 'Staff'],
    ['house', 'House'],
];

/** Find an account by email, name or id. */
export default function Index({ accounts, q, filter }) {
    const [query, setQuery] = useState(q ?? '');

    const search = (event) => {
        event.preventDefault();
        router.get(route('admin.accounts.index'), { q: query || undefined, filter: filter || undefined }, { preserveState: true });
    };

    return (
        <AdminLayout title="Accounts" active="accounts">
            <form onSubmit={search} role="search" className="flex flex-wrap gap-2">
                <label htmlFor="account-search" className="sr-only">
                    Search accounts
                </label>
                <input
                    id="account-search"
                    data-a="admin:accounts:search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Email, name or account number"
                    className={`${inputClass} min-w-0 flex-1 sm:max-w-[420px]`}
                    autoFocus
                />
                <button type="submit" className={`${buttonClass} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                    Search
                </button>
            </form>

            <div className="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Show">
                {FILTERS.map(([key, label]) => (
                    <Link
                        key={key}
                        href={route('admin.accounts.index', { q: q || undefined, filter: key || undefined })}
                        aria-current={filter === key ? 'true' : undefined}
                        className={`tap rounded-badge px-3 py-1 text-[12.5px] ${
                            filter === key ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-subtle text-text-secondary hover:text-text-primary'
                        }`}
                    >
                        {label}
                    </Link>
                ))}
            </div>

            <div className="mt-4 overflow-hidden rounded-panel border border-border-default bg-bg-surface">
                {accounts.data.length === 0 ? (
                    <p className="p-6 text-center text-[13px] text-text-secondary">No accounts match.</p>
                ) : (
                    <ul className="divide-y divide-border-divider" data-a="admin:accounts:list">
                        {accounts.data.map((account) => (
                            <li key={account.id}>
                                <Link
                                    href={route('admin.accounts.show', account.id)}
                                    data-a={`admin:account:${account.id}`}
                                    className="flex flex-col gap-1 px-4 py-3 hover:bg-bg-subtle sm:flex-row sm:items-center sm:gap-4"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[13.5px] font-semibold text-text-primary">
                                            {account.name}
                                            <span className="ml-2 font-normal text-text-muted">#{account.id}</span>
                                        </p>
                                        <p className="truncate text-[12.5px] text-text-secondary">{account.email}</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5 text-[11.5px]">
                                        <Badge>{account.plan}{account.complimentary ? ' · comp' : ''}</Badge>
                                        <Badge>{account.books} book{account.books === 1 ? '' : 's'}</Badge>
                                        {account.platform_role && <Badge tone="info">{account.platform_role}</Badge>}
                                        {account.house && <Badge tone="warning">House</Badge>}
                                        {account.suspended && <Badge tone="danger">Suspended</Badge>}
                                    </div>
                                    <p className="text-[11.5px] text-text-muted sm:w-[170px] sm:text-right">
                                        {account.last_sign_in ? `Signed in ${formatDate(account.last_sign_in)}` : `Joined ${formatDate(account.created_at, false)}`}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Pager links={accounts.links} />
        </AdminLayout>
    );
}

