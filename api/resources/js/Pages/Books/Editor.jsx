import ManuscriptEditor from '@/Components/Editor/ManuscriptEditor';
import ConsistencyPanel from '@/Components/Studio/ConsistencyPanel';
import InspectorPanel from '@/Components/Studio/InspectorPanel';
import ResearchLibrary from '@/Components/Studio/ResearchLibrary';
import { NotBuilt, Sidebar, TopNav } from '@/Components/Studio/Shell';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * The writing studio.
 *
 * Follows the design reference's layout: product nav across the top, book
 * sidebar on the left, binder, canvas, inspector. Destinations without an
 * engine are present and navigable but lead to an explicit empty state —
 * hiding them would make the product impossible to evaluate, and faking them
 * would misrepresent it.
 */
export default function Editor({ book, binder, activeSection, agents }) {
    const { auth } = usePage().props;

    const [section, setSection] = useState(activeSection);
    const [bookWords, setBookWords] = useState(book.word_count);
    const [versions, setVersions] = useState([]);
    const [findings, setFindings] = useState([]);
    const [running, setRunning] = useState(null);

    const [nav, setNav] = useState('writing');      // top nav
    const [pane, setPane] = useState('manuscript'); // sidebar
    const [binderTab, setBinderTab] = useState('manuscript');

    useEffect(() => setSection(activeSection), [activeSection?.id]);

    const load = useCallback(async () => {
        if (!section) return;
        const [v, f] = await Promise.all([
            window.axios.get(`/sections/${section.id}/versions`),
            window.axios.get(`/sections/${section.id}/findings`),
        ]);
        setVersions(v.data.versions);
        setFindings(f.data.findings);
    }, [section?.id]);

    useEffect(() => {
        load();
    }, [load]);

    const onSaved = useCallback(
        (data) => {
            setBookWords(data.book_word_count);
            if (data.versions_count !== versions.length) load();
        },
        [versions.length, load],
    );

    const runAgent = async (agent) => {
        setRunning(agent);
        try {
            const res = await window.axios.post(`/sections/${section.id}/review`, { agent });
            setFindings(res.data.findings);
        } finally {
            setRunning(null);
        }
    };

    const resolve = async (finding, status) => {
        const res = await window.axios.patch(`/findings/${finding.id}`, { status });
        setFindings(res.data.findings);
    };

    const restore = async (versionId) => {
        if (!confirm('Restore this version? Your current text is saved to history first.')) return;
        const res = await window.axios.post(`/sections/${section.id}/versions/${versionId}/restore`);
        setSection((s) => ({ ...s, content: res.data.content, word_count: res.data.word_count }));
        load();
    };

    // Which destinations actually have something behind them.
    const liveNav = ['writing', 'ai', 'books', 'research'].includes(nav);
    const livePane = (p) => ['manuscript', 'research', 'terms'].includes(p);

    // The top-level Research area and the sidebar's Research Library are the
    // same destination; keeping them in step avoids two routes to one screen
    // that disagree about which is selected.
    const effectivePane = nav === 'research' ? 'research' : pane;

    const openSection = (id) =>
        router.get(
            route('books.edit', book.id),
            { section: id },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <div className="flex h-screen flex-col bg-slate-100">
            <Head title={book.title} />

            <TopNav active={nav} onSelect={(i) => setNav(i.key)} user={auth?.user} />

            <div className="flex min-h-0 flex-1">
                <Sidebar
                    book={{ ...book, word_count: bookWords }}
                    active={liveNav ? effectivePane : null}
                    onSelect={(i) => {
                        // The sidebar belongs to the manuscript area; choosing
                        // from it moves you back there rather than leaving the
                        // highlight pointing at a pane you cannot see.
                        setNav('writing');
                        setPane(i.key);
                    }}
                />

                {!liveNav ? (
                    <main className="flex-1 bg-white">
                        <NotBuilt
                            title={nav.charAt(0).toUpperCase() + nav.slice(1)}
                            milestone={
                                nav === 'research' ? 'Milestone 2'
                                : nav === 'design' || nav === 'publishing' ? 'Milestone 3'
                                : 'Milestone 5'
                            }
                            what="This area of the studio has no engine behind it yet."
                        />
                    </main>
                ) : !livePane(effectivePane) ? (
                    <main className="flex-1 bg-white">
                        <NotBuilt
                            title="Not built yet"
                            milestone="a later milestone"
                            what="This section of the book workspace has no engine behind it yet."
                        />
                    </main>
                ) : effectivePane === 'research' ? (
                    <main className="flex-1 overflow-y-auto bg-white">
                        <ResearchLibrary bookId={book.id} />
                    </main>
                ) : effectivePane === 'terms' ? (
                    <main className="flex-1 overflow-y-auto bg-white">
                        <ConsistencyPanel bookId={book.id} onOpenSection={openSection} />
                    </main>
                ) : (
                    <>
                        <Binder
                            book={book}
                            nodes={binder}
                            activeId={section?.id}
                            tab={binderTab}
                            onTab={setBinderTab}
                            onSelect={openSection}
                        />

                        <main className="min-w-0 flex-1 border-x border-slate-200 bg-white">
                            <ManuscriptEditor section={section} onSaved={onSaved} />
                        </main>

                        <InspectorPanel
                            agents={agents}
                            findings={findings}
                            versions={versions}
                            running={running}
                            sectionId={section?.id}
                            bookId={book.id}
                            onRunAgent={runAgent}
                            onResolve={resolve}
                            onRestore={restore}
                            onOpenLibrary={() => {
                                setNav('writing');
                                setPane('research');
                            }}
                        />
                    </>
                )}
            </div>
        </div>
    );
}

