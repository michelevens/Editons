import ManuscriptEditor from '@/Components/Editor/ManuscriptEditor';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * The writing studio: binder left, canvas centre, history right.
 *
 * The design reference also shows an AI Editorial panel, a research library,
 * a design preview, a cover studio and an export panel. None of those have
 * an engine behind them yet, so the right rail shows version history — which
 * is real — and the unbuilt modules appear once, explicitly, as a
 * "coming in Milestone 2" note. CLAUDE.md forbids shipping mock screens, and
 * a panel listing "Fact Checker" with nothing behind it is exactly that.
 */
export default function Editor({ book, binder, activeSection }) {
    const [section, setSection] = useState(activeSection);
    const [bookWords, setBookWords] = useState(book.word_count);
    const [versions, setVersions] = useState([]);
    const [loadingVersions, setLoadingVersions] = useState(false);

    // Inertia replaces props on navigation; mirror them into local state so
    // the editor swaps chapters without a full remount.
    useEffect(() => setSection(activeSection), [activeSection?.id]);

    const loadVersions = useCallback(async () => {
        if (!section) return;
        setLoadingVersions(true);
        try {
            const res = await window.axios.get(`/sections/${section.id}/versions`);
            setVersions(res.data.versions);
        } finally {
            setLoadingVersions(false);
        }
    }, [section?.id]);

    useEffect(() => {
        loadVersions();
    }, [loadVersions]);

    const onSaved = useCallback(
        (data) => {
            setBookWords(data.book_word_count);
            // Version count can change on save; refresh the list so the panel
            // is not quietly stale.
            if (data.versions_count !== versions.length) loadVersions();
        },
        [versions.length, loadVersions],
    );

    const restore = async (versionId) => {
        if (!confirm('Restore this version? Your current text is saved to history first.')) {
            return;
        }
        const res = await window.axios.post(
            `/sections/${section.id}/versions/${versionId}/restore`,
        );
        setSection((s) => ({ ...s, content: res.data.content, word_count: res.data.word_count }));
        loadVersions();
    };

    return (
        <div className="flex h-screen flex-col bg-slate-100">
            <Head title={book.title} />

            <TopBar book={book} bookWords={bookWords} />

            <div className="flex min-h-0 flex-1">
                <Binder
                    book={book}
                    nodes={binder}
                    activeId={section?.id}
                    onSelect={(id) =>
                        router.get(
                            route('books.edit', book.id),
                            { section: id },
                            { preserveState: true, preserveScroll: true },
                        )
                    }
                />

                <main className="min-w-0 flex-1 border-x border-slate-200 bg-white">
                    <ManuscriptEditor section={section} onSaved={onSaved} />
                </main>

                <HistoryPanel
                    versions={versions}
                    loading={loadingVersions}
                    onRestore={restore}
                />
            </div>
        </div>
    );
}

function TopBar({ book, bookWords }) {
    return (
        <header className="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-2.5">
            <div className="flex items-center gap-4">
                <Link
                    href={route('books.index')}
                    className="text-sm font-semibold text-slate-500 hover:text-slate-800"
                >
                    ← Books
                </Link>
                <div>
                    <h1 className="font-serif text-base font-bold leading-tight text-slate-900">
                        {book.title}
                    </h1>
                    <p className="text-xs text-slate-500">
                        {book.status.replace('_', ' ')} ·{' '}
                        {bookWords.toLocaleString()} words
                    </p>
                </div>
            </div>
        </header>
    );
}

