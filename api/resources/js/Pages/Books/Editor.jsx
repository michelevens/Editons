import ManuscriptEditor from '@/Components/Editor/ManuscriptEditor';
import AIEditorPanel from '@/Components/Studio/AIEditorPanel';
import AiEditorScreen from '@/Components/Studio/AiEditorScreen';
import AnalyticsPanel from '@/Components/Studio/AnalyticsPanel';
import AppHeader from '@/Components/Studio/AppHeader';
import BookSidebar from '@/Components/Studio/BookSidebar';
import { BottomRow } from '@/Components/Studio/BottomCards';
import ConsistencyPanel from '@/Components/Studio/ConsistencyPanel';
import ManuscriptOutline from '@/Components/Studio/ManuscriptOutline';
import ReferencesPanel from '@/Components/Studio/ReferencesPanel';
import ResearchLibrary from '@/Components/Studio/ResearchLibrary';
import TemplatesPanel from '@/Components/Studio/TemplatesPanel';
import WorkflowBoard from '@/Components/Studio/WorkflowBoard';
import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * The Writing Studio.
 *
 * Layout follows HANDOFF.md: a 223px sidebar beside a 268 / 1fr / 392 grid,
 * with a bottom row spanning the two right columns. At 1536 wide it lands on
 * the mockup's coordinates; below 1280 the AI panel becomes a drawer and
 * below 1024 the sidebar does, as the responsive section requires.
 *
 * Destinations without an engine are present and navigable but lead to an
 * explicit empty state. Hiding them would make the product impossible to
 * evaluate; faking them would misrepresent it.
 */
