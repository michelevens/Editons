import Dialog, { CloseButton } from '@/Components/Dialog';
import ManuscriptEditor from '@/Components/Editor/ManuscriptEditor';
import AIEditorPanel from '@/Components/Studio/AIEditorPanel';
import AiEditorScreen from '@/Components/Studio/AiEditorScreen';
import AnalyticsPanel from '@/Components/Studio/AnalyticsPanel';
import AppHeader from '@/Components/Studio/AppHeader';
import { BottomRow } from '@/Components/Studio/BottomCards';
import AccessibilityPanel from '@/Components/Studio/AccessibilityPanel';
import CommentsPanel from '@/Components/Studio/CommentsPanel';
import ConsistencyPanel from '@/Components/Studio/ConsistencyPanel';
import CreditStrip from '@/Components/Studio/CreditStrip';
import CoachStrip from '@/Components/Studio/CoachStrip';
import CoverPanel from '@/Components/Studio/CoverPanel';
import EditionsPanel from '@/Components/Studio/EditionsPanel';
import GoalsSheet from '@/Components/Studio/GoalsSheet';
import ImportDialog from '@/Components/Studio/ImportDialog';
import LaunchPanel from '@/Components/Studio/LaunchPanel';
import LockedPanel from '@/Components/Studio/LockedPanel';
import MarketingPanel from '@/Components/Studio/MarketingPanel';
import LayoutPanel from '@/Components/Studio/LayoutPanel';
import PublishFlow from '@/Components/Studio/PublishFlow';
import PublishPanel from '@/Components/Studio/PublishPanel';
import BookPreview from '@/Components/Studio/BookPreview';
import RightsPanel from '@/Components/Studio/RightsPanel';
import SalesPanel from '@/Components/Studio/SalesPanel';
import Icon from '@/Components/Studio/Icon';
import ManuscriptOutline from '@/Components/Studio/ManuscriptOutline';
import MobileNav from '@/Components/Studio/MobileNav';
import NotesPanel from '@/Components/Studio/NotesPanel';
import ReferencesPanel from '@/Components/Studio/ReferencesPanel';
import ResearchLibrary from '@/Components/Studio/ResearchLibrary';
import Splitter from '@/Components/Studio/Splitter';
import StudioSidebar from '@/Components/Studio/StudioSidebar';
import TeamPanel from '@/Components/Studio/TeamPanel';
import TemplatesPanel from '@/Components/Studio/TemplatesPanel';
import ViewMenu, { PANEL_LABELS } from '@/Components/Studio/ViewMenu';
import WorkflowBoard from '@/Components/Studio/WorkflowBoard';
import { destination } from '@/Components/Studio/navigation';
import {
    BUILTIN_PRESETS,
    CANVAS_FONTS,
    canvasMeasure,
    SHORTCUTS,
    shortcutLabel,
    SIZE_LIMITS,
    useWorkspace,
} from '@/Components/Studio/workspace';
import { matches, usePrefs } from '@/shortcuts';
import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

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
/**
 * A house book (ADR — house catalog): owned by one of the platform's own
 * imprints, opened by staff as themselves. Says so on every pane, and whose
 * name the versions are saved under.
 */
function HouseBanner({ house }) {
    return (
        <div
            role="note"
            data-a="editor:house-banner"
            className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-status-warning-badge bg-status-warning-bg px-4 py-2 text-[12.5px] text-text-primary sm:rounded-tile sm:border"
        >
            <span className="font-semibold">
                {house.can_edit ? `House book — edits are saved as you, ${house.editor}` : 'House book — read only'}
            </span>
            <span className="text-text-secondary">{house.imprint}</span>
            <a href={house.console} className="ml-auto font-semibold text-brand-primary hover:underline">
                House catalog
            </a>
        </div>
    );
}

/** The width of a panel collapsed to its icons. */
const RAIL = 44;

/** The narrowest the manuscript may be squeezed by a splitter. */
const MIN_MANUSCRIPT = 360;

