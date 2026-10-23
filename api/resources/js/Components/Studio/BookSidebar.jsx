import Icon from './Icon';

/**
 * The book sidebar: book card, then three nav groups.
 *
 * Groups and icons follow HANDOFF.md component 2 and the prototype's `SIDE`
 * map. Items without a screen behind them are marked rather than hidden, and
 * lead to an explicit empty state.
 *
 * Metrics: item h34, icon 19 with a 15px gap, section labels 11px /
 * letter-spacing 1.6.
 */

export const SIDEBAR_GROUPS = [
    {
        group: null,
        items: [
            { key: 'overview', label: 'Overview', icon: 'home', ready: true },
            { key: 'manuscript', label: 'Manuscript', icon: 'doc', ready: true },
            { key: 'research', label: 'Research Library', icon: 'lib', ready: true },
            { key: 'terms', label: 'Characters & Terms', icon: 'users', ready: true },
            { key: 'references', label: 'References & Citations', icon: 'refs', ready: true },
            { key: 'notes', label: 'Notes', icon: 'note', ready: true },
            { key: 'templates', label: 'Templates', icon: 'tmpl', ready: true },
            { key: 'settings', label: 'Book Settings', icon: 'gear', ready: true },
        ],
    },
    {
        group: 'COLLABORATION',
        items: [
            { key: 'comments', label: 'Comments', icon: 'chat', ready: true },
            { key: 'suggestions', label: 'Suggestions', icon: 'sugg', ready: true },
            { key: 'workflow', label: 'Review Workflow', icon: 'flow', ready: true },
            { key: 'team', label: 'Team Members', icon: 'users', ready: true },
        ],
    },
    {
        group: 'PUBLISHING',
        items: [
            { key: 'layout', label: 'Design & Layout', icon: 'layout', ready: true },
            { key: 'cover', label: 'Cover Studio', icon: 'cover', ready: true },
            { key: 'export', label: 'Export & Publish', icon: 'exp', ready: true },
            { key: 'rights', label: 'ISBN & Rights', icon: 'hash', ready: true },
            { key: 'editions', label: 'Editions', icon: 'copies', ready: true },
            { key: 'a11y', label: 'Accessibility', icon: 'access', ready: true },
            { key: 'distribution', label: 'Sales & Distribution', icon: 'sales', ready: true },
            { key: 'royalties', label: 'Royalties', icon: 'coin', ready: true },
        ],
    },
    {
        group: 'GROW',
        items: [
            { key: 'launch', label: 'Launch Planner', icon: 'rocket', ready: true },
            { key: 'audio', label: 'Audiobook Studio', icon: 'mic', ready: false },
            { key: 'course', label: 'Course Builder', icon: 'cap', ready: false },
            { key: 'marketing', label: 'Marketing Kit', icon: 'mega', ready: true },
            { key: 'site', label: 'Author Website', icon: 'globe', ready: false },
        ],
    },
];

/**
 * `topNav` adds the header's areas (Writing Studio, AI Editor, Analytics...)
 * as a first group. The phone drawer passes it: below 1024px the header hides
 * its nav, and without this Analytics had no way in at all.
 */
export default function BookSidebar({
    book,
    active,
    badges = {},
    onSelect,
    topNav = null,
    activeNav = null,
    onSelectTop,
    className = '',
}) {
    const groups = topNav
        ? [
              {
                  group: 'STUDIO',
                  top: true,
                  items: topNav.map((item) => ({
                      ...item,
                      icon: TOP_ICONS[item.key] ?? 'doc',
                  })),
              },
              ...SIDEBAR_GROUPS,
          ]
        : SIDEBAR_GROUPS;

    return (
        <aside className={`flex w-[223px] shrink-0 flex-col overflow-hidden rounded-tr-panel bg-bg-sidebar ${className}`}>
            {/* Pinned: the book being worked on should not scroll out of
                sight, and keeping it out of the scroll area leaves more of
                the list reachable. */}
            <BookCard book={book} />

            {/*
             * The nav scrolls, not the whole sidebar.
             *
             * The studio shell is a fixed-height app frame, so unlike
             * Overview there is no page scroll to fall back on. With the list
             * longer than a 768px-tall viewport the last items sat below the
             * fold with nothing to indicate they existed — the scrollbar only
             * appeared on hover. `sidebar-scroll` keeps it visible.
             */}
            <nav className="sidebar-scroll mt-1 min-h-0 flex-1 overflow-y-auto pb-4" aria-label="Book">
                {groups.map((section, index) => (
                    <div key={section.group ?? index}>
                        {section.group && (
                            <p className="px-[18px] pb-1.5 pt-4 text-[11px] font-medium tracking-[1.6px] text-text-sidebar-muted">
                                {section.group}
                            </p>
                        )}

                        {section.items.map((item) => {
                            const isActive = section.top
                                ? activeNav === item.key
                                : active === item.key;
                            const badge = section.top ? 0 : badges[item.key];

                            return (
                                <button
                                    key={item.key}
                                    data-a={`${section.top ? 'drawer:nav' : 'side'}:${item.key}`}
                                    onClick={() =>
                                        section.top ? onSelectTop?.(item) : onSelect?.(item)
                                    }
                                    title={item.ready ? undefined : 'Not built yet'}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={`flex h-[34px] w-full items-center gap-[15px] px-[18px] text-left text-[13px] transition ${
                                        isActive
                                            ? 'bg-bg-sidebar-active font-semibold text-white'
                                            : item.ready
                                              ? 'text-text-sidebar hover:bg-bg-sidebar-active/55'
                                              : 'text-text-sidebar-muted hover:bg-bg-sidebar-active/35'
                                    }`}
                                >
                                    <Icon
                                        name={item.icon}
                                        size={19}
                                        className="shrink-0 opacity-90"
                                    />
                                    <span className="flex-1 truncate">{item.label}</span>

                                    {badge > 0 ? (
                                        <span className="grid h-[18px] min-w-[18px] shrink-0 place-items-center rounded-badge bg-status-danger-fill px-1 text-[10.5px] font-semibold text-white">
                                            {badge}
                                        </span>
                                    ) : (
                                        !item.ready && (
                                            <span className="shrink-0 text-[9px] opacity-70">
                                                •
                                            </span>
                                        )
                                    )}
                                </button>
                            );
                        })}
                    </div>
                ))}
            </nav>

            <p className="mt-auto px-[18px] pt-6 text-[10px] leading-relaxed text-text-sidebar-muted">
                <span aria-hidden="true">•</span> marks a section that is not
                built yet. Nothing here shows simulated data.
            </p>
        </aside>
    );
}