export default function Editor({ book, binder, activeSection, agents, sources, pane: initialPane }) {
    const { auth } = usePage().props;

    const [section, setSection] = useState(activeSection);
    const [bookWords, setBookWords] = useState(book.word_count);
    const [versions, setVersions] = useState([]);
    const [findings, setFindings] = useState([]);
    const [library, setLibrary] = useState(sources ?? []);

    const [running, setRunning] = useState(null);
    const [reviewProgress, setReviewProgress] = useState(null);
    const [adding, setAdding] = useState(false);
    const [toast, setToast] = useState(null);

    const [nav, setNav] = useState('writing');
    const [pane, setPane] = useState(initialPane ?? 'manuscript');
    const [leftTab, setLeftTab] = useState('Manuscript');
    const [panelOpen, setPanelOpen] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(false);

    useEffect(() => setSection(activeSection), [activeSection?.id]);

    const notify = useCallback((message) => {
        setToast(message);
        window.setTimeout(() => setToast(null), 3200);
    }, []);

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

    const openSection = (id) =>
        router.get(
            route('books.edit', book.id),
            { section: id },
            { preserveState: true, preserveScroll: true },
        );

    const runAgent = async (agent) => {
        setRunning(agent);

        try {
            const res = await window.axios.post(`/sections/${section.id}/review`, {
                agent,
            });
            setFindings(res.data.findings);
        } finally {
            setRunning(null);
        }
    };

    /**
     * Run every agent over the open chapter, one after another.
     *
     * Sequential rather than parallel: each run replaces that agent's own open
     * findings, and the progress label is only meaningful if the steps are
     * ordered. The driver answers in milliseconds, so this costs nothing in
     * wall-clock time today — and when a real provider replaces it, this is
     * the call site that moves behind a queue.
     */
    const runFullReview = async () => {
        if (!section || reviewProgress) return;

        let latest = findings;

        for (const [index, agent] of agents.entries()) {
            setReviewProgress({
                label: `Reviewing ${section.title}… ${index + 1}/${agents.length}`,
            });

            try {
                const res = await window.axios.post(
                    `/sections/${section.id}/review`,
                    { agent: agent.value },
                );
                latest = res.data.findings;
                setFindings(latest);
            } catch {
                notify(`${agent.label} could not finish. The rest still ran.`);
            }
        }

        setReviewProgress(null);

        const open = latest.filter((f) => f.status === 'open').length;

        notify(
            open === 0
                ? 'Review finished. Nothing open on this chapter.'
                : `Review finished. ${open} open finding${open === 1 ? '' : 's'}.`,
        );
    };

    const resolve = async (finding, status) => {
        const res = await window.axios.patch(`/findings/${finding.id}`, { status });
        setFindings(res.data.findings);
        notify(status === 'accepted' ? 'Finding applied.' : 'Finding resolved.');
    };

    const restore = async (versionId) => {
        if (
            !confirm('Restore this version? Your current text is saved to history first.')
        ) {
            return;
        }

        const res = await window.axios.post(
            `/sections/${section.id}/versions/${versionId}/restore`,
        );

        setSection((current) => ({
            ...current,
            content: res.data.content,
            word_count: res.data.word_count,
            revision: (current.revision ?? 0) + 1,
        }));

        load();
        notify('Version restored.');
    };

    /**
     * Scroll the open chapter to a finding's text and select it.
     *
     * Matches on the stored excerpt rather than character offsets: the
     * document moves after a review runs, and the excerpt is what still
     * identifies the place. A finding whose text is gone is already marked
     * stale, and this says so rather than scrolling nowhere.
     */
    const showInText = (finding) => {
        if (!finding.excerpt) return;

        const root = document.querySelector('.manuscript .ProseMirror');
        if (!root) return;

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const needle = finding.excerpt.slice(0, 60);

        while (walker.nextNode()) {
            const node = walker.currentNode;
            const at = node.textContent.indexOf(needle);

            if (at === -1) continue;

            const range = document.createRange();
            range.setStart(node, at);
            range.setEnd(node, Math.min(at + needle.length, node.textContent.length));

            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            node.parentElement?.scrollIntoView({ behavior: 'smooth', block: 'center' });

            return;
        }

        notify('That text is no longer in this chapter.');
    };

    const addChapter = () => {
        setAdding(true);

        router.post(
            route('sections.store', book.id),
            {
                type: 'chapter',
                title: `${countChapters(binder) + 1}. Untitled Chapter`,
            },
            { preserveScroll: true, onFinish: () => setAdding(false) },
        );
    };

    /**
     * Append a template's blocks to the open chapter.
     *
     * Goes through the same save endpoint as typing, so the insertion is
     * versioned and revertible rather than a special kind of edit.
     */
    const insertTemplate = async (blocks) => {
        if (!section) return;

        const current = section.content?.content ?? [];
        const content = { type: 'doc', content: [...current, ...blocks] };

        const res = await window.axios.put(`/sections/${section.id}/content`, {
            content,
            reason: 'manual',
        });

        setSection((value) => ({
            ...value,
            content,
            word_count: res.data.word_count,
            // Forces the editor to re-read: the chapter has not changed, but
            // its content has.
            revision: (value.revision ?? 0) + 1,
        }));
        setBookWords(res.data.book_word_count);
        notify('Block inserted at the end of the chapter.');
        load();
    };

    const refreshLibrary = useCallback(async () => {
        const res = await window.axios.get(`/books/${book.id}/sources`);
        setLibrary(res.data.sources);
    }, [book.id]);

    const openLibrary = () => {
        setNav('writing');
        setPane('research');
        setPanelOpen(false);
    };

    const liveNav = ['writing', 'ai', 'books', 'research', 'analytics'].includes(nav);
    const effectivePane = nav === 'research' ? 'research' : pane;
    const isStudio = liveNav && effectivePane === 'manuscript';
    const openFindings = findings.filter((f) => f.status === 'open');

    const panelProps = {
        agents,
        findings,
        versions,
        running,
        reviewProgress,
        sectionId: section?.id,
        bookId: book.id,
        onRunAgent: runAgent,
        onRunFullReview: runFullReview,
        onResolve: resolve,
        onShowInText: showInText,
        onRestore: restore,
        onOpenLibrary: openLibrary,
    };

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={book.title} />

            <AppHeader
                active={nav}
                onSelect={(item) => {
                    setNav(item.key);
                    setSidebarOpen(false);
                }}
                user={auth?.user}
                onOpenPalette={() => notify('The command palette is not built yet.')}
                onOpenNotifications={() => notify('Notifications are not built yet.')}
            />

            <div className="flex min-h-0 flex-1">
                {/* Static at >=1024, off-canvas below. */}
                <div
                    className={`max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:pt-[54px] max-lg:transition-transform ${
                        sidebarOpen ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'
                    }`}
                >
                    <BookSidebar
                        book={{ ...book, word_count: bookWords }}
                        active={liveNav ? effectivePane : null}
                        badges={{ suggestions: openFindings.length }}
                        onSelect={(item) => {
                            if (item.key === 'overview') {
                                router.get(route('books.overview', book.id));

                                return;
                            }

                            if (item.key === 'settings') {
                                router.get(route('books.settings', book.id));

                                return;
                            }

                            setNav('writing');
                            setPane(item.key);
                            setSidebarOpen(false);
                        }}
                    />
                </div>

                {sidebarOpen && (
                    <button
                        aria-label="Close menu"
                        onClick={() => setSidebarOpen(false)}
                        className="fixed inset-0 z-30 bg-black/25 lg:hidden"
                    />
                )}

                <main className="min-w-0 flex-1 overflow-hidden p-[11px]">
                    {nav === 'analytics' ? (
                        <Panel>
                            <AnalyticsPanel bookId={book.id} onOpenSection={openSection} />
                        </Panel>
                    ) : nav === 'ai' ? (
                        <Panel>
                            <AiEditorScreen bookId={book.id} onOpenSection={openSection} />
                        </Panel>
                    ) : !liveNav ? (
                        <NotBuilt
                            title={labelFor(nav)}
                            what="This area of the studio has no engine behind it yet."
                            milestone={milestoneFor(nav)}
                        />
                    ) : effectivePane === 'research' ? (
                        <Panel>
                            <ResearchLibrary bookId={book.id} onChanged={refreshLibrary} />
                        </Panel>
                    ) : effectivePane === 'references' ? (
                        <Panel>
                            <ReferencesPanel bookId={book.id} onOpenSection={openSection} />
                        </Panel>
                    ) : effectivePane === 'terms' ? (
                        <Panel>
                            <ConsistencyPanel bookId={book.id} onOpenSection={openSection} />
                        </Panel>
                    ) : effectivePane === 'templates' ? (
                        <Panel>
                            <TemplatesPanel section={section} onInsert={insertTemplate} />
                        </Panel>
                    ) : effectivePane === 'workflow' ? (
                        <Panel>
                            <WorkflowBoard bookId={book.id} onOpenSection={openSection} />
                        </Panel>
                    ) : !isStudio ? (
                        <NotBuilt
                            title={paneLabel(effectivePane)}
                            what="This section of the book workspace has no engine behind it yet."
                            milestone="a later milestone"
                        />
                    ) : (
                        <div className="grid h-full min-h-0 grid-rows-[minmax(0,1fr)_auto] gap-[11px]">
                            <div className="grid min-h-0 gap-[10px] [grid-template-columns:268px_minmax(0,1fr)] xl:[grid-template-columns:268px_minmax(0,1fr)_392px]">
                                <ManuscriptOutline
                                    nodes={binder}
                                    activeId={section?.id}
                                    tab={leftTab}
                                    onTab={setLeftTab}
                                    onSelect={openSection}
                                    onAddChapter={addChapter}
                                    adding={adding}
                                />

                                <section className="manuscript flex min-h-0 min-w-0 flex-col overflow-hidden rounded-panel border border-border-default bg-bg-surface">
                                    <ManuscriptEditor section={section} onSaved={onSaved} />
                                </section>

                                <div className="flex min-h-0 max-xl:hidden">
                                    <AIEditorPanel {...panelProps} />
                                </div>
                            </div>

                            <div className="h-[236px] min-h-0">
                                <BottomRow
                                    bookId={book.id}
                                    sources={library}
                                    onOpenLibrary={openLibrary}
                                    onAddSource={openLibrary}
                                />
                            </div>
                        </div>
                    )}
                </main>
            </div>

            {/* Affordances for the two panels that leave the grid on smaller
                screens. Without these they would be unreachable, not merely
                hidden. */}
            {isStudio && (
                <div className="pointer-events-none fixed inset-x-0 bottom-4 z-30 flex justify-center gap-2 xl:hidden">
                    <button
                        onClick={() => setSidebarOpen(true)}
                        className="pointer-events-auto rounded-badge bg-bg-sidebar px-4 py-2 text-[12px] font-semibold text-white shadow-popover lg:hidden"
                    >
                        Menu
                    </button>
                    <button
                        onClick={() => setPanelOpen(true)}
                        className="pointer-events-auto rounded-badge bg-brand-primary px-4 py-2 text-[12px] font-semibold text-white shadow-popover"
                    >
                        AI Editor
                        {openFindings.length > 0 && ` (${openFindings.length})`}
                    </button>
                </div>
            )}

            {panelOpen && (
                <div className="fixed inset-0 z-40 flex justify-end xl:hidden">
                    <button
                        aria-label="Close panel"
                        onClick={() => setPanelOpen(false)}
                        className="flex-1 bg-black/25"
                    />
                    <div className="h-full max-w-[92vw] bg-bg-surface shadow-popover">
                        <AIEditorPanel {...panelProps} />
                    </div>
                </div>
            )}

            {toast && (
                <div
                    role="status"
                    className="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-control bg-text-primary px-4 py-2.5 text-[12.5px] font-medium text-white shadow-popover"
                >
                    {toast}
                </div>
            )}
        </div>
    );
}

