import AppHeader from '@/Components/Studio/AppHeader';
import MobileNav from '@/Components/Studio/MobileNav';
import Icon from '@/Components/Studio/Icon';
import { goTo } from '@/Components/Studio/navigation';
import StudioSidebar from '@/Components/Studio/StudioSidebar';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * The book's home screen.
 *
 * Every figure comes from the server, counted from the database. A progress
 * checklist an author cannot trust is worse than no checklist, so nothing
 * here is estimated and nothing is stored — see OverviewController.
 */
export default function Overview({ book, stats, checklist, channels = [], store = null }) {
    const { auth } = usePage().props;
    const { t } = useI18n();

    const open = (key) => goTo(book.id, key);
    const [menu, setMenu] = useState(false);
    const closeMenu = useCallback(() => setMenu(false), []);

    return (
        <div className="flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={t('overview.head', { title: book.title })} />

            <AppHeader
                active="writing"
                user={auth?.user}
                account={auth?.account}
                bookId={book.id}
            />

            <div className="flex min-h-0 flex-1">
                <StudioSidebar
                    open={menu}
                    onClose={closeMenu}
                    activeNav="writing"
                    onSelectNav={(item) => open(item.key)}
                    book={book}
                    active="overview"
                    onSelect={(item) =>
                        item.key === 'overview' ? null : open(item.key)
                    }
                />

                <main id="main" tabIndex={-1} className="min-w-0 flex-1 overflow-y-auto outline-none">
                    <div className="mx-auto max-w-[1040px] px-4 py-6 sm:px-8 sm:py-9">
                        <header className="flex flex-wrap items-start justify-between gap-5">
                            <div className="min-w-0">
                                <h1 className="break-words font-display text-[26px] font-extrabold tracking-[-0.03em] text-text-primary sm:text-[30px]">
                                    {book.title}
                                </h1>
                                {book.subtitle && (
                                    <p className="mt-1.5 text-[15px] text-text-secondary">
                                        {book.subtitle}
                                    </p>
                                )}
                                <p className="mt-2 text-[12.5px] text-text-secondary">
                                    {book.genre || t('book.unclassified')} · {t('overview.edited', { when: book.updated_at })}
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-2.5">
                                {/*
                                 * The guided view of the same book: the
                                 * stages, and one thing to do next. Offered
                                 * beside the manuscript rather than instead
                                 * of it, because which one an author wants
                                 * depends on the day.
                                 */}
                                <button
                                    data-a="overview:guided"
                                    onClick={() =>
                                        router.get(route('journey.guided', book.id))
                                    }
                                    className="flex h-[40px] items-center gap-2 rounded-control border border-border-default bg-bg-surface px-4 text-[14px] font-semibold text-text-secondary transition hover:border-brand-primary/40 hover:text-text-primary"
                                >
                                    {t('overview.guided')}
                                </button>

                                <button
                                    data-a="overview:open-manuscript"
                                    onClick={() => open('manuscript')}
                                    className="flex h-[40px] items-center gap-2 rounded-control bg-brand-fill px-4 text-[14px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover"
                                >
                                    {t('overview.open_manuscript')}
                                    <Icon name="right" size={16} strokeWidth={2.2} />
                                </button>
                            </div>
                        </header>

                        <Kpis stats={stats} onOpen={open} />

                        <div className="mt-6 grid grid-cols-1 gap-[18px] lg:grid-cols-2">
                            <WordTarget book={book} stats={stats} />
                            <Checklist items={checklist} onOpen={open} />
                        </div>

                        <Published channels={channels} store={store} onOpen={open} />
                    </div>
                </main>
            </div>

            <MobileNav current="overview" bookId={book.id} bookTitle={book.title} />
        </div>
    );
}

const CHANNEL_STATUS = {
    live: [{ tKey: 'channel.live' }, 'text-status-success'],
    in_review: [{ tKey: 'channel.in_review' }, 'text-status-warning'],
    ready_to_submit: [{ tKey: 'channel.ready_to_submit' }, 'text-status-warning'],
    not_submitted: [{ tKey: 'channel.not_submitted' }, 'text-text-secondary'],
    unpublished: [{ tKey: 'channel.unpublished' }, 'text-text-secondary'],
};