function Binder({ book, nodes, activeId, onSelect }) {
    const { data, setData, post, processing, reset } = useForm({
        type: 'chapter',
        title: '',
    });

    const addChapter = (e) => {
        e.preventDefault();
        if (!data.title.trim()) return;
        post(route('sections.store', book.id), { onSuccess: () => reset() });
    };

    return (
        <aside className="flex w-64 shrink-0 flex-col overflow-y-auto bg-slate-50">
            <div className="px-4 py-3">
                <h2 className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                    Manuscript
                </h2>
            </div>

            <nav className="flex-1 px-2">
                {nodes.map((node) => (
                    <BinderNode
                        key={node.id}
                        node={node}
                        activeId={activeId}
                        onSelect={onSelect}
                        depth={0}
                    />
                ))}
            </nav>

            <form onSubmit={addChapter} className="border-t border-slate-200 p-3">
                <input
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="New chapter title"
                    className="w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <button
                    disabled={processing}
                    className="mt-2 w-full rounded-md bg-slate-800 py-1.5 text-xs font-bold text-white hover:bg-slate-900 disabled:opacity-60"
                >
                    Add chapter
                </button>
            </form>
        </aside>
    );
}

function BinderNode({ node, activeId, onSelect, depth }) {
    const isPart = node.type === 'part';
    const active = node.id === activeId;

    return (
        <div>
            <button
                onClick={() => !isPart && onSelect(node.id)}
                disabled={isPart}
                style={{ paddingLeft: 10 + depth * 12 }}
                className={`flex w-full items-baseline justify-between rounded-md py-1.5 pr-2 text-left text-sm transition ${
                    isPart
                        ? 'cursor-default font-bold uppercase tracking-wide text-[11px] text-slate-500'
                        : active
                          ? 'bg-indigo-50 font-semibold text-indigo-700'
                          : 'text-slate-700 hover:bg-slate-100'
                }`}
            >
                <span className="truncate">{node.title}</span>
                {!isPart && node.word_count > 0 && (
                    <span className="ml-2 shrink-0 text-[10px] text-slate-400">
                        {node.word_count.toLocaleString()}
                    </span>
                )}
            </button>

            {node.children?.map((child) => (
                <BinderNode
                    key={child.id}
                    node={child}
                    activeId={activeId}
                    onSelect={onSelect}
                    depth={depth + 1}
                />
            ))}
        </div>
    );
}

function HistoryPanel({ versions, loading, onRestore }) {
    return (
        <aside className="flex w-72 shrink-0 flex-col overflow-y-auto bg-slate-50">
            <div className="px-4 py-3">
                <h2 className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                    Version history
                </h2>
            </div>

            <div className="flex-1 px-3">
                {loading && <p className="px-1 text-xs text-slate-400">Loading…</p>}

                {!loading && versions.length === 0 && (
                    <p className="px-1 text-xs leading-relaxed text-slate-500">
                        No versions yet. One is saved automatically as you
                        write, and whenever you press Ctrl/Cmd+S.
                    </p>
                )}

                <ul className="space-y-1.5">
                    {versions.map((v) => (
                        <li
                            key={v.id}
                            className="rounded-lg border border-slate-200 bg-white p-2.5"
                        >
                            <div className="flex items-baseline justify-between">
                                <span className="text-xs font-bold text-slate-800">
                                    v{v.version_number}
                                </span>
                                <span className="text-[10px] uppercase tracking-wide text-slate-400">
                                    {v.reason}
                                </span>
                            </div>
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                {v.created_human} · {v.word_count.toLocaleString()} words
                            </p>
                            {v.label && (
                                <p className="mt-0.5 text-[11px] italic text-slate-500">
                                    {v.label}
                                </p>
                            )}
                            <button
                                onClick={() => onRestore(v.id)}
                                className="mt-1.5 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                Restore
                            </button>
                        </li>
                    ))}
                </ul>
            </div>

            {/* Stated plainly rather than mocked. The design reference shows
                AI agents, research and export panels here; they have no engine
                behind them, and a convincing placeholder would misrepresent
                what this build can do. */}
            <div className="m-3 rounded-lg border border-dashed border-slate-300 p-3">
                <p className="text-[11px] font-bold uppercase tracking-wide text-slate-500">
                    Not built yet
                </p>
                <p className="mt-1 text-[11px] leading-relaxed text-slate-500">
                    AI editorial review, research library, citations, book
                    design and export arrive in later milestones. Nothing on
                    this screen is simulated.
                </p>
            </div>
        </aside>
    );
}