function Panel({ children }) {
    return (
        <div className="h-full overflow-y-auto rounded-panel border border-border-default bg-bg-surface">
            {children}
        </div>
    );
}

function NotBuilt({ title, what, milestone }) {
    return (
        <div className="grid h-full place-items-center rounded-panel border border-border-default bg-bg-surface p-10">
            <div className="max-w-sm text-center">
                <h3 className="text-[16px] font-semibold text-text-primary">{title}</h3>
                <p className="mt-2 text-[12.5px] leading-relaxed text-text-secondary">
                    {what}
                </p>
                <p className="mt-4 inline-block rounded-badge bg-bg-subtle px-3 py-1 text-[11.5px] font-semibold text-text-secondary">
                    Arrives in {milestone}
                </p>
                <p className="mt-4 text-[11px] leading-relaxed text-text-secondary/75">
                    This screen is deliberately empty. Showing a mock-up here
                    would misrepresent what the application can do.
                </p>
            </div>
        </div>
    );
}

function countChapters(nodes) {
    return nodes.reduce(
        (total, node) =>
            total + (node.type === 'part' ? 0 : 1) + countChapters(node.children ?? []),
        0,
    );
}

const NAV_LABELS = {
    design: 'Design',
    publishing: 'Publishing',
    sales: 'Sales',
    analytics: 'Analytics',
};

const PANE_LABELS = {
    overview: 'Overview',
    notes: 'Notes',
    templates: 'Templates',
    settings: 'Book Settings',
    comments: 'Comments',
    suggestions: 'Suggestions',
    workflow: 'Review Workflow',
    team: 'Team Members',
    layout: 'Design & Layout',
    cover: 'Cover Studio',
    export: 'Export & Publish',
    distribution: 'Sales & Distribution',
};

const labelFor = (key) => NAV_LABELS[key] ?? key;
const paneLabel = (key) => PANE_LABELS[key] ?? key;

function milestoneFor(nav) {
    if (nav === 'design' || nav === 'publishing') return 'Milestone 3';
    if (nav === 'sales' || nav === 'analytics') return 'Milestone 5';

    return 'a later milestone';
}