/** Where the book is published: each store's status, the ASIN once known. */
function Published({ channels, store, onOpen }) {
    const { t } = useI18n();

    return (
        <section data-a="overview:published" className="mt-[18px] rounded-panel border border-border-default bg-bg-surface p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-[15px] font-semibold text-text-primary">{t('overview.published')}</h2>
                <button
                    type="button"
                    data-a="overview:publish"
                    onClick={() => onOpen('publish')}
                    className="rounded-control border border-border-input px-3 py-1.5 text-[12.5px] font-semibold text-brand-primary transition hover:border-brand-primary/40"
                >
                    {t('overview.publish_3')}
                </button>
            </div>
            {channels.length === 0 && !store ? (
                <p className="mt-2 text-[12.5px] text-text-secondary">{t('overview.not_published')}</p>
            ) : (
                <ul className="mt-2 divide-y divide-border-default text-[12.5px]">
                    {store && (
                        <li className="flex flex-wrap justify-between gap-2 py-1.5">
                            <span className="text-text-primary">{store.paperback ? t('overview.store_both') : t('overview.store')}</span>
                            <span className={store.state === 'live' ? 'text-status-success' : 'text-text-secondary'}>
                                {store.state === 'live' ? t('overview.on_sale') : store.state === 'taken_down' ? t('overview.taken_down') : t('overview.draft')}
                            </span>
                        </li>
                    )}
                    {channels.map((c) => {
                        const [status, tone] = CHANNEL_STATUS[c.status] ?? [null, 'text-text-secondary'];
                        const label = status ? t(status.tKey) : c.status;

                        return (
                            <li key={c.channel} data-a={`overview:channel:${c.channel}`} className="flex flex-wrap justify-between gap-2 py-1.5">
                                <span className="text-text-primary">{c.label}</span>
                                <span className={tone}>
                                    {label}
                                    {c.external_id ? ` · ${c.external_id}` : ''}
                                    {c.submitted_at && c.status !== 'not_submitted' ? ` · ${c.submitted_at}` : ''}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

function Kpis({ stats, onOpen }) {
    const { t, formatNumber } = useI18n();
    const tiles = [
        {
            label: t('overview.kpi_words'),
            value: formatNumber(stats.words),
            sub: t('overview.chapters', { count: stats.chapters }),
            pane: 'manuscript',
        },
        {
            label: t('overview.kpi_findings'),
            value: stats.open_findings,
            sub:
                stats.agents_with_findings === 0
                    ? t('overview.nothing_outstanding')
                    : t('overview.across_agents', { count: stats.agents_with_findings }),
            pane: 'manuscript',
            tone: stats.critical > 0 ? 'danger' : undefined,
        },
        {
            label: t('overview.kpi_claims'),
            value: stats.claims_needing_source,
            sub: t('overview.sources', { count: stats.sources }),
            pane: 'research',
            tone: stats.claims_needing_source > 0 ? 'warning' : undefined,
        },
        {
            label: t('overview.kpi_conflicts'),
            value: stats.consistency_issues,
            sub: t('overview.across_chapters'),
            pane: 'terms',
            tone: stats.consistency_issues > 0 ? 'warning' : undefined,
        },
    ];

    return (
        <div className="mt-7 grid grid-cols-1 gap-[14px] sm:grid-cols-2 lg:grid-cols-4">
            {tiles.map((tile) => (
                <button
                    key={tile.pane + tile.label}
                    data-a={`overview:kpi:${tile.pane}`}
                    onClick={() => onOpen(tile.pane)}
                    className="rounded-panel border border-border-default bg-bg-surface p-4 text-left transition hover:border-brand-primary-border"
                >
                    <p className="text-[11.5px] font-medium uppercase tracking-wide text-text-secondary">
                        {tile.label}
                    </p>
                    <p
                        className={`mt-2 font-display text-[28px] font-bold leading-none tracking-[-0.03em] ${
                            tile.tone === 'danger'
                                ? 'text-status-danger'
                                : tile.tone === 'warning'
                                  ? 'text-status-warning'
                                  : 'text-text-primary'
                        }`}
                    >
                        {tile.value}
                    </p>
                    <p className="mt-1.5 text-[12px] text-text-secondary">{tile.sub}</p>
                </button>
            ))}
        </div>
    );
}

function WordTarget({ book, stats }) {
    const { t, formatNumber } = useI18n();
    const { data, setData, patch, processing } = useForm({
        target_words: book.target_words ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        patch(route('books.overview.update', book.id), { preserveScroll: true });
    };

    const target = book.target_words;
    const pct = target ? Math.min(100, Math.round((stats.words / target) * 100)) : null;

    return (
        <section className="rounded-panel border border-border-default bg-bg-surface p-5">
            <h2 className="text-[15px] font-semibold text-text-primary">{t('overview.target')}</h2>

            {target ? (
                <>
                    <p className="mt-3.5 font-display text-[32px] font-bold leading-none tracking-[-0.03em] text-text-primary">
                        {formatNumber(stats.words)}
                        <span className="ml-2 font-ui text-[14px] font-normal text-text-secondary">
                            {t('overview.of_words', { words: formatNumber(target) })}
                        </span>
                    </p>

                    <div className="mt-3.5 h-2 overflow-hidden rounded-full bg-bg-subtle">
                        <div
                            className="h-full rounded-full bg-brand-fill transition-[width]"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                    <p className="mt-2 text-[12.5px] text-text-secondary">
                        {t('overview.pct_of_target', { pct })}
                        {stats.words < target &&
                            ` · ${t('overview.to_go', { words: formatNumber(target - stats.words) })}`}
                    </p>
                </>
            ) : (
                <p className="mt-3 text-[13px] leading-relaxed text-text-secondary">
                    {t('overview.no_target')}
                </p>
            )}

            <form onSubmit={submit} className="mt-5 flex items-end gap-2.5">
                <label className="flex-1">
                    <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                        {t('overview.target_label')}
                    </span>
                    <input
                        type="number"
                        min="1000"
                        step="1000"
                        value={data.target_words}
                        data-a="overview:target"
                        placeholder={t('overview.target_placeholder')}
                        onChange={(event) => setData('target_words', event.target.value)}
                        className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                    />
                </label>
                <button
                    disabled={processing}
                    data-a="overview:target:save"
                    className="h-[38px] shrink-0 rounded-control bg-bg-inverse px-3.5 text-[13px] font-semibold text-text-inverse transition hover:opacity-90 disabled:opacity-60"
                >
                    {target ? t('overview.update') : t('overview.set_target')}
                </button>
            </form>
        </section>
    );
}

function Checklist({ items, onOpen }) {
    const { t } = useI18n();
    const done = items.filter((item) => item.done).length;

    return (
        <section className="rounded-panel border border-border-default bg-bg-surface p-5">
            <div className="flex items-baseline justify-between">
                <h2 className="text-[15px] font-semibold text-text-primary">
                    {t('overview.checklist')}
                </h2>
                <span className="text-[12.5px] text-text-secondary">
                    {t('overview.done_of', { done, total: items.length })}
                </span>
            </div>

            <ul className="mt-3.5 divide-y divide-border-divider">
                {items.map((item) => (
                    <li key={item.label}>
                        <button
                            data-a={`overview:step:${item.route}`}
                            onClick={() => onOpen(item.route)}
                            className="flex w-full items-center gap-3 py-2.5 text-left transition hover:opacity-80"
                        >
                            <span
                                className={`grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full ${
                                    item.done
                                        ? 'bg-status-success-tile text-status-success'
                                        : 'bg-bg-subtle text-text-secondary'
                                }`}
                            >
                                {item.done ? (
                                    <Icon name="check" size={13} strokeWidth={2.6} />
                                ) : (
                                    <span className="h-1.5 w-1.5 rounded-full bg-current" />
                                )}
                            </span>

                            <span className="min-w-0 flex-1">
                                <span
                                    className={`block text-[13.5px] ${
                                        item.done
                                            ? 'text-text-secondary line-through'
                                            : 'font-medium text-text-primary'
                                    }`}
                                >
                                    {item.label}
                                </span>
                                <span className="block text-[11.5px] text-text-secondary">
                                    {item.detail}
                                </span>
                            </span>

                            <span className="shrink-0 text-text-secondary">
                                <Icon name="right" size={15} />
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            <p className="mt-4 text-[11px] leading-relaxed text-text-secondary">
                {t('overview.checklist_note')}
            </p>
        </section>
    );
}