/** Icons for the header's areas when they are listed in the drawer. */
const TOP_ICONS = {
    books: 'lib',
    writing: 'doc',
    ai: 'sugg',
    research: 'refs',
    design: 'layout',
    publishing: 'exp',
    sales: 'sales',
    analytics: 'chart',
};

/**
 * The cover thumbnail.
 *
 * Draws the author's chosen palette and title when there is no uploaded
 * artwork, rather than a grey "NO COVER" box. Every book has a cover in the
 * sense that matters here — Cover Studio always has a palette selected — and
 * the box was showing because nothing ever sent the field the card asked for.
 */
function BookCover({ cover, title }) {
    if (cover?.url) {
        return (
            <img
                src={cover.thumb ?? cover.url}
                alt=""
                width={46}
                height={73}
                decoding="async"
                className="h-[73px] w-[46px] shrink-0 rounded-[3px] object-cover"
            />
        );
    }

    // No cover data at all: the caller has not been updated to send it, so
    // fall back rather than render a blank rectangle.
    if (!cover) {
        return (
            <div className="grid h-[73px] w-[46px] shrink-0 place-items-center rounded-[3px] bg-bg-sidebar-active text-center text-[8px] font-semibold uppercase leading-tight tracking-wide text-text-sidebar-muted">
                No
                <br />
                cover
            </div>
        );
    }

    return (
        <div
            data-a="sidebar:cover"
            className="flex h-[73px] w-[46px] shrink-0 flex-col justify-between overflow-hidden rounded-[3px] p-[5px]"
            style={{
                background: `linear-gradient(170deg, ${cover.from}, ${cover.to})`,
                color: cover.ink,
            }}
        >
            <span className="line-clamp-3 text-[5.5px] font-bold uppercase leading-[1.25] tracking-tight">
                {cover.title || title}
            </span>
            {cover.author && (
                <span className="truncate text-[4.5px] font-semibold uppercase tracking-wide opacity-90">
                    {cover.author}
                </span>
            )}
        </div>
    );
}

function BookCard({ book }) {
    return (
        <div className="flex gap-3 border-b border-border-sidebar px-[18px] py-4">
            <BookCover cover={book.cover} title={book.title} />

            <div className="min-w-0 pt-0.5">
                <p className="line-clamp-2 text-[13.5px] font-semibold leading-[1.25] text-white">
                    {book.title}
                </p>
                <p className="mt-1 text-[11px] text-text-sidebar-muted">
                    {book.genre || 'Unclassified'} •{' '}
                    {Number(book.word_count ?? 0).toLocaleString()} words
                </p>
                <StatusChip status={book.status} />
            </div>
        </div>
    );
}

function StatusChip({ status }) {
    // Draft has its own tokens in the handoff palette; the other states reuse
    // the status colours rather than inventing new ones.
    const style =
        status === 'draft'
            ? 'bg-draft-bg text-draft-text'
            : status === 'published'
              ? 'bg-status-success-tile text-status-success'
              : 'bg-bg-sidebar-active text-text-sidebar-muted';

    return (
        <span
            className={`mt-1.5 inline-block rounded-[4px] px-[7px] py-[2px] text-[10.5px] font-semibold capitalize ${style}`}
        >
            {String(status ?? 'draft').replace('_', ' ')}
        </span>
    );
}