function Binder({ book, nodes, activeId, tab, onTab, onSelect }) {
    const [query, setQuery] = useState('');
    const { data, setData, post, processing, reset } = useForm({ type: 'chapter', title: '' });

    const addChapter = (e) => {
        e.preventDefault();
        if (!data.title.trim()) return;
        post(route('sections.store', book.id), { onSuccess: () => reset() });
    };

    const matches = (node) =>
        !query || node.title.toLowerCase().includes(query.toLowerCase());

    return (
        <aside className="flex w-64 shrink-0 flex-col overflow-hidden bg-slate-50">
            <div className="flex border-b border-slate-200 bg-white">
                {[
                    { key: 'manuscript', label: 'Manuscript', ready: true },
                    { key: 'outline', label: 'Outline', ready: false },
                    { key: 'notes', label: 'Notes', ready: false },
                ].map((t) => (
                    <button
                        key={t.key}
                        onClick={() => onTab(t.key)}
                        className={`flex-1 border-b-2 py-2.5 text-xs font-semibold transition ${
                            tab === t.key
                                ? 'border-indigo-600 text-indigo-700'
                                : t.ready
                                  ? 'border-transparent text-slate-500 hover:text-slate-800'
                                  : 'border-transparent text-slate-400'
                        }`}
                    >
                        {t.label}
                        {!t.ready && <span className="ml-0.5 align-super text-[8px]">•</span>}
                    </button>
                ))}
            </div>

            {tab !== 'manuscript' ? (
                <NotBuilt
                    title={tab === 'outline' ? 'Outline' : 'Notes'}
                    milestone="a later milestone"
                    what={
                        tab === 'outline'
                            ? 'A drag-and-drop structural view of the whole book.'
                            : 'Per-chapter notes kept beside the manuscript.'
                    }
                />
            ) : (
                <>
                    <div className="px-3 pt-3">
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search chapters…"
                            className="w-full rounded-md border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>

                    <nav className="flex-1 overflow-y-auto px-2 py-2">
                        {nodes.filter(matches).map((node) => (
                            <BinderNode
                                key={node.id}
                                node={node}
                                activeId={activeId}
                                onSelect={onSelect}
                                depth={0}
                            />
                        ))}
                        {nodes.filter(matches).length === 0 && (
                            <p className="px-2 py-3 text-[11px] text-slate-400">
                                No chapters match.
                            </p>
                        )}
                    </nav>

                    <ImportExport book={book} />

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
                </>
            )}
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
                        ? 'cursor-default text-[11px] font-bold uppercase tracking-wide text-slate-500'
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

/**
 * Import a Markdown manuscript, or take the whole book back out as one.
 *
 * The report is shown rather than swallowed: an author who uploads 90,000
 * words needs to know what could not be carried before they start editing,
 * not at proof stage.
 */
function ImportExport({ book }) {
    const [report, setReport] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const upload = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        setBusy(true);
        setError(null);
        setReport(null);

        try {
            const body = new FormData();
            body.append('file', file);

            const res = await window.axios.post(
                route('books.import', book.id),
                body,
            );
            setReport(res.data.report);

            // The binder is server-rendered, so it has to come back to show
            // the imported chapters.
            router.reload({ only: ['binder', 'book'] });
        } catch (err) {
            setError(
                err.response?.data?.message ??
                    'That file could not be imported.',
            );
        } finally {
            setBusy(false);
            e.target.value = '';
        }
    };

    return (
        <div className="border-t border-slate-200 p-3">
            <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Manuscript
            </p>

            <div className="flex gap-2">
                <label className="flex-1 cursor-pointer rounded-md border border-slate-300 bg-white py-1.5 text-center text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                    {busy ? 'Importing…' : 'Import .md'}
                    <input
                        type="file"
                        accept=".md,.markdown,.txt,text/markdown,text/plain"
                        onChange={upload}
                        disabled={busy}
                        className="hidden"
                    />
                </label>

                <a
                    href={route('books.export', book.id)}
                    className="flex-1 rounded-md border border-slate-300 bg-white py-1.5 text-center text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                >
                    Export .md
                </a>
            </div>

            {error && (
                <p className="mt-2 text-[11px] font-semibold text-rose-700">
                    {error}
                </p>
            )}

            {report && (
                <div className="mt-2 rounded bg-slate-100 px-2 py-1.5 text-[11px] leading-relaxed text-slate-700">
                    <p className="font-semibold">
                        Imported {report.sections} chapter
                        {report.sections === 1 ? '' : 's'}.
                    </p>
                    {report.lossless ? (
                        <p className="text-emerald-700">
                            Nothing was dropped.
                        </p>
                    ) : (
                        <p className="text-amber-700">
                            Could not carry:{' '}
                            {Object.entries(report.skipped)
                                .map(([what, n]) => `${n} ${what}${n === 1 ? '' : 's'}`)
                                .join(', ')}
                            .
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
