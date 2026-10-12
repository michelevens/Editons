import { Link } from '@inertiajs/react';

/**
 * The studio chrome: top product nav and the left sidebar.
 *
 * Every destination in the design reference appears here. Sections without an
 * engine behind them are NOT hidden — navigation that does not exist makes a
 * product impossible to evaluate — but they are marked and they lead to an
 * honest empty state rather than fabricated content. Structure is real; data
 * is never invented.
 */

const TOP_NAV = [
    { key: 'books', label: 'My Books', ready: true },
    { key: 'writing', label: 'Writing Studio', ready: true },
    { key: 'ai', label: 'AI Editor', ready: true },
    { key: 'research', label: 'Research', ready: false },
    { key: 'design', label: 'Design', ready: false },
    { key: 'publishing', label: 'Publishing', ready: false },
    { key: 'sales', label: 'Sales', ready: false },
    { key: 'analytics', label: 'Analytics', ready: false },
];

const SIDEBAR = [
    {
        group: null,
        items: [
            { key: 'overview', label: 'Overview', ready: false },
            { key: 'manuscript', label: 'Manuscript', ready: true },
            { key: 'research', label: 'Research Library', ready: false },
            { key: 'terms', label: 'Characters & Terms', ready: false },
            { key: 'references', label: 'References & Citations', ready: false },
            { key: 'notes', label: 'Notes', ready: false },
            { key: 'templates', label: 'Templates', ready: false },
            { key: 'settings', label: 'Book Settings', ready: false },
        ],
    },
    {
        group: 'Collaboration',
        items: [
            { key: 'comments', label: 'Comments', ready: false },
            { key: 'suggestions', label: 'Suggestions', ready: false },
            { key: 'workflow', label: 'Review Workflow', ready: false },
            { key: 'team', label: 'Team Members', ready: false },
        ],
    },
    {
        group: 'Publishing',
        items: [
            { key: 'layout', label: 'Design & Layout', ready: false },
            { key: 'cover', label: 'Cover Studio', ready: false },
            { key: 'export', label: 'Export & Publish', ready: false },
            { key: 'distribution', label: 'Sales & Distribution', ready: false },
        ],
    },
];

export function TopNav({ active, onSelect, user }) {
    return (
        <header className="flex items-center gap-6 border-b border-slate-200 bg-white px-5 py-2.5">
            <Link href={route('books.index')} className="flex items-center gap-2">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-indigo-600 text-sm font-bold text-white">
                    E
                </span>
                <span className="font-serif text-[15px] font-bold text-slate-900">
                    Publishing Studio
                </span>
            </Link>

            <nav className="flex items-center gap-1 overflow-x-auto">
                {TOP_NAV.map((item) => (
                    <button
                        key={item.key}
                        onClick={() => onSelect?.(item)}
                        title={item.ready ? undefined : 'Not built yet'}
                        className={`relative whitespace-nowrap rounded-md px-3 py-1.5 text-sm transition ${
                            active === item.key
                                ? 'font-semibold text-indigo-700'
                                : item.ready
                                  ? 'text-slate-600 hover:bg-slate-100'
                                  : 'text-slate-400 hover:bg-slate-50'
                        }`}
                    >
                        {item.label}
                        {!item.ready && (
                            <span className="ml-1 align-super text-[9px] text-slate-400">
                                •
                            </span>
                        )}
                        {active === item.key && (
                            <span className="absolute inset-x-2 -bottom-[11px] h-0.5 rounded bg-indigo-600" />
                        )}
                    </button>
                ))}
            </nav>

            <div className="ml-auto text-sm text-slate-500">{user?.name}</div>
        </header>
    );
}

export function Sidebar({ book, active, onSelect }) {
    return (
        <aside className="flex w-56 shrink-0 flex-col overflow-y-auto border-r border-slate-200 bg-slate-900 text-slate-300">
            {/* Book header — cover placeholder, title, meta, status. */}
            <div className="border-b border-slate-800 p-4">
                <div className="flex gap-3">
                    <div className="grid h-14 w-11 shrink-0 place-items-center rounded bg-slate-700 text-[9px] font-bold uppercase tracking-wide text-slate-400">
                        No
                        <br />
                        cover
                    </div>
                    <div className="min-w-0">
                        <p className="truncate font-serif text-sm font-bold leading-tight text-white">
                            {book.title}
                        </p>
                        <p className="mt-0.5 text-[11px] text-slate-400">
                            {book.genre || 'Unclassified'} ·{' '}
                            {book.word_count.toLocaleString()} words
                        </p>
                        <span className="mt-1.5 inline-block rounded bg-slate-700 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-300">
                            {book.status.replace('_', ' ')}
                        </span>
                    </div>
                </div>
            </div>

            <nav className="flex-1 py-2">
                {SIDEBAR.map((section, i) => (
                    <div key={i} className="mb-1">
                        {section.group && (
                            <p className="px-4 pb-1 pt-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                {section.group}
                            </p>
                        )}
                        {section.items.map((item) => (
                            <button
                                key={item.key}
                                onClick={() => onSelect?.(item)}
                                title={item.ready ? undefined : 'Not built yet'}
                                className={`flex w-full items-center justify-between px-4 py-1.5 text-left text-[13px] transition ${
                                    active === item.key
                                        ? 'bg-slate-800 font-semibold text-white'
                                        : item.ready
                                          ? 'text-slate-300 hover:bg-slate-800'
                                          : 'text-slate-500 hover:bg-slate-800/50'
                                }`}
                            >
                                <span>{item.label}</span>
                                {!item.ready && (
                                    <span className="text-[9px] text-slate-600">•</span>
                                )}
                            </button>
                        ))}
                    </div>
                ))}
            </nav>

            <p className="border-t border-slate-800 px-4 py-2.5 text-[10px] leading-relaxed text-slate-500">
                <span className="text-slate-600">•</span> marks a section that
                is not built yet. Nothing in this app shows simulated data.
            </p>
        </aside>
    );
}

/** What an unbuilt destination shows. Says what it is and when it arrives. */
export function NotBuilt({ title, milestone, what }) {
    return (
        <div className="grid h-full place-items-center p-10">
            <div className="max-w-sm text-center">
                <h3 className="font-serif text-lg font-bold text-slate-800">{title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{what}</p>
                <p className="mt-4 inline-block rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                    Arrives in {milestone}
                </p>
                <p className="mt-4 text-xs leading-relaxed text-slate-400">
                    This screen is deliberately empty. Showing a mock-up here
                    would misrepresent what the application can do.
                </p>
            </div>
        </div>
    );
}

export { TOP_NAV, SIDEBAR };