export default function Editor({ book, binder, activeSection, agents, sources, pane: initialPane, house = null, workspace = null }) {
    const { auth } = usePage().props;

    // The author's arrangement of the studio (ADR — customisable writing
    // workspace), from the server on first paint.
    const ws = useWorkspace({ initial: workspace, bookId: book.id, userId: auth?.user?.id });
    const layout = ws.settings;

    const [section, setSection] = useState(activeSection);
    const [bookWords, setBookWords] = useState(book.word_count);
    const [versions, setVersions] = useState([]);
    const [findings, setFindings] = useState([]);

    // Observations about the chapter in front of the author, for somebody who
    // asked to be coached. Empty for everybody else.
    const [coaching, setCoaching] = useState([]);
    const [library, setLibrary] = useState(sources ?? []);

    // The balance the server returned with the last review, handed to the
    // credit strip so it moves with the spend rather than a request behind it.
    const [credits, setCredits] = useState(null);

    const [binderOpen, setBinderOpen] = useState(false);

    const [running, setRunning] = useState(null);
    const [reviewProgress, setReviewProgress] = useState(null);
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);
    const [toast, setToast] = useState(null);

    // The goals sheet: which chapter it is about, and the editor's live word
    // count when it was opened from the chapter header.
    const [goals, setGoals] = useState(null);

    // ?pane= may name a top-nav area (ai, analytics...) as well as a
    // sidebar screen; destination() knows which is which.
    const [nav, setNav] = useState(() => destination(initialPane).nav);
    const [pane, setPane] = useState(() => destination(initialPane).pane);

    // The agent the AI Editor should open filtered to, from Analytics.
    const [aiAgent, setAiAgent] = useState(null);

    // Where the full-screen preview returns to when it closes: the screen
    // that opened it (Design, Export, or wherever More was tapped from).
    const here = useRef({ nav: null, pane: null });
    here.current = { nav, pane };
    const beforePreview = useRef({ nav: 'publishing', pane: 'export' });

    const go = useCallback((key) => {
        const to = destination(key);

        if (to.pane === 'preview' && here.current.pane !== 'preview' && here.current.nav) {
            beforePreview.current = here.current;
        }

        setAiAgent(null);
        setNav(to.nav);
        setPane(to.pane);
        setSidebarOpen(false);
    }, []);
    const [leftTab, setLeftTab] = useState('Manuscript');
    const [panelOpen, setPanelOpen] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const closeSidebar = useCallback(() => setSidebarOpen(false), []);

    // Distraction-free writing: the manuscript alone, everything else out of
    // sight. Ctrl/Cmd+Shift+F toggles it, Escape leaves it (a dialog open on
    // top takes Escape first; see Components/Dialog).
    const [focusMode, setFocusMode] = useState(false);

    // The panel shortcuts (workspace.js SHORTCUTS), through a ref so the
    // listener is added once and still calls today's handlers.
    const shortcuts = useRef({});
    // Re-rendered when the author remaps one, so labels follow.
    const prefs = usePrefs();

    useEffect(() => {
        const onKey = (event) => {
            if (SHORTCUTS.focus.match(event)) {
                event.preventDefault();
                setFocusMode((on) => !on);
            } else if (matches('view.leave', event)) {
                setFocusMode(false);
            } else {
                for (const name of ['outline', 'right', 'bottom']) {
                    if (SHORTCUTS[name].match(event) && shortcuts.current[name]) {
                        event.preventDefault();
                        shortcuts.current[name]();

                        return;
                    }
                }
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => setSection(activeSection), [activeSection?.id]);

    // The last text selected in the chapter, for the AI Editor's "Review
    // the selection" (ADR — Style guides and review options). Kept when the
    // author moves to that screen, which is when the selection collapses.
    const [lastSelection, setLastSelection] = useState(null);

    useEffect(() => {
        const onChange = () => {
            const selected = window.getSelection();

            if (!selected || selected.isCollapsed || !section) return;

            const node = selected.anchorNode instanceof Element ? selected.anchorNode : selected.anchorNode?.parentElement;

            if (!node?.closest('.ProseMirror')) return;

            const text = selected.toString().trim();

            if (text) setLastSelection({ sectionId: section.id, text: text.slice(0, 20000) });
        };

        document.addEventListener('selectionchange', onChange);

        return () => document.removeEventListener('selectionchange', onChange);
    }, [section?.id]);

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

        // Arrives with the findings rather than on its own request: the
        // editor already makes this call every time a chapter opens.
        setCoaching(f.data.coaching ?? []);
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

    // Back to the manuscript as well: a chapter link inside a full-screen
    // panel used to load the chapter behind the panel, so the click looked
    // like it did nothing.
    //
    // The open chapter's last edit is saved first. Asking for the next
    // chapter while it was still on its way could bring back, on a quick
    // return, the copy from before it.
    const manuscript = useRef(null);

    // Suggest mode: the open chapter's pending changes, as the editor reads
    // them, for the list in the Comments tab; and a request for that tab.
    const [changes, setChanges] = useState([]);
    const [panelTab, setPanelTab] = useState(null);
    const wide = () => window.matchMedia('(min-width: 1280px)').matches;

    const showChanges = useCallback(() => {
        setPanelTab({ tab: 'Comments', at: Date.now() });
        // Below 1280 the panel is a drawer.
        if (!wide()) setPanelOpen(true);
    }, []);

    const openSection = useCallback(
        async (id) => {
            setNav('writing');
            setPane('manuscript');
            setPanelOpen(false);
            await manuscript.current?.flush();
            router.get(
                route('books.edit', book.id),
                { section: id },
                { preserveState: true, preserveScroll: true },
            );
        },
        [book.id],
    );

    const navigate = useCallback(
        async (key) => {
            if (['books', 'overview', 'settings'].includes(key)) {
                await manuscript.current?.flush();
            }

            if (key === 'books') {
                router.get(route('books.index'));
            } else if (key === 'overview') {
                router.get(route('books.overview', book.id));
            } else if (key === 'settings') {
                router.get(route('books.settings', book.id));
            } else {
                go(key);
            }
        },
        [book.id, go],
    );

    const chapters = useMemo(() => flatten(binder), [binder]);

    const runAgent = async (agent) => {
        setRunning(agent);

        try {
            const res = await window.axios.post(`/sections/${section.id}/review`, {
                agent,
            });
            setFindings(res.data.findings);
            setCredits(res.data.billing?.credits ?? null);
        } catch (error) {
            // Previously this had no catch, so a refusal — the plan does not
            // include this agent, or the balance will not cover it — failed
            // silently and the spinner just stopped. The server's message
            // names the plan or the shortfall, so it is worth showing.
            const refusal = error.response?.data?.errors;

            notify(
                refusal?.agent?.[0]
                    ?? refusal?.credits?.[0]
                    ?? 'That review could not be run.',
            );
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
                setCredits(res.data.billing?.credits ?? null);
            } catch (error) {
                const refusal = error.response?.data?.errors;

                // A refusal is worth naming: "could not finish" reads like a
                // fault when the real reason is the plan or the balance.
                notify(
                    refusal?.agent?.[0]
                        ?? refusal?.credits?.[0]
                        ?? `${agent.label} could not finish. The rest still ran.`,
                );
            }
        }

        setReviewProgress(null);

        const open = latest.filter((f) => f.status === 'open').length;

        // Names the chapter, because that is what ran. The button beside it
        // is the one that reads the book.
        notify(
            open === 0
                ? `Nothing open on ${section.title}.`
                : `${section.title}: ${open} open finding${open === 1 ? '' : 's'}.`,
        );
    };

    const resolve = async (finding, status) => {
        try {
            const res = await window.axios.patch(`/findings/${finding.id}`, { status });

            setFindings(res.data.findings);

            // An applied suggestion rewrites the chapter, so the editor has
            // to re-read it. Without the revision bump it would keep showing
            // the text as it was before the fix.
            if (res.data.applied && res.data.content) {
                setSection((value) => ({
                    ...value,
                    content: res.data.content,
                    word_count: res.data.word_count,
                    revision: (value.revision ?? 0) + 1,
                }));
                setBookWords((words) => words);
                load();
            }

            notify(
                status === 'accepted'
                    ? res.data.applied
                        ? 'Fix applied to the chapter.'
                        : 'Finding marked as handled.'
                    : 'Finding dismissed.',
            );

            return true;
        } catch (failure) {
            // 409 means the passage moved or is ambiguous and nothing was
            // changed. Saying so beats letting the finding vanish.
            notify(
                failure.response?.data?.message ??
                    'That could not be applied.',
            );

            if (failure.response?.data?.findings) {
                setFindings(failure.response.data.findings);
            }

            // So the one-at-a-time review does not offer to undo a decision
            // that never happened.
            return false;
        }
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

    // A chapter's goal changed in the goals sheet: the header ring reads the
    // open chapter, the binder's rings read the binder, which reloads alone.
    const chapterGoalSaved = useCallback((id, goal) => {
        setSection((value) => (value?.id === id ? { ...value, word_goal: goal } : value));
        router.reload({ only: ['binder'] });
    }, []);

    // The binder's row actions (long press, right-click, Shift+F10).
    const binderActions = {
        onSetGoal: (id) => setGoals({ sectionId: id, liveWords: null }),
        onRenamed: (id, title) => setSection((value) => (value?.id === id ? { ...value, title } : value)),
        // Deleting the open chapter leaves nothing to show: open the book
        // afresh, which lands on another chapter. Any other only redraws
        // the binder and the book's count.
        onDeleted: (id) => {
            if (id === section?.id) {
                setBinderOpen(false);
                router.get(route('books.edit', book.id));
            } else {
                router.reload({ only: ['binder'] });
            }

            notify('Chapter deleted.');
        },
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

    const liveNav = [
        'writing', 'ai', 'books', 'research', 'analytics', 'design', 'publishing',
        'sales',
    ].includes(nav);
    const effectivePane = nav === 'research' ? 'research' : pane;
    const isStudio = liveNav && effectivePane === 'manuscript';
    // Only the manuscript has a distraction-free form.
    const focused = focusMode && isStudio;
    const openFindings = findings.filter((f) => f.status === 'open');

    // --- The workspace: panels, splitters, presets ----------------------

    // A region is shown when the author keeps it and distraction-free is off.
    const shown = (key) => !focused && layout.panels[key];

    // Sizes while a splitter is being dragged; saved once, on release.
    const [live, setLive] = useState({});
    const size = (key) => live[key] ?? layout.sizes[key];

    // The room the side panels share, so a splitter cannot squeeze the
    // manuscript below MIN_MANUSCRIPT.
    const row = useRef(null);
    const [rowWidth, setRowWidth] = useState(0);

    useEffect(() => {
        if (!row.current || typeof ResizeObserver === 'undefined') return undefined;

        const observer = new ResizeObserver(([entry]) => setRowWidth(entry.contentRect.width));
        observer.observe(row.current);

        return () => observer.disconnect();
    }, [isStudio]);

    /**
     * Never lose focus to a panel closing: if what had focus is gone or
     * hidden once the change lands, focus moves to `fallback` (or the
     * manuscript), rather than dropping to <body>.
     */
    const keepFocus = useCallback((fallback = null) => {
        window.requestAnimationFrame(() => {
            const now = document.activeElement;
            const lost = !now || now === document.body || !now.isConnected || now.getClientRects().length === 0;

            if (!lost) return;

            const target = (fallback && document.querySelector(fallback)) ?? document.querySelector('.manuscript .ProseMirror');

            target?.focus({ preventScroll: true });
        });
    }, []);

    const togglePanel = useCallback((key) => {
        keepFocus();
        ws.update((current) => ({ panels: { [key]: !current.panels[key] } }));
    }, [keepFocus, ws.update]);

    const toggleRail = useCallback((key, expandTo = null) => {
        const collapsing = !layout.rails[key];

        ws.update((current) => ({ rails: { [key]: !current.rails[key] }, panels: { [key]: true } }));
        // Into the rail's first button, or back into the panel.
        keepFocus(collapsing ? `[data-a="rail:${key}:expand"]` : expandTo);
    }, [keepFocus, layout.rails, ws.update]);

    const applyPreset = useCallback((preset) => {
        keepFocus();
        ws.update(preset.layout, { preset: preset.name });

        if (preset.tab) setPanelTab({ tab: preset.tab, at: Date.now() });
    }, [keepFocus, ws.update]);

    // Below their breakpoints the side panels are drawers, and their
    // shortcuts open and close those instead.
    shortcuts.current = isStudio
        ? {
              outline: () => (window.matchMedia('(min-width: 768px)').matches ? togglePanel('outline') : setBinderOpen((open) => !open)),
              right: () => (wide() ? togglePanel('right') : setPanelOpen((open) => !open)),
              bottom: () => togglePanel('bottom'),
          }
        : {};

    // View, in search as well: every panel, layout and the reset.
    const viewCommands = useMemo(() => {
        if (!isStudio) return [];

        const list = Object.entries(PANEL_LABELS).map(([key, label]) => ({
            key: `view:${key}`,
            label: `${layout.panels[key] ? 'Hide' : 'Show'} ${label.toLowerCase()}`,
            group: 'View',
            icon: 'layout',
            shortcut: ['outline', 'right', 'bottom'].includes(key) ? shortcutLabel(key) : null,
            run: () => togglePanel(key),
        }));

        list.push({
            key: 'view:focus',
            label: focused ? 'Leave distraction-free mode' : 'Distraction-free mode',
            group: 'View',
            icon: 'expand',
            shortcut: shortcutLabel('focus'),
            run: () => setFocusMode((on) => !on),
        });
        list.push({
            key: 'view:swap',
            label: 'Swap side panels',
            group: 'View',
            icon: 'layout',
            run: () => ws.update((current) => ({ swap: !current.swap })),
        });

        for (const preset of [...BUILTIN_PRESETS, ...ws.presets]) {
            list.push({
                key: `view:preset:${preset.id}`,
                label: `Layout: ${preset.name}`,
                group: 'View',
                icon: 'layout',
                run: () => applyPreset(preset),
            });
        }

        list.push({
            key: 'view:reset',
            label: 'Reset workspace layout',
            group: 'View',
            icon: 'reset',
            run: () => ws.reset(),
        });

        return list;
    }, [isStudio, layout.panels, focused, ws.presets, ws.update, ws.reset, togglePanel, applyPreset, prefs]);

    // Room left for one side panel, given the other and the manuscript.
    const roomFor = (key) => {
        if (!rowWidth) return null;

        const other = key === 'outline' ? 'right' : 'outline';
        const otherShown = layout.panels[other] && (other === 'outline' || wide());
        const otherWidth = !otherShown ? 0 : layout.rails[other] ? RAIL : size(other);

        return rowWidth - otherWidth - MIN_MANUSCRIPT - 20;
    };

    const outlineShown = shown('outline');
    const rightShown = shown('right');
    const cardsKept = Object.values(layout.subpanels.bottomCards).some(Boolean);
    const bottomShown = shown('bottom') && cardsKept;
    const measure = focused ? null : canvasMeasure(layout.canvas);
    const canvasStyle = {
        '--ws-font': CANVAS_FONTS.find((f) => f.key === layout.canvas.font)?.css,
        '--ws-size': `${layout.canvas.fontSize}px`,
        '--ws-leading': layout.canvas.lineHeight,
        ...(measure ? { '--ws-measure': `${measure}px` } : {}),
    };

    // Undo for a decided finding: an applied fix comes back out of the
    // chapter through the server's own undo, then the finding is open again.
    const reopen = async (finding) => {
        try {
            const res = await window.axios.patch(`/findings/${finding.id}/reopen`);

            if (res.data.undone && res.data.content) {
                setSection((value) => ({
                    ...value,
                    content: res.data.content,
                    word_count: res.data.word_count,
                    revision: (value.revision ?? 0) + 1,
                }));
            }

            await load();
            notify(res.data.undone ? 'Fix undone.' : 'Finding reopened.');
        } catch (failure) {
            notify(failure.response?.data?.message ?? 'Could not undo that.');
        }
    };

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
        onReopen: reopen,
        onShowInText: showInText,
        onOpenSection: openSection,
        onRestore: restore,
        onOpenLibrary: openLibrary,
        changes,
        onDecide: (ids, accept) => manuscript.current?.decide(ids, accept),
        onShowChange: (change) => {
            // On a phone the list is in a drawer over the text.
            if (!wide()) setPanelOpen(false);
            window.setTimeout(() => manuscript.current?.reveal(change.id), 50);
        },
        tabRequest: panelTab,
    };

    return (
        <div className="relative flex h-dvh flex-col overflow-hidden bg-bg-app font-ui text-text-body">
            <Head title={book.title} />

            {/* Hidden (by the workspace or distraction-free), the header
                leaves search, its Ctrl+K and the skip link behind. */}
            <AppHeader
                bare={!shown('topNav')}
                extraCommands={viewCommands}
                active={nav}
                bookId={book.id}
                // The top nav names an area; destination() says which pane
                // it opens. "My Books" leaves the editor for the shelf.
                onSelect={(item) => navigate(item.key)}
                onNavigate={navigate}
                chapters={chapters}
                onOpenChapter={openSection}
                user={auth?.user}
                account={auth?.account}
                skipTo={isStudio ? '#manuscript' : '#main'}
                skipLabel={isStudio ? 'Skip to manuscript' : 'Skip to content'}
            />

            <div className="flex min-h-0 flex-1">
                {/* Static at >=1024, a drawer below: Components/Studio/StudioSidebar. */}
                {!focused && (
                    <StudioSidebar
                        hidden={!layout.panels.sidebar}
                        open={sidebarOpen}
                        onClose={closeSidebar}
                        activeNav={nav}
                        onSelectNav={(item) => navigate(item.key)}
                        book={{ ...book, word_count: bookWords }}
                        active={liveNav ? effectivePane : null}
                        badges={{ suggestions: openFindings.length }}
                        onSelect={(item) => navigate(item.key)}
                    />
                )}

                <main id="main" tabIndex={-1} className="flex min-w-0 flex-1 flex-col overflow-hidden outline-none sm:p-[11px]">
                    {house && <HouseBanner house={house} />}
                    {/* Above the panes: this is where credits are spent, so
                        this is where the balance belongs. */}
                    {/* Not on a phone: forty pixels of a 390px screen, on
                        every pane, for a figure the avatar menu also has. */}
                    {!focused && (
                        <div className={`max-sm:hidden ${layout.panels.credits ? '' : 'hidden'}`} data-ws-panel="credits">
                            <CreditStrip balance={credits} />
                        </div>
                    )}

                    {nav === 'analytics' ? (
                        <Panel>
                            <AnalyticsPanel bookId={book.id} onOpenSection={openSection} onOpenFindings={(agent) => { go('ai'); setAiAgent(agent); }} />
                        </Panel>
                    ) : nav === 'ai' ? (
                        <Panel>
                            <AiEditorScreen
                                bookId={book.id}
                                onOpenSection={openSection}
                                onNotify={notify}
                                agent={aiAgent}
                                currentSectionId={section?.id ?? null}
                                selection={lastSelection?.sectionId === section?.id ? lastSelection : null}
                            />
                        </Panel>
                    ) : !liveNav ? (
                        <NotBuilt
                            title={labelFor(nav)}
                            what="This area of the studio has no engine behind it yet."
                            milestone={milestoneFor(nav)}
                        />
                    ) : effectivePane === 'preview' ? (
                        // Full screen over everything, a dialog of its own.
                        <BookPreview
                            bookId={book.id}
                            onClose={() => {
                                setNav(beforePreview.current.nav);
                                setPane(beforePreview.current.pane);
                            }}
                        />
                    ) : effectivePane === 'layout' ? (
                        <Panel>
                            <LayoutPanel bookId={book.id} onNotify={notify} onPreview={() => go('preview')} />
                        </Panel>
                    ) : effectivePane === 'cover' ? (
                        <Panel>
                            <CoverPanel bookId={book.id} onNotify={notify} />
                        </Panel>
                    ) : effectivePane === 'publish' ? (
                        <Panel>
                            {/* Publish in three steps; a Fix link opens the pane that fixes it. */}
                            <PublishFlow bookId={book.id} onNotify={notify} onNavigate={navigate} />
                        </Panel>
                    ) : effectivePane === 'export' ? (
                        <Panel>
                            <PublishPanel bookId={book.id} onNotify={notify} onPreview={() => go('preview')} />
                        </Panel>
                    ) : effectivePane === 'rights' ? (
                        <Panel>
                            <RightsPanel bookId={book.id} onNotify={notify} />
                        </Panel>
                    ) : effectivePane === 'comments' ? (
                        <Panel>
                            {/* The same panel the AI drawer shows on its
                                Comments tab. It was built and tested but the
                                sidebar entry led nowhere. */}
                            <CommentsPanel
                                bookId={book.id}
                                sectionId={section?.id}
                                onOpenSection={openSection}
                            />
                        </Panel>
                    ) : effectivePane === 'notes' ? (
                        <Panel>
                            <NotesPanel bookId={book.id} onNotify={notify} />
                        </Panel>
                    ) : effectivePane === 'marketing' ? (
                        <Panel>
                            <MarketingPanel bookId={book.id} onNotify={notify} />
                        </Panel>
                    ) : LOCKED_SCREENS[effectivePane] ? (
                        <Panel>
                            <LockedPanel
                                bookId={book.id}
                                screen={effectivePane}
                                icon={LOCKED_SCREENS[effectivePane]}
                            />
                        </Panel>
                    ) : effectivePane === 'a11y' ? (
                        <Panel>
                            <AccessibilityPanel
                                bookId={book.id}
                                onOpenSection={openSection}
                                onNotify={notify}
                            />
                        </Panel>
                    ) : effectivePane === 'distribution' ? (
                        <Panel>
                            <SalesPanel bookId={book.id} variant="distribution" />
                        </Panel>
                    ) : effectivePane === 'royalties' ? (
                        <Panel>
                            <SalesPanel bookId={book.id} variant="royalties" />
                        </Panel>
                    ) : effectivePane === 'launch' ? (
                        <Panel>
                            <LaunchPanel bookId={book.id} onNotify={notify} />
                        </Panel>
                    ) : effectivePane === 'editions' ? (
                        <Panel>
                            <EditionsPanel bookId={book.id} onNotify={notify} />
                        </Panel>
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
                    ) : effectivePane === 'team' ? (
                        <Panel>
                            <TeamPanel bookId={book.id} />
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
                        <div className="flex min-h-0 flex-1 flex-col">
                            {/*
                              * The writing row: outline, manuscript, AI panel,
                              * each a panel the author can hide, resize,
                              * collapse to icons and swap (ADR — customisable
                              * writing workspace). Placed with CSS order, so
                              * swapping sides moves no DOM and the editor
                              * keeps its focus and selection.
                              *
                              * On a phone the manuscript is the whole screen.
                              * The binder and the AI panel become drawers,
                              * reached from the bar at the bottom, because a
                              * 268px column beside a 390px viewport leaves
                              * nothing to write in — which is what it did.
                              */}
                            <div ref={row} className="flex min-h-0 flex-1">
                                <div
                                    id="ws-outline"
                                    data-ws-panel="outline"
                                    data-rail={layout.rails.outline ? 'true' : undefined}
                                    className={`flex min-h-0 shrink-0 max-md:hidden ${outlineShown ? '' : 'hidden'}`}
                                    style={{ order: layout.swap ? 5 : 1, width: layout.rails.outline ? RAIL : size('outline') }}
                                >
                                    {layout.rails.outline && (
                                        <Rail
                                            name="outline"
                                            label="Chapter outline"
                                            side={layout.swap ? 'right' : 'left'}
                                            onExpand={() => toggleRail('outline', '#ws-outline [data-a="outline:search"]')}
                                            items={[
                                                { key: 'add', icon: 'plus', label: 'Add chapter', run: addChapter },
                                                { key: 'import', icon: 'fDoc', label: 'Import chapters', run: () => setImporting(true) },
                                            ]}
                                        />
                                    )}
                                    <div className={`flex min-h-0 w-full ${layout.rails.outline ? 'hidden' : ''}`}>
                                        <ManuscriptOutline
                                            nodes={binder}
                                            activeId={section?.id}
                                            tab={leftTab}
                                            onTab={(t) => (t === 'Notes' ? go('notes') : setLeftTab(t))}
                                            onSelect={openSection}
                                            onAddChapter={addChapter}
                                            onImport={() => setImporting(true)}
                                            adding={adding}
                                            showTabs={layout.subpanels.outlineTabs}
                                            showTools={layout.subpanels.outlineTools}
                                            showGoals={layout.subpanels.outlineGoals}
                                            {...binderActions}
                                        />
                                    </div>
                                </div>

                                {outlineShown && (
                                    layout.rails.outline ? (
                                        <div aria-hidden="true" className="w-[10px] shrink-0 max-md:hidden" style={{ order: layout.swap ? 4 : 2 }} />
                                    ) : (
                                        <Splitter
                                            label="Resize chapter outline"
                                            controls="ws-outline"
                                            data-a="splitter:outline"
                                            className="max-md:hidden"
                                            edge={layout.swap ? 'start' : 'end'}
                                            value={size('outline')}
                                            min={SIZE_LIMITS.outline.min}
                                            max={SIZE_LIMITS.outline.max}
                                            limit={roomFor('outline')}
                                            defaultValue={ws.defaults.sizes.outline}
                                            onLive={(n) => setLive({ outline: n })}
                                            onCommit={(n) => {
                                                setLive({});
                                                ws.update({ sizes: { outline: n } }, { preset: layout.preset });
                                            }}
                                            onCollapse={() => toggleRail('outline')}
                                            order={layout.swap ? 4 : 2}
                                        />
                                    )
                                )}

                                <section
                                    id="manuscript"
                                    aria-label="Manuscript"
                                    data-measure={measure ? 'true' : undefined}
                                    data-paper={layout.canvas.background === 'paper' ? 'true' : undefined}
                                    data-page={layout.canvas.pageView && !focused ? 'true' : undefined}
                                    style={{ order: 3, ...canvasStyle }}
                                    className={`manuscript flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden border-border-default bg-bg-surface sm:rounded-panel sm:border ${
                                        focused ? 'mx-auto w-full max-w-[860px]' : 'lg:max-xl:pb-12'
                                    }`}
                                >
                                    <ManuscriptEditor
                                        ref={manuscript}
                                        section={section}
                                        bookId={book.id}
                                        onSaved={onSaved}
                                        autosave={book.autosave ?? true}
                                        onChapters={() => setBinderOpen(true)}
                                        onChanges={setChanges}
                                        onShowChanges={showChanges}
                                        wordGoal={section?.word_goal ?? null}
                                        onGoals={(words) => setGoals({ sectionId: section.id, liveWords: words })}
                                        showToolbar={layout.panels.toolbar}
                                        toolbarStyle={layout.canvas.toolbarStyle}
                                        showStatusBar={layout.panels.statusBar}
                                        typewriter={layout.canvas.typewriter}
                                        onTypewriter={(on) => ws.update({ canvas: { typewriter: on } }, { preset: layout.preset })}
                                        headerActions={
                                            <>
                                                <ViewMenu
                                                    ws={ws}
                                                    bookId={book.id}
                                                    focused={focused}
                                                    onToggleFocus={() => setFocusMode((on) => !on)}
                                                    onTogglePanel={togglePanel}
                                                    onToggleRail={(key) => toggleRail(key)}
                                                    onApplyPreset={applyPreset}
                                                />
                                                <button
                                                    type="button"
                                                    data-a="editor:focus-mode"
                                                    onClick={() => setFocusMode((on) => !on)}
                                                    aria-pressed={focused}
                                                    aria-keyshortcuts={SHORTCUTS.focus.keys}
                                                    title={focused ? 'Leave distraction-free mode (Esc)' : `Distraction-free mode (${shortcutLabel('focus')})`}
                                                    className="grid h-7 w-7 shrink-0 place-items-center rounded-control text-text-secondary transition hover:bg-bg-subtle hover:text-text-primary"
                                                >
                                                    <Icon name="expand" size={15} />
                                                    <span className="sr-only">
                                                        {focused ? 'Leave distraction-free mode' : 'Distraction-free mode'}
                                                    </span>
                                                </button>
                                            </>
                                        }
                                    />

                                    {/* Under the manuscript, where somebody
                                        writing will meet it without going
                                        looking. Empty unless they asked to be
                                        coached. */}
                                    <CoachStrip
                                        notes={coaching}
                                        onDismiss={() => setCoaching([])}
                                    />
                                </section>

                                {rightShown && (
                                    layout.rails.right ? (
                                        <div aria-hidden="true" className="w-[10px] shrink-0 max-xl:hidden" style={{ order: layout.swap ? 2 : 4 }} />
                                    ) : (
                                        <Splitter
                                            label="Resize AI editor panel"
                                            controls="ws-right"
                                            data-a="splitter:right"
                                            className="max-xl:hidden"
                                            edge={layout.swap ? 'end' : 'start'}
                                            value={size('right')}
                                            min={SIZE_LIMITS.right.min}
                                            max={SIZE_LIMITS.right.max}
                                            limit={roomFor('right')}
                                            defaultValue={ws.defaults.sizes.right}
                                            onLive={(n) => setLive({ right: n })}
                                            onCommit={(n) => {
                                                setLive({});
                                                ws.update({ sizes: { right: n } }, { preset: layout.preset });
                                            }}
                                            onCollapse={() => toggleRail('right')}
                                            order={layout.swap ? 2 : 4}
                                        />
                                    )
                                )}

                                <div
                                    id="ws-right"
                                    data-ws-panel="right"
                                    data-rail={layout.rails.right ? 'true' : undefined}
                                    className={`flex min-h-0 shrink-0 max-xl:hidden ${rightShown ? '' : 'hidden'}`}
                                    style={{ order: layout.swap ? 1 : 5, width: layout.rails.right ? RAIL : size('right') }}
                                >
                                    {layout.rails.right && (
                                        <Rail
                                            name="right"
                                            label="AI editor panel"
                                            side={layout.swap ? 'left' : 'right'}
                                            onExpand={() => toggleRail('right', '#ws-right [role="tab"][aria-selected="true"]')}
                                            items={RIGHT_RAIL
                                                .filter((item) => layout.subpanels.rightTabs[item.key])
                                                .map((item) => ({
                                                    ...item,
                                                    count: item.key === 'editor' ? openFindings.length : item.key === 'comments' ? changes.length : 0,
                                                    run: () => {
                                                        setPanelTab({ tab: item.tab, at: Date.now() });
                                                        toggleRail('right', '#ws-right [role="tab"][aria-selected="true"]');
                                                    },
                                                }))}
                                        />
                                    )}
                                    <div className={`flex min-h-0 w-full ${layout.rails.right ? 'hidden' : ''}`}>
                                        <AIEditorPanel {...panelProps} visibleTabs={layout.subpanels.rightTabs} />
                                    </div>
                                </div>
                            </div>

                            {/* The bottom row is four cards of context. On a
                                phone that is four screens of scrolling before
                                the manuscript, so it waits for the tablet
                                breakpoint. */}
                            {bottomShown && (
                                <Splitter
                                    label="Resize bottom cards"
                                    controls="ws-bottom"
                                    data-a="splitter:bottom"
                                    className="max-lg:hidden"
                                    edge="top"
                                    value={size('bottom')}
                                    min={SIZE_LIMITS.bottom.min}
                                    max={SIZE_LIMITS.bottom.max}
                                    defaultValue={ws.defaults.sizes.bottom}
                                    onLive={(n) => setLive({ bottom: n })}
                                    onCommit={(n) => {
                                        setLive({});
                                        ws.update({ sizes: { bottom: n } }, { preset: layout.preset });
                                    }}
                                />
                            )}
                            <div
                                id="ws-bottom"
                                data-ws-panel="bottom"
                                className={`min-h-0 shrink-0 max-lg:hidden ${bottomShown ? '' : 'hidden'}`}
                                style={{ height: size('bottom') }}
                            >
                                <BottomRow
                                    sources={library}
                                    cards={layout.subpanels.bottomCards}
                                    onOpenLibrary={openLibrary}
                                    onAddSource={openLibrary}
                                    onOpenLayout={() => go('layout')}
                                    onOpenCover={() => go('cover')}
                                    onOpenExport={() => go('export')}
                                />
                            </div>
                        </div>
                    )}
                </main>
            </div>

            {/* Between 1024 and 1280 the tab bar is gone and the AI panel
                has not yet joined the grid; this is its way in there. Below
                1024 it is the tab bar's AI tab. */}
            {isStudio && shown('right') && (
                <div className="pointer-events-none fixed inset-x-0 bottom-4 z-30 hidden justify-center lg:flex xl:hidden">
                    <button
                        data-a="mobile:ai-pill"
                        aria-haspopup="dialog"
                        onClick={() => setPanelOpen(true)}
                        className="pointer-events-auto rounded-badge bg-brand-fill px-4 py-2 text-[12px] font-semibold text-white shadow-popover"
                    >
                        AI Editor
                        {openFindings.length > 0 && ` (${openFindings.length})`}
                    </button>
                </div>
            )}

            {!focused && (
                <MobileNav
                    bookId={book.id}
                    bookTitle={book.title}
                    current={nav === 'ai' || nav === 'analytics' ? nav : effectivePane}
                    aiCount={openFindings.length}
                    onSelect={navigate}
                    onChapters={() => setBinderOpen(true)}
                    onAi={() => {
                        setPanelTab(null);
                        setPanelOpen(true);
                    }}
                />
            )}

            {/* Phone drawers: dialogs, so focus moves in, Escape and a
                visible button close them, and focus returns to the button
                that opened them. */}
            {binderOpen && (
                <Dialog
                    variant="drawer"
                    label="Chapters"
                    onClose={() => setBinderOpen(false)}
                    data-a="drawer:chapters"
                    className="flex h-full w-[min(86vw,320px)] flex-col bg-bg-app p-2 shadow-popover md:hidden"
                >
                    <div className="flex items-center justify-between pb-1 pl-2">
                        <span className="text-[13px] font-semibold text-text-primary">Chapters</span>
                        <CloseButton onClick={() => setBinderOpen(false)} label="Close chapters" />
                    </div>
                    <ManuscriptOutline
                        nodes={binder}
                        activeId={section?.id}
                        tab={leftTab}
                        onTab={(t) => (t === 'Notes' ? go('notes') : setLeftTab(t))}
                        onSelect={(id) => {
                            openSection(id);
                            setBinderOpen(false);
                        }}
                        onAddChapter={addChapter}
                        onImport={() => {
                            setBinderOpen(false);
                            setImporting(true);
                        }}
                        adding={adding}
                        showGoals={layout.subpanels.outlineGoals}
                        {...binderActions}
                    />
                </Dialog>
            )}

            {panelOpen && (
                <Dialog
                    variant="drawer"
                    side="right"
                    label="AI Editor"
                    onClose={() => {
                        setPanelOpen(false);
                        setPanelTab(null);
                    }}
                    data-a="drawer:ai"
                    className="flex h-full max-w-[92vw] flex-col bg-bg-surface shadow-popover xl:hidden"
                >
                    <div className="flex items-center justify-end border-b border-border-divider px-2 py-1">
                        <CloseButton onClick={() => setPanelOpen(false)} label="Close AI Editor" />
                    </div>
                    <div className="flex min-h-0 flex-1">
                        <AIEditorPanel {...panelProps} visibleTabs={layout.subpanels.rightTabs} />
                    </div>
                </Dialog>
            )}

            {goals && (
                <GoalsSheet
                    bookId={book.id}
                    sectionId={goals.sectionId}
                    liveWords={goals.liveWords}
                    onClose={() => setGoals(null)}
                    onChapterGoal={chapterGoalSaved}
                />
            )}

            {importing && (
                <ImportDialog
                    title="Import chapters"
                    hint="A Markdown (.md) or Word (.docx) file. Its chapters are added after the ones already here; nothing is replaced."
                    url={`/books/${book.id}/import`}
                    accept=".md,.markdown,.txt,.docx"
                    onClose={() => setImporting(false)}
                    onDone={() => {
                        setImporting(false);
                        router.reload();
                    }}
                />
            )}

            {toast && (
                <div
                    role="status"
                    className="fixed bottom-5 left-1/2 z-[70] -translate-x-1/2 rounded-control bg-bg-inverse px-4 py-2.5 text-[12.5px] font-medium text-text-inverse shadow-popover"
                >
                    {toast}
                </div>
            )}
        </div>
    );
}

/** The AI panel's tabs, as icons on its rail. */
const RIGHT_RAIL = [
    { key: 'editor', tab: 'AI Editor', icon: 'sugg', label: 'AI Editor' },
    { key: 'comments', tab: 'Comments', icon: 'chat', label: 'Comments' },
    { key: 'research', tab: 'Research', icon: 'refs', label: 'Research' },
    { key: 'checks', tab: 'Checks', icon: 'check', label: 'Checks' },
];

/**
 * A side panel collapsed to a strip of icons: the first expands it, the
 * rest do one thing each (open a tab, add a chapter) and expand it too
 * where that thing is inside the panel.
 */
function Rail({ name, label, side, onExpand, items }) {
    return (
        <nav
            aria-label={`${label}, collapsed`}
            data-a={`rail:${name}`}
            className="flex w-full flex-col items-center gap-1 rounded-panel border border-border-default bg-bg-surface py-2"
        >
            <button
                type="button"
                data-a={`rail:${name}:expand`}
                aria-expanded="false"
                aria-controls={`ws-${name}`}
                onClick={onExpand}
                title={`Expand ${label.toLowerCase()}`}
                className="grid h-8 w-8 place-items-center rounded-control text-text-secondary transition hover:bg-bg-subtle hover:text-text-primary"
            >
                <Icon name={side === 'left' ? 'right' : 'left'} size={15} />
                <span className="sr-only">Expand {label.toLowerCase()}</span>
            </button>
            <span aria-hidden="true" className="my-1 h-px w-6 bg-border-divider" />
            {items.map((item) => (
                <button
                    key={item.key}
                    type="button"
                    data-a={`rail:${name}:${item.key}`}
                    onClick={item.run}
                    title={item.label}
                    className="relative grid h-8 w-8 place-items-center rounded-control text-text-secondary transition hover:bg-bg-subtle hover:text-brand-primary"
                >
                    <Icon name={item.icon} size={16} />
                    <span className="sr-only">{item.label}{item.count ? ` (${item.count})` : ''}</span>
                    {item.count > 0 && (
                        <span aria-hidden="true" className="absolute -right-0.5 -top-0.5 grid h-[15px] min-w-[15px] place-items-center rounded-badge bg-brand-fill px-1 text-[9.5px] font-semibold text-white">
                            {item.count}
                        </span>
                    )}
                </button>
            ))}
        </nav>
    );
}

function Panel({ children }) {
    // min-h-0 flex-1 rather than h-full: main is a flex column now that the
    // credit strip sits above the panes, and h-full in a flex child measures
    // against the wrong box. Focusable so a keyboard can scroll it.
    // Relative, so an absolutely positioned descendant (an sr-only label, a
    // hidden file input) is laid out and clipped in here: without it, one deep
    // in a long panel escapes to the page and makes the whole page scroll on
    // into blank space below the studio.
    return (
        <div tabIndex={0} className="relative min-h-0 flex-1 overflow-y-auto border-border-default bg-bg-surface sm:rounded-panel sm:border">
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
                <p className="mt-4 text-[11px] leading-relaxed text-text-secondary">
                    This screen is deliberately empty. Showing a mock-up here
                    would misrepresent what the application can do.
                </p>
            </div>
        </div>
    );
}

/** Every section in reading order, for search. Parts are not chapters. */
function flatten(nodes, out = []) {
    for (const node of nodes ?? []) {
        if (node.type !== 'part') out.push({ id: node.id, title: node.title });
        flatten(node.children, out);
    }

    return out;
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
    rights: 'ISBN & Rights',
    publish: 'Publish',
    editions: 'Editions',
    a11y: 'Accessibility',
    royalties: 'Royalties',
    launch: 'Launch Planner',
    audio: 'Audiobook Studio',
    course: 'Course Builder',
    marketing: 'Marketing Kit',
    site: 'Author Website',
    distribution: 'Sales & Distribution',
};

/**
 * Screens that are in the product but not built.
 *
 * Mapped to their icon so the panel looks like the menu item that led to it,
 * rather than every one of them showing a padlock.
 */
const LOCKED_SCREENS = {
    audio: 'mic',
    course: 'cap',
    site: 'globe',
};


const labelFor = (key) => NAV_LABELS[key] ?? key;
const paneLabel = (key) => PANE_LABELS[key] ?? key;

function milestoneFor(nav) {
    if (nav === 'design' || nav === 'publishing') return 'Milestone 3';
    if (nav === 'sales' || nav === 'analytics') return 'Milestone 5';

    return 'a later milestone';
}
