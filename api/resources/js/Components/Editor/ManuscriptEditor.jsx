import Icon from '@/Components/Studio/Icon';
import { onComments, publishComments } from '@/Components/Studio/commentsBus';
import { usePage } from '@inertiajs/react';
import { TextSelection } from '@tiptap/pm/state';
import { EditorContent, useEditor } from '@tiptap/react';
import { BlockMath, InlineMath } from '@tiptap/extension-mathematics';
import { TableKit } from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';
import 'katex/contrib/mhchem';
import 'katex/dist/katex.min.css';
import { useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
import ProgressRing, { ringLabel } from '@/Components/Studio/ProgressRing';
import { CALLOUT_CHOICES, CalloutChoice, useCalloutCss } from './Callout';
import ChartDialog from './ChartDialog';
import { CommentAnchors, setCommentThreads } from './CommentAnchors';
import { ChangePopover, CommentDialog, CommentPopover, SelectionBubble, ThreadSheet } from './ReviewLayer';
import { decide, pendingChanges, Suggest } from './Suggest';
import { CrossRef, localTargets, refreshNumbering, Xref, XrefDialog } from './CrossRef';
import { Chart } from './ChartNode';
import { Footnote, FootnoteDialog } from './FootnoteNode';
import ImageDialog from './ImageDialog';
import { PlacedImage, PlacedTable, PlacedTableView, setTextBlock } from './Placement';
import { ImageBar, TableBar } from './PlacementBar';
import { useKeyboardInset, useOnline } from './useKeyboard';
import MathDialog from './MathDialog';

/**
 * The writing canvas.
 *
 * Autosave is debounced rather than fired per keystroke: the endpoint writes
 * a row and may cut a version, and doing that on every character would be
 * both wasteful and a way to lose the server's throttle guarantees.
 */
const AUTOSAVE_DEBOUNCE_MS = 1500;

/** Average adult reading speed, for the footer estimate. */
const WORDS_PER_MINUTE = 240;

/**
 * The session's CSRF token, from the meta tag in app.blade.php.
 *
 * Not the XSRF-TOKEN cookie: that holds an encrypted value, which Laravel
 * only decrypts for the X-XSRF-TOKEN header. A `_token` field in the body is
 * compared against the raw session token, so it has to come from here.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Browsers cap the body of a request that outlives its page (keepalive) at
 * 64 KiB across all of them. A larger chapter goes as an ordinary request,
 * which a closing tab may cancel; the leave-page warning covers that case.
 */
const KEEPALIVE_LIMIT = 60_000;

/** How long a chapter switch waits for the last edit to be saved. */
const FLUSH_WAIT_MS = 4000;

/** How often an edit the server refused is tried again. */
const RETRY_MS = 15_000;

/**
 * PUT a chapter's content, and insist on the answer a save gives.
 *
 * A reply that is not the save's JSON is a failure, whatever its status:
 * a PHP notice printed ahead of the JSON, a login page, a proxy's error
 * page. Taking it as success put `undefined` in the word count, the footer
 * threw on it, and the editor went blank.
 */
async function putContent(id, json, reason, keepalive, label = null) {
    const path = `/sections/${id}/content`;
    // A label names what a `review` save decided, for version history.
    const payload = label ? { content: json, reason, label } : { content: json, reason };
    const body = JSON.stringify(payload);
    let data;

    if (keepalive && body.length < KEEPALIVE_LIMIT) {
        // fetch, because axios cannot ask for keepalive. Headers as axios
        // would send them: the XSRF cookie's token, else the page's.
        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];
        const response = await fetch(`${window.axios?.defaults.baseURL ?? ''}${path}`, {
            method: 'PUT',
            body,
            keepalive: true,
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : { 'X-CSRF-TOKEN': csrfToken() }),
            },
        });

        if (!response.ok) throw new Error(`Save refused (${response.status}).`);
        data = await response.json();
    } else {
        data = (await window.axios.put(path, payload)).data;
    }

    if (!data || typeof data !== 'object' || typeof data.word_count !== 'number') {
        throw new Error('The server did not confirm the save.');
    }

    return data;
}

export default function ManuscriptEditor({
    section,
    bookId,
    onSaved,
    autosave = true,
    headerActions = null,
    onChapters = null,
    // Suggest mode: told the chapter's pending changes when they change,
    // and asked to show the list of them.
    onChanges = null,
    onShowChanges = null,
    // The chapter's word goal, and what tapping its ring opens.
    wordGoal = null,
    onGoals = null,
    // The workspace (ADR — customisable writing workspace): the formatting
    // toolbar docked, floating over a selection, or hidden; the status bar;
    // and typewriter scrolling, when the studio keeps it. Without
    // `typewriter` the editor remembers that choice itself, per browser.
    showToolbar = true,
    toolbarStyle = 'docked',
    showStatusBar = true,
    typewriter: typewriterSetting = undefined,
    onTypewriter = null,
    ref,
}) {
    const user = usePage().props.auth?.user ?? null;
    const [status, setStatus] = useState('idle'); // idle | dirty | saving | saved | error
    const [wordCount, setWordCount] = useState(section?.word_count ?? 0);
    // The equation being written or edited: {kind, latex, pos} or null.
    const [mathEdit, setMathEdit] = useState(null);
    const [inserting, setInserting] = useState(false);
    // The chart being made or edited: {attrs, pos} or null.
    const [chartEdit, setChartEdit] = useState(null);
    // The footnote being written or edited: {text, pos} or null.
    const [noteEdit, setNoteEdit] = useState(null);
    // Picking a cross-reference target: {local, remote} or null.
    const [xrefPick, setXrefPick] = useState(null);

    // Refs rather than state: these are read inside callbacks and must not
    // retrigger the effect that wires the editor up.
    const timer = useRef(null);
    const sectionId = useRef(section?.id);
    const autosaveRef = useRef(autosave);
    const onSavedRef = useRef(onSaved);
    // Set once the chapter's place has loaded: re-asks for it when a save
    // may have changed the book's numbering scheme.
    const afterSave = useRef(null);

    autosaveRef.current = autosave;
    onSavedRef.current = onSaved;

    // The edit not yet sent: {id, editor}, and {id, json} once the editor
    // holding it is being destroyed. Its chapter id is taken when the edit
    // is made, never when it is sent: switching chapter replaces the editor
    // and the id, and the old flush, reading both at send time, found a
    // destroyed editor and dropped the edit. The JSON is read when sent
    // rather than per keystroke, which costs milliseconds on a long chapter.
    const pending = useRef(null);
    // Edits sent and not confirmed, by chapter id, tried again until they
    // are. A failed save of a chapter already left is otherwise gone.
    const unsent = useRef(new Map());
    // Saves run one at a time, in order, so an older copy never lands last.
    const queue = useRef(Promise.resolve());
    const inFlight = useRef(0);

    const send = useCallback((id, json, reason = 'autosave', keepalive = false, label = null) => {
        const run = async () => {
            if (id === sectionId.current) setStatus('saving');
            inFlight.current += 1;

            try {
                const data = await putContent(id, json, reason, keepalive, label);
                // Saves run in order, so any refused copy of this chapter
                // is older than this one and must not be sent again.
                unsent.current.delete(id);

                if (id === sectionId.current) {
                    setWordCount(data.word_count);
                    // Typing during the save leaves the chapter unsaved.
                    setStatus(pending.current ? 'dirty' : unsent.current.size ? 'error' : 'saved');
                    onSavedRef.current?.(data);
                    afterSave.current?.();
                }
            } catch {
                // Deliberately not silent. A writer who believes their work
                // is saved when it is not is the worst failure this product
                // can have, so the footer says so and keeps saying so, and
                // the edit is kept to send again.
                if (pending.current?.id !== id) unsent.current.set(id, json);
                setStatus('error');
            } finally {
                inFlight.current -= 1;
            }
        };

        queue.current = queue.current.then(run, run);

        return queue.current;
    }, []);

    /**
     * Send whatever is waiting: the pending edit and any earlier refusals.
     * Resolves when every save queued so far has been answered.
     */
    const flush = useCallback(({ reason = 'autosave', keepalive = false, label = null } = {}) => {
        clearTimeout(timer.current);
        timer.current = null;

        const edit = pending.current;
        pending.current = null;

        unsent.current.forEach((json, id) => {
            if (edit?.id !== id) send(id, json, 'autosave', keepalive);
        });

        if (edit) {
            const json = edit.json ?? (edit.editor.isDestroyed ? null : edit.editor.getJSON());

            if (json) send(edit.id, json, reason, keepalive, label);
        }

        return queue.current;
    }, [send]);

    // For the page: a chapter switch waits for the last edit to be saved
    // before asking for the next chapter, or switching straight back could
    // load the copy from before it. Bounded, so a hung save cannot freeze
    // the binder; the edit stays queued either way.
    // Suggest mode, for the changes list beside the manuscript: decide some
    // or all of the chapter's changes, or show one in the text. Through
    // refs, because both need the editor, which is made below.
    const decideRef = useRef(null);
    const revealRef = useRef(null);

    useImperativeHandle(ref, () => ({
        flush: () => Promise.race([flush(), new Promise((resolve) => setTimeout(resolve, FLUSH_WAIT_MS))]),
        decide: (ids, accept) => decideRef.current?.(ids, accept),
        reveal: (id) => revealRef.current?.(id),
    }), [flush]);

    // A deletion Suggest mode would not track (a picture, a table) is
    // refused, and the author told why nothing happened.
    const [notice, setNotice] = useState(null);

    const editor = useEditor(
        {
            // Tiptap 3 no longer re-renders on every transaction, so the
            // toolbar's pressed states and block menu froze at whatever they
            // were when something else last rendered: Bold worked, but never
            // looked like it had.
            shouldRerenderOnTransaction: true,
            extensions: [
                StarterKit.configure({
                    heading: { levels: [1, 2, 3] },
                }),
                // Theses and reports are made of tables. Columns resize at
                // their edges; every export reads the widths as shares of
                // the measure, with the table's style, type size and cell
                // alignment (Placement.js, App\Publishing\Placement).
                TableKit.configure({ table: false }),
                PlacedTable.configure({ resizable: true, View: PlacedTableView, lastColumnResizable: false }),
                // Pictures with a width, alignment, wrap, a page of their
                // own and a caption side, set by the toolbar or the handle.
                PlacedImage.configure({ inline: false, allowBase64: false }),
                Chart.configure({ onEdit: (attrs, pos) => setChartEdit({ attrs, pos }) }),
                Footnote.configure({ onEdit: (attrs, pos) => setNoteEdit({ text: attrs.text, pos }) }),
                CrossRef,
                Xref,
                // Equations are stored as LaTeX and typeset on screen with
                // KaTeX; clicking one opens it for editing.
                // Configured separately: a display equation needs KaTeX's
                // display mode, without which 	ag (equation numbers) fails.
                InlineMath.configure({
                    katexOptions: { throwOnError: false },
                    onClick: (node, pos) => setMathEdit({ kind: 'inline', latex: node.attrs.latex, pos }),
                }),
                BlockMath.configure({
                    katexOptions: { throwOnError: false, displayMode: true },
                    onClick: (node, pos) => setMathEdit({ kind: 'block', latex: node.attrs.latex, pos }),
                }),
                // Tracked changes; switched on through its storage below.
                Suggest.configure({
                    onRefused: () => setNotice('Pictures, tables, charts and display equations cannot be removed while suggesting. Turn Suggest off to remove one.'),
                }),
                CommentAnchors,
                // A quotation's box kind, chosen in the toolbar (Callout.js).
                CalloutChoice,
            ],
            content: section?.content ?? '',
            onUpdate: ({ editor }) => {
                pending.current = { id: sectionId.current, editor };
                setStatus('dirty');
                clearTimeout(timer.current);
                timer.current = null;

                // With autosave off the edit waits for Ctrl/Cmd+S, or for the
                // author leaving the chapter or the page, which flush it.
                if (!autosaveRef.current) return;

                timer.current = setTimeout(() => flush(), AUTOSAVE_DEBOUNCE_MS);
            },
            // Tiptap destroys the editor when the chapter changes, before any
            // effect cleanup can run. This is the last moment its document
            // can be read, so a pending edit is read here and sent.
            onDestroy: () => {
                const edit = pending.current;

                if (edit && !edit.json && !edit.editor.isDestroyed) {
                    edit.json = edit.editor.getJSON();
                }

                if (edit) flush();
            },
            editorProps: {
                attributes: {
                    class: 'prose prose-slate dark:prose-invert max-w-none focus:outline-none min-h-[60vh] px-2 py-4',
                    // A screen reader announced an unnamed edit box.
                    'aria-label': `${section?.title ?? 'Chapter'}, manuscript`,
                    'aria-multiline': 'true',
                },
            },
            // Tiptap 3 renders on the client only; without this React warns
            // about a hydration mismatch on the server-rendered shell.
            immediatelyRender: false,
        },
        [section?.id],
    );

    // Swap content when the binder selects a different chapter. Without this
    // the editor keeps the previous chapter's text and the next autosave
    // writes it over the newly opened one.
    // Also keyed on a revision counter, not only the id. Content can be
    // replaced underneath this component without the chapter changing — a
    // template insertion, a restored version — and keying on the id alone
    // left the editor showing text the database no longer held.
    useEffect(() => {
        sectionId.current = section?.id;
        setWordCount(section?.word_count ?? 0);
        // An earlier chapter's refused save is still unsaved work.
        setStatus(unsent.current.size ? 'error' : 'idle');

        // isDestroyed as well as null. Tiptap 3 tears the editor down when
        // the pane unmounts — switching to the Outline subtab does it — and
        // this effect can still run against the stale instance afterwards.
        // Reaching for `.commands` on a destroyed editor throws, React
        // unmounts the tree, and the whole studio goes blank.
        if (editor && ! editor.isDestroyed && section) {
            editor.commands.setContent(section.content ?? '', { emitUpdate: false });
        }
    }, [section?.id, section?.revision, editor]);

    // Where this chapter sits in the book: its number prefix, the tables and
    // equations before it, and every cross-reference target elsewhere. Until
    // it arrives the numbering plugin leaves the chapter alone.
    const loadPlace = useCallback(async () => {
        if (!bookId || !section?.id || !editor || editor.isDestroyed) return null;

        try {
            const { data } = await window.axios.get(`/books/${bookId}/cross-references`, {
                params: { section: section.id },
            });

            if (editor.isDestroyed) return null;

            const storage = editor.storage.crossRef;
            storage.place = {
                scheme: data.scheme,
                schemeElsewhere: data.scheme_elsewhere ?? data.scheme,
                // The book's chosen scheme (Design), which no caption changes.
                chosen: data.numbering ?? null,
                prefix: data.section.prefix,
                offsets: data.section.offsets,
                running: data.section.running,
            };
            storage.remote = data.targets;
            refreshNumbering(editor);
            if (data.text_block) setTextBlock(editor, data.text_block);

            return data;
        } catch {
            return null;
        }
    }, [bookId, section?.id, editor]);

    useEffect(() => {
        loadPlace();
    }, [loadPlace]);

    // A caption typed here can turn the book's numbering to by-chapter (or
    // back). The screen follows at once; the other chapters' numbers, which
    // cross-references here read, follow once the change is saved.
    useEffect(() => {
        afterSave.current = () => {
            const storage = editor && !editor.isDestroyed ? editor.storage.crossRef : null;

            if (storage?.place && storage.scheme && storage.scheme !== storage.place.scheme) loadPlace();
        };
    }, [editor, loadPlace]);

    // Leaving the page. visibilitychange (hidden) is the last event a phone
    // or a closed tab reliably delivers; pagehide covers the rest. Both send
    // with keepalive so the request outlives the page. While anything is
    // unsaved, beforeunload asks the author first: the one case keepalive
    // cannot carry, a chapter over 64 KiB, depends on it.
    useEffect(() => {
        const unsaved = () => pending.current || unsent.current.size > 0 || inFlight.current > 0;
        const leave = () => {
            if (pending.current || unsent.current.size) flush({ keepalive: true });
        };
        const hidden = () => document.visibilityState === 'hidden' && leave();
        const warn = (event) => {
            if (!unsaved()) return;

            leave();
            event.preventDefault();
            event.returnValue = '';
        };

        document.addEventListener('visibilitychange', hidden);
        window.addEventListener('pagehide', leave);
        window.addEventListener('beforeunload', warn);

        return () => {
            document.removeEventListener('visibilitychange', hidden);
            window.removeEventListener('pagehide', leave);
            window.removeEventListener('beforeunload', warn);
        };
    }, [flush]);

    // A refused save is tried again, so a dropped connection that comes
    // back saves the work without the author having to type again.
    useEffect(() => {
        if (status !== 'error') return undefined;

        const retry = () => unsent.current.size && flush();
        const every = setInterval(retry, RETRY_MS);
        window.addEventListener('online', retry);

        return () => {
            clearInterval(every);
            window.removeEventListener('online', retry);
        };
    }, [status, flush]);

    // Ctrl/Cmd+S forces a save and a version, because writers press it.
    useEffect(() => {
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();

                if (editor && ! editor.isDestroyed && sectionId.current) {
                    pending.current = { id: sectionId.current, editor };
                    flush({ reason: 'manual' });
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [editor, flush]);

    // --- Phone writing: the keyboard, the connection, the caret ----------

    const keyboard = useKeyboardInset();
    const keyboardRef = useRef(0);
    keyboardRef.current = keyboard;
    const online = useOnline();
    const scroller = useRef(null);
    const [phone, setPhone] = useState(false);
    // The editor has focus: on a phone the formatting bar moves down to sit
    // on the keyboard, where the thumb is, instead of scrolling away above.
    const [editing, setEditing] = useState(false);
    // Typewriter scrolling: the line being written stays mid-screen.
    const [ownTypewriter, setTypewriter] = useState(() => {
        try {
            return window.localStorage.getItem('ecrivain:typewriter') === '1';
        } catch {
            return false;
        }
    });
    const typewriter = typewriterSetting ?? ownTypewriter;
    const typewriterRef = useRef(typewriter);
    typewriterRef.current = typewriter;

    const toggleTypewriter = () => {
        if (onTypewriter) {
            onTypewriter(!typewriter);

            return;
        }

        setTypewriter((on) => {
            try {
                window.localStorage.setItem('ecrivain:typewriter', on ? '0' : '1');
            } catch {
                // Private mode: the choice lasts for this page only.
            }

            return !on;
        });
    };

    useEffect(() => {
        const narrow = window.matchMedia('(max-width: 767px)');
        const update = () => setPhone(narrow.matches);

        update();
        narrow.addEventListener('change', update);

        return () => narrow.removeEventListener('change', update);
    }, []);

    // Back online: send what was refused while the connection was down,
    // rather than waiting for the next keystroke to try again.
    useEffect(() => {
        if (online && (unsent.current.size || pending.current)) flush();
    }, [online, flush]);

    /**
     * Keep the caret where it can be seen: mid-screen for typewriter
     * scrolling, or above the keyboard and the formatting bar on a phone
     * (iOS slides the keyboard over the page, so ProseMirror's own scrolling
     * can leave the caret under the keys).
     */
    const keepCaret = useCallback(() => {
        const box = scroller.current;

        if (!editor || editor.isDestroyed || !box) return;

        const center = typewriterRef.current && editor.state.selection.empty;

        if (!center && !keyboardRef.current) return;

        let caret;

        try {
            caret = editor.view.coordsAtPos(editor.state.selection.head);
        } catch {
            return;
        }

        const rect = box.getBoundingClientRect();
        const view = window.visualViewport;
        const viewTop = view ? view.offsetTop : 0;
        const viewBottom = view
            ? view.offsetTop + view.height - (keyboardRef.current ? 60 : 0)
            : window.innerHeight;
        const top = Math.max(rect.top, viewTop);
        const bottom = Math.min(rect.bottom, viewBottom);

        if (center) {
            box.scrollTop += caret.top - (top + (bottom - top) * 0.45);
        } else if (caret.bottom > bottom - 16) {
            box.scrollTop += caret.bottom - bottom + 32;
        }
    }, [editor]);

    useEffect(() => {
        if (!editor) return undefined;

        let frame = null;
        let blurTimer = null;
        const onMove = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(keepCaret);
        };
        const onFocus = () => {
            clearTimeout(blurTimer);
            setEditing(true);
        };
        // Late, so a tap on the formatting bar (which keeps focus, but not
        // on every touch browser) does not bounce the bar up and down.
        const onBlur = () => {
            blurTimer = setTimeout(() => setEditing(false), 200);
        };

        editor.on('selectionUpdate', onMove);
        editor.on('update', onMove);
        editor.on('focus', onFocus);
        editor.on('blur', onBlur);

        return () => {
            cancelAnimationFrame(frame);
            clearTimeout(blurTimer);
            editor.off('selectionUpdate', onMove);
            editor.off('update', onMove);
            editor.off('focus', onFocus);
            editor.off('blur', onBlur);
        };
    }, [editor, keepCaret]);

    useEffect(() => {
        if (keyboard) keepCaret();
    }, [keyboard, keepCaret]);

    // --- Review: suggest mode, its changes, comments on a selection -------

    // Remembered per browser and per book, like typewriter scrolling: it is
    // how this person is working on this book on this device (an editor
    // suggesting on a client's manuscript, the author writing on their
    // own), not a fact about the book every collaborator should inherit.
    const calloutCss = useCalloutCss(bookId);
    const suggestKey = `ecrivain:suggest:${bookId ?? 'book'}`;
    const [suggesting, setSuggesting] = useState(() => {
        try {
            return window.localStorage.getItem(suggestKey) === '1';
        } catch {
            return false;
        }
    });

    const toggleSuggest = () => {
        setSuggesting((on) => {
            try {
                window.localStorage.setItem(suggestKey, on ? '0' : '1');
            } catch {
                // Private mode: the choice lasts for this page only.
            }

            return !on;
        });
    };

    // Suggest mode switched off by the console (App\Platform\Features):
    // the switch goes, and typing edits directly. Changes already suggested
    // can still be accepted or rejected.
    const suggestAllowed = usePage().props.features?.suggest_mode !== false;

    useEffect(() => {
        if (!editor || editor.isDestroyed) return;

        editor.storage.suggest.enabled = suggesting && suggestAllowed;
        editor.storage.suggest.user = user ? { id: user.id, name: user.name } : null;
    }, [editor, suggesting, suggestAllowed, user?.id, user?.name]);

    useEffect(() => {
        if (!notice) return undefined;

        const timer = setTimeout(() => setNotice(null), 5000);

        return () => clearTimeout(timer);
    }, [notice]);

    // The chapter's pending changes, re-read a moment after the text
    // changes, and handed up only when the list itself changed, so typing
    // outside a suggestion never re-renders the studio.
    const [changes, setChanges] = useState([]);
    const changesSig = useRef('');
    const onChangesRef = useRef(onChanges);
    onChangesRef.current = onChanges;

    const readChanges = useCallback(() => {
        if (!editor || editor.isDestroyed) return;

        const list = pendingChanges(editor.state.doc);
        const sig = list.map((c) => `${c.id}:${c.inserted}:${c.deleted}`).join('|');

        if (sig === changesSig.current) return;

        changesSig.current = sig;
        setChanges(list);
        onChangesRef.current?.(list);
    }, [editor]);

    // What floats over the text: {kind: 'change'|'comment', id, top, left}.
    const [popover, setPopover] = useState(null);
    const hideTimer = useRef(null);

    useEffect(() => {
        if (!editor) return undefined;

        let timer = null;
        const onTransaction = ({ transaction }) => {
            if (!transaction.docChanged) return;

            clearTimeout(timer);
            timer = setTimeout(readChanges, 200);
            setPopover(null);
        };

        readChanges();
        editor.on('transaction', onTransaction);

        return () => {
            clearTimeout(timer);
            editor.off('transaction', onTransaction);
        };
    }, [editor, readChanges]);

    const decideChanges = useCallback((ids, accept) => {
        if (!editor || editor.isDestroyed) return;

        const all = pendingChanges(editor.state.doc);
        const list = ids ? all.filter((change) => ids.includes(change.id)) : all;
        const tr = decide(editor.state, ids, accept);

        if (!tr || !list.length) return;

        editor.view.dispatch(tr);
        setPopover(null);

        // Saved at once, as `review`, with what was decided: version
        // history is the record of who accepted what.
        const authors = [...new Set(list.map((change) => change.author))].join(', ');
        const n = list.length;
        const label = `${accept ? 'Accepted' : 'Rejected'} ${n} suggestion${n === 1 ? '' : 's'} by ${authors}`;

        pending.current = { id: sectionId.current, editor };
        flush({ reason: 'review', label: label.length > 120 ? `${label.slice(0, 119)}…` : label });
        readChanges();
    }, [editor, flush, readChanges]);

    decideRef.current = decideChanges;

    /** Position, inside the scrolling text, just under an element. */
    const below = useCallback((rect, width = 268) => {
        const box = scroller.current;

        if (!box) return null;

        const frame = box.getBoundingClientRect();

        return {
            top: rect.bottom - frame.top + box.scrollTop + 6,
            left: Math.min(Math.max(rect.left - frame.left, 8), Math.max(8, box.clientWidth - width)),
        };
    }, []);

    const showFor = useCallback((element) => {
        const change = element.closest('[data-change]');
        const anchor = change ? null : element.closest('.comment-anchor');
        const target = change ?? anchor;

        if (!target) return;

        const place = below(target.getBoundingClientRect());

        if (!place) return;

        setPopover(change
            ? { kind: 'change', id: change.getAttribute('data-change'), ...place }
            : { kind: 'comment', id: Number(anchor.getAttribute('data-thread')), ...place });
    }, [below]);

    revealRef.current = (id) => {
        if (!editor || editor.isDestroyed) return;

        const element = editor.view.dom.querySelector(`[data-change="${CSS.escape(id)}"]`);

        if (!element) return;

        element.scrollIntoView({ block: 'center' });
        showFor(element);
    };

    const hideSoon = () => {
        clearTimeout(hideTimer.current);
        hideTimer.current = setTimeout(() => setPopover(null), 350);
    };

    // Comment threads: the book's, from the same endpoint the Comments
    // panel reads, kept in step with it through the comments bus.
    const [threads, setThreads] = useState([]);
    const [commenting, setCommenting] = useState(null);
    const [threadOpen, setThreadOpen] = useState(null);

    useEffect(() => {
        if (!bookId) return undefined;

        let live = true;

        window.axios.get(`/books/${bookId}/comments`)
            .then(({ data }) => live && setThreads(data.threads ?? []))
            .catch(() => {});

        const off = onComments((data) => setThreads(data.threads ?? []));

        return () => {
            live = false;
            off();
        };
    }, [bookId]);

    const openThreads = useMemo(
        () => threads.filter((thread) => thread.section_id === section?.id && thread.status === 'open'),
        [threads, section?.id],
    );

    useEffect(() => {
        if (editor && !editor.isDestroyed) setCommentThreads(editor, openThreads);
    }, [editor, openThreads]);

    // Hover (a mouse) or tap on a change or a commented passage.
    useEffect(() => {
        if (!editor) return undefined;

        const dom = editor.view.dom;
        const coarse = () => window.matchMedia('(pointer: coarse)').matches;
        const target = (event) => event.target?.closest?.('[data-change], .comment-anchor');

        const over = (event) => {
            if (coarse() || !target(event)) return;

            clearTimeout(hideTimer.current);
            showFor(event.target);
        };
        const out = (event) => {
            if (!coarse() && target(event)) hideSoon();
        };
        const click = (event) => {
            const hit = target(event);

            if (!hit) {
                setPopover(null);

                return;
            }

            // On a phone a commented passage opens its thread outright: a
            // card in between is one more tap on a small screen.
            const anchor = hit.closest('[data-change]') ? null : hit.closest('.comment-anchor');

            if (anchor && coarse()) {
                setPopover(null);
                setThreadOpen(Number(anchor.getAttribute('data-thread')));

                return;
            }

            clearTimeout(hideTimer.current);
            showFor(event.target);
        };

        const escape = (event) => {
            if (event.key === 'Escape') setPopover(null);
        };

        dom.addEventListener('mouseover', over);
        dom.addEventListener('mouseout', out);
        dom.addEventListener('click', click);
        document.addEventListener('keydown', escape);

        return () => {
            dom.removeEventListener('mouseover', over);
            dom.removeEventListener('mouseout', out);
            dom.removeEventListener('click', click);
            document.removeEventListener('keydown', escape);
        };
    }, [editor, showFor]);

    // The bubble over a selection: Comment, Bold, Italic.
    const [bubble, setBubble] = useState(null);
    // A floating toolbar stays while something in it has focus (the block
    // menu), though the selection's bubble has gone with the editor's focus.
    const [toolbarFocus, setToolbarFocus] = useState(false);

    useEffect(() => {
        if (!editor) return undefined;

        let blurTimer = null;

        const update = () => {
            if (editor.isDestroyed) return;

            const { state, view } = editor;
            const selection = state.selection;
            const box = scroller.current;

            if (!box || selection.empty || !(selection instanceof TextSelection) || !view.hasFocus()) {
                setBubble(null);

                return;
            }

            let start;
            let end;

            try {
                start = view.coordsAtPos(selection.from);
                end = view.coordsAtPos(selection.to);
            } catch {
                return;
            }

            const frame = box.getBoundingClientRect();
            // On touch the system's copy menu sits above a selection, so
            // the bubble goes under it.
            const touch = window.matchMedia('(pointer: coarse)').matches;
            const top = touch
                ? end.bottom - frame.top + box.scrollTop + 12
                : Math.max(box.scrollTop + 4, start.top - frame.top + box.scrollTop - 50);
            const middle = start.top === end.top ? (start.left + end.left) / 2 : start.left;

            setBubble({
                top,
                left: Math.min(Math.max(middle - frame.left - 120, 8), Math.max(8, box.clientWidth - 250)),
            });
        };
        const blur = () => {
            clearTimeout(blurTimer);
            blurTimer = setTimeout(() => !editor.isDestroyed && !editor.view.hasFocus() && setBubble(null), 200);
        };

        editor.on('selectionUpdate', update);
        editor.on('focus', update);
        editor.on('blur', blur);

        return () => {
            clearTimeout(blurTimer);
            editor.off('selectionUpdate', update);
            editor.off('focus', update);
            editor.off('blur', blur);
        };
    }, [editor]);

    const startComment = () => {
        const { from, to } = editor.state.selection;
        const excerpt = editor.state.doc.textBetween(from, to, '\n\n', ' ').trim().slice(0, 2000);

        if (!excerpt) return;

        setBubble(null);
        setCommenting({ excerpt });
    };

    const postComment = async (body) => {
        const { data } = await window.axios.post(`/sections/${section.id}/comments`, {
            body,
            excerpt: commenting.excerpt,
        });

        publishComments(data);
        setCommenting(null);
    };

    const replyTo = async (thread, body) => {
        const { data } = await window.axios.post(`/sections/${thread.section_id}/comments`, { body, parent_id: thread.id });
        publishComments(data);
    };

    const resolve = async (thread, status) => {
        const { data } = await window.axios.patch(`/comments/${thread.id}`, { status });
        publishComments(data);
    };

    if (!section) {
        return (
            <div className="grid h-full place-items-center p-12 text-center">
                <p className="text-[12.5px] text-text-secondary">
                    Select a chapter from the outline to start writing.
                </p>
            </div>
        );
    }

    // Never trust the count to be a number: a footer that throws takes the
    // whole writing canvas down with it.
    const words = Number(wordCount) || 0;
    // Floating is a desktop form: on a phone the bar is on the keyboard.
    const floating = showToolbar && toolbarStyle === 'floating' && !phone;
    const docked = editing && phone && showToolbar;
    const floatingBar = floating && !docked;
    const toolbarShown = showToolbar && (!floating || Boolean(bubble) || toolbarFocus);
    const readingMinutes = Math.max(1, Math.round(words / WORDS_PER_MINUTE));

    return (
        <div className="flex h-full flex-col">
            {/* Chapter header, h48 per the handoff: title, live word count,
                save state. */}
            <header className="flex h-[48px] shrink-0 items-center gap-3 border-b border-border-divider px-3 sm:px-[21px]">
                {/* On a phone the binder is a drawer, and the chapter's own
                    title is where a thumb looks for "which chapter, and how
                    do I get to another one". */}
                {onChapters ? (
                    <button
                        type="button"
                        data-a="editor:chapters"
                        onClick={onChapters}
                        aria-haspopup="dialog"
                        aria-label={`${section.title} — switch chapter`}
                        className="-ml-1.5 flex min-h-[40px] min-w-0 items-center gap-1 rounded-control px-1.5 text-left text-[14px] font-semibold text-text-primary transition hover:bg-bg-subtle md:hidden"
                    >
                        <span className="truncate">{section.title}</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true" className="shrink-0 text-text-secondary">
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                ) : null}
                <h2 className={`truncate text-[14px] font-semibold text-text-primary ${onChapters ? 'max-md:hidden' : ''}`}>
                    {section.title}
                </h2>
                <span className="ml-auto shrink-0 text-[11.5px] tabular-nums text-text-secondary max-sm:hidden">
                    {words.toLocaleString()} words
                </span>
                {/* The chapter's word goal: the ring fills as the
                    chapter grows, and opens the goals sheet. Without a goal
                    it is an empty ring that offers to set one. */}
                {onGoals && (
                    <button
                        type="button"
                        data-a="editor:goals"
                        onClick={() => onGoals(words)}
                        aria-haspopup="dialog"
                        aria-label={`Goals — ${ringLabel(words, wordGoal)}`}
                        title={wordGoal ? ringLabel(words, wordGoal) : 'Set a word goal'}
                        className="grid h-7 w-7 shrink-0 place-items-center rounded-control transition hover:bg-bg-subtle max-md:h-9 max-md:w-9 max-sm:ml-auto"
                    >
                        <ProgressRing value={words} goal={wordGoal} size={20} stroke={2.5} />
                    </button>
                )}
                <SaveStatus status={status} online={online} />
                <button
                    type="button"
                    data-a="editor:typewriter"
                    onClick={toggleTypewriter}
                    aria-pressed={typewriter}
                    title={typewriter ? 'Typewriter scrolling is on' : 'Typewriter scrolling: keep the line you are writing mid-screen'}
                    className={`grid h-7 w-7 shrink-0 place-items-center rounded-control transition max-md:h-9 max-md:w-9 ${
                        typewriter ? 'bg-brand-primary-subtle text-brand-primary' : 'text-text-secondary hover:bg-bg-subtle hover:text-text-primary'
                    }`}
                >
                    <Icon name="type" size={15} />
                    <span className="sr-only">Typewriter scrolling</span>
                </button>
                {changes.length > 0 && onShowChanges && (
                    <button
                        type="button"
                        data-a="editor:changes"
                        onClick={onShowChanges}
                        title="Suggested changes in this chapter"
                        className="flex h-7 shrink-0 items-center gap-1 rounded-badge bg-status-success-bg px-2 text-[11.5px] font-semibold text-status-success max-md:h-9"
                    >
                        {changes.length}
                        <span className="max-sm:sr-only">{changes.length === 1 ? 'change' : 'changes'}</span>
                    </button>
                )}
                {suggestAllowed && (
                <button
                    type="button"
                    data-a="editor:suggest"
                    onClick={toggleSuggest}
                    aria-pressed={suggesting}
                    title={suggesting
                        ? 'Suggesting: what you type and delete is tracked for review. Click to edit directly.'
                        : 'Suggest: track what you type and delete, for the author to accept or reject'}
                    className={`flex h-7 shrink-0 items-center gap-1 rounded-control px-1.5 text-[12px] font-semibold transition max-md:h-9 max-md:min-w-9 max-md:justify-center ${
                        suggesting ? 'bg-status-success-bg text-status-success' : 'text-text-secondary hover:bg-bg-subtle hover:text-text-primary'
                    }`}
                >
                    <Icon name="aLine" size={15} />
                    <span className="max-lg:sr-only">{suggesting ? 'Suggesting' : 'Suggest'}</span>
                </button>
                )}
                {headerActions}
            </header>

            {/* Said in words as well as by the pressed button: on a phone
                the button is an icon, and typing that does not delete is
                alarming until you know why. */}
            {((suggesting && suggestAllowed) || notice) && (
                <p
                    // Not role=status: that is the save state's, and the
                    // only one in the chapter.
                    role={notice ? 'alert' : undefined}
                    data-a={notice ? 'editor:suggest-notice' : 'editor:suggesting'}
                    className={`shrink-0 border-b px-3 py-1 text-[11.5px] sm:px-[21px] ${
                        notice
                            ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                            : 'border-status-success/20 bg-status-success-bg text-status-success'
                    }`}
                >
                    {notice ?? 'Suggesting — what you type and delete is kept as a suggestion to accept or reject.'}
                </p>
            )}

            {/* Writing on a phone, the bar leaves the top and sits on the
                keyboard: fixed above it where iOS lays the keyboard over the
                page, last in the column where Android has already shrunk the
                page to fit. */}
            {/* Floating, the bar sits over the top of the writing area
                while text is selected: laid over the page, not in its
                flow, so the text never moves when it comes and goes. */}
            <div
                data-a="editor:toolbar-dock"
                data-docked={docked ? 'true' : undefined}
                data-floating={floatingBar ? 'true' : undefined}
                hidden={!toolbarShown}
                onFocus={() => floatingBar && setToolbarFocus(true)}
                onBlur={(event) => !event.currentTarget.contains(event.relatedTarget) && setToolbarFocus(false)}
                className={
                    docked
                        ? 'order-last z-40 border-t border-border-divider bg-bg-surface shadow-[0_-6px_16px_-10px_rgba(0,0,0,0.25)]'
                        : floatingBar
                          ? 'relative z-30 h-0'
                          : ''
                }
                style={docked && keyboard ? { position: 'fixed', left: 0, right: 0, bottom: keyboard } : undefined}
            >
            <div className={floatingBar ? 'absolute inset-x-3 top-2 overflow-hidden rounded-tile border border-border-default bg-bg-surface shadow-popover' : ''}>
            <Toolbar
                editor={editor}
                onDone={docked ? () => editor?.commands.blur() : null}
                onMath={(kind) => setMathEdit({ kind, latex: '', pos: null })}
                onImage={bookId ? () => setInserting(true) : null}
                onChart={() => setChartEdit({ attrs: {}, pos: null })}
                onFootnote={() => setNoteEdit({ text: '', pos: null })}
                onXref={bookId ? async () => {
                    const place = await loadPlace();
                    setXrefPick({ local: localTargets(editor.state.doc), remote: place?.targets ?? [] });
                } : null}
            />
            </div>
            </div>

            {xrefPick && (
                <XrefDialog
                    local={xrefPick.local}
                    remote={xrefPick.remote}
                    onClose={() => setXrefPick(null)}
                    onInsert={(target) => {
                        editor.chain().focus().insertContent({ type: 'xref', attrs: { target: target.label, text: target.text } }).run();
                        setXrefPick(null);
                    }}
                />
            )}

            {noteEdit && (
                <FootnoteDialog
                    initial={noteEdit}
                    onClose={() => setNoteEdit(null)}
                    onDelete={() => {
                        editor.chain().focus().setNodeSelection(noteEdit.pos).deleteSelection().run();
                        setNoteEdit(null);
                    }}
                    onSave={(text) => {
                        const { pos } = noteEdit;

                        if (pos === null) {
                            editor.chain().focus().insertContent({ type: 'footnote', attrs: { text } }).run();
                        } else {
                            editor
                                .chain()
                                .focus()
                                .command(({ tr }) => {
                                    tr.setNodeMarkup(pos, undefined, { text });

                                    return true;
                                })
                                .run();
                        }

                        setNoteEdit(null);
                    }}
                />
            )}

            {chartEdit && (
                <ChartDialog
                    initial={chartEdit}
                    onClose={() => setChartEdit(null)}
                    onDelete={() => {
                        editor.chain().focus().setNodeSelection(chartEdit.pos).deleteSelection().run();
                        setChartEdit(null);
                    }}
                    onSave={(spec) => {
                        const { pos } = chartEdit;

                        if (pos === null) {
                            editor.chain().focus().insertContent({ type: 'chart', attrs: spec }).run();
                        } else {
                            editor
                                .chain()
                                .focus()
                                .command(({ tr }) => {
                                    tr.setNodeMarkup(pos, undefined, spec);

                                    return true;
                                })
                                .run();
                        }

                        setChartEdit(null);
                    }}
                />
            )}

            {inserting && (
                <ImageDialog
                    bookId={bookId}
                    onClose={() => setInserting(false)}
                    onInsert={({ src, alt, label, caption }) => {
                        const content = [{ type: 'image', attrs: { src, alt } }];

                        // The caption as a paragraph under the picture, in the
                        // form every export recognises as a caption.
                        if (label || caption) {
                            content.push({
                                type: 'paragraph',
                                content: [
                                    ...(label ? [{ type: 'text', text: label, marks: [{ type: 'bold' }] }] : []),
                                    ...(caption ? [{ type: 'text', text: (label ? ' ' : '') + caption }] : []),
                                ],
                            });
                        }

                        editor.chain().focus().insertContent(content).run();
                        setInserting(false);
                    }}
                />
            )}

            {mathEdit && (
                <MathDialog
                    initial={mathEdit}
                    onClose={() => setMathEdit(null)}
                    onDelete={() => {
                        const { kind, pos } = mathEdit;
                        const chain = editor.chain().focus();
                        (kind === 'block' ? chain.deleteBlockMath({ pos }) : chain.deleteInlineMath({ pos })).run();
                        setMathEdit(null);
                    }}
                    onSave={({ latex, display }) => {
                        const { kind, pos } = mathEdit;
                        let chain = editor.chain().focus();

                        if (pos !== null && (kind === 'block') === display) {
                            chain = display
                                ? chain.updateBlockMath({ latex, pos })
                                : chain.updateInlineMath({ latex, pos });
                        } else {
                            // New, or switched between inline and display:
                            // replace the old node where it stood.
                            if (pos !== null) {
                                chain = kind === 'block'
                                    ? chain.deleteBlockMath({ pos })
                                    : chain.deleteInlineMath({ pos });
                            }
                            chain = display
                                ? chain.insertBlockMath({ latex, pos: pos ?? undefined })
                                : chain.insertInlineMath({ latex, pos: pos ?? undefined });
                        }

                        chain.run();
                        setMathEdit(null);
                    }}
                />
            )}

            <div
                ref={scroller}
                data-a="editor:canvas"
                className={`ws-canvas relative flex-1 overflow-y-auto px-4 pt-5 sm:px-[21px] sm:pt-[30px] ${typewriter ? 'pb-[45vh]' : 'pb-10'}`}
            >
                {/^\s*list\s+of\s+(tables|figures|illustrations)\s*$/i.test(section.title) && (
                    <p
                        data-a="editor:generated-list"
                        className="mb-4 rounded-control border border-brand-primary/30 bg-brand-primary-subtle px-3.5 py-2.5 text-[12.5px] leading-relaxed text-text-body"
                    >
                        This list is made for you in every export, with page numbers in the PDF,
                        from every caption written as a bold “Table 1.1” or “Figure 1.1”.
                        Anything typed here is not used.
                    </p>
                )}
                {calloutCss && <style data-a="editor:callout-css">{calloutCss}</style>}
                <EditorContent editor={editor} />

                {popover?.kind === 'change' && (() => {
                    const change = changes.find((item) => item.id === popover.id)
                        ?? (editor ? pendingChanges(editor.state.doc).find((item) => item.id === popover.id) : null);

                    return change ? (
                        <ChangePopover
                            change={change}
                            position={{ top: popover.top, left: popover.left }}
                            onAccept={() => decideChanges([change.id], true)}
                            onReject={() => decideChanges([change.id], false)}
                            onEnter={() => clearTimeout(hideTimer.current)}
                            onLeave={hideSoon}
                        />
                    ) : null;
                })()}

                {popover?.kind === 'comment' && (() => {
                    const thread = openThreads.find((item) => item.id === popover.id);

                    return thread ? (
                        <CommentPopover
                            thread={thread}
                            position={{ top: popover.top, left: popover.left }}
                            onOpen={() => {
                                setPopover(null);
                                setThreadOpen(thread.id);
                            }}
                            onEnter={() => clearTimeout(hideTimer.current)}
                            onLeave={hideSoon}
                        />
                    ) : null;
                })()}

                {bubble && !commenting && (
                    <SelectionBubble
                        position={bubble}
                        onComment={bookId ? startComment : null}
                        bold={editor?.isActive('bold')}
                        italic={editor?.isActive('italic')}
                        onBold={() => editor.chain().focus().toggleBold().run()}
                        onItalic={() => editor.chain().focus().toggleItalic().run()}
                    />
                )}
            </div>

            {commenting && (
                <CommentDialog
                    excerpt={commenting.excerpt}
                    onClose={() => setCommenting(null)}
                    onPost={postComment}
                />
            )}

            {threadOpen && threads.find((thread) => thread.id === threadOpen) && (() => {
                const thread = threads.find((item) => item.id === threadOpen);

                return (
                    <ThreadSheet
                        thread={thread}
                        onClose={() => setThreadOpen(null)}
                        onReply={(body) => replyTo(thread, body)}
                        onResolve={(status) => resolve(thread, status)}
                    />
                );
            })()}

            <footer data-a="editor:status-bar" className={`${docked || !showStatusBar ? 'hidden' : 'flex'} h-[30px] shrink-0 items-center justify-between border-t border-border-divider px-4 text-[11px] text-text-secondary sm:px-[21px]`}>
                <span>Reading time ~{readingMinutes} min</span>
                <span className="tabular-nums">
                    {words.toLocaleString()} words
                </span>
            </footer>
        </div>
    );
}

function SaveStatus({ status, online = true }) {
    const map = {
        idle: ['text-text-secondary', 'Ready', false],
        dirty: ['text-status-warning', 'Unsaved changes', false],
        saving: ['text-text-secondary', 'Saving…', false],
        saved: ['text-status-success', 'Saved', true],
        error: ['text-status-danger', 'Not saved — check your connection', false],
    };
    // Offline with work not yet saved: said plainly, with what to do. The
    // edit is held by this page and sent when the connection returns, so
    // the page has to stay open.
    const offline = !online && ['dirty', 'saving', 'error'].includes(status);
    const [cls, label, showCheck] = offline
        ? ['text-status-warning', 'Offline — keep this page open; it saves when you reconnect', false]
        : (map[status] ?? map.idle);

    return (
        <span
            className={`flex shrink-0 items-center gap-1 text-[11.5px] font-medium ${cls}`}
            role="status"
        >
            {showCheck && <Icon name="savedc" size={14} strokeWidth={2} />}
            {label}
        </span>
    );
}

/**
 * Formatting controls, h47 per the handoff.
 *
 * Only commands the installed extensions provide. The mockup also shows link
 * and image buttons; those need extensions that are not installed, and a
 * button that does nothing is worse than an absent one.
 */
function Toolbar({ editor, onMath, onImage, onChart, onFootnote, onXref, onDone = null }) {
    if (!editor) return null;

    const block = editor.isActive('heading', { level: 1 })
        ? 'h1'
        : editor.isActive('heading', { level: 2 })
          ? 'h2'
          : editor.isActive('heading', { level: 3 })
            ? 'h3'
            : editor.isActive('blockquote')
              ? 'quote'
              : 'p';

    const setBlock = (value) => {
        const chain = editor.chain().focus();

        if (value === 'p') return chain.setParagraph().run();
        if (value === 'quote') return chain.toggleBlockquote().run();

        return chain
            .toggleHeading({ level: Number(value.replace('h', '')) })
            .run();
    };

    const Btn = ({ onClick, active, title, label, children }) => (
        <button
            type="button"
            title={title}
            aria-label={title}
            aria-pressed={active}
            data-a={`toolbar:${label}`}
            // Keep the selection in the text: a blur here re-rendered the
            // toolbar mid-click and the click never landed.
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            className={`grid h-[28px] min-w-[28px] shrink-0 place-items-center rounded-button px-1.5 text-[13px] transition max-md:h-[40px] max-md:min-w-[40px] pointer-coarse:h-[38px] pointer-coarse:min-w-[38px] ${
                active
                    ? 'bg-brand-primary-subtle text-brand-primary'
                    : 'text-text-body hover:bg-bg-subtle'
            }`}
        >
            {children}
        </button>
    );

    const Divider = () => (
        <span className="mx-1 h-[18px] w-px shrink-0 bg-border-default" />
    );

    // One row that scrolls sideways on a phone: wrapped, the toolbar took
    // three rows of a 390px screen before the first line of text.
    return (
        <div
            role="toolbar"
            aria-label="Formatting"
            className="no-scrollbar flex min-h-[47px] shrink-0 items-center gap-0.5 border-b border-border-divider px-[21px] md:flex-wrap md:py-1 max-md:h-[52px] max-md:overflow-x-auto max-md:px-2"
        >
            <select
                value={block}
                onChange={(event) => setBlock(event.target.value)}
                data-a="toolbar:block"
                aria-label="Block type"
                className="mr-1 h-[28px] shrink-0 rounded-control border max-md:h-[40px] border-border-input bg-bg-surface py-0 pl-2 pr-7 text-[12px] text-text-body focus:border-brand-primary focus:ring-0"
            >
                <option value="p">Paragraph</option>
                <option value="h1">Heading 1</option>
                <option value="h2">Heading 2</option>
                <option value="h3">Heading 3</option>
                <option value="quote">Quote</option>
            </select>

            {block === 'quote' && (
                <select
                    value={editor.getAttributes('blockquote').callout ?? ''}
                    onChange={(event) => editor.chain().focus().setCallout(event.target.value).run()}
                    data-a="toolbar:callout"
                    aria-label="Box kind"
                    title="What this quotation prints as"
                    className="mr-1 h-[28px] shrink-0 rounded-control border max-md:h-[40px] border-border-input bg-bg-surface py-0 pl-2 pr-7 text-[12px] text-text-body focus:border-brand-primary focus:ring-0"
                >
                    {CALLOUT_CHOICES.map((choice) => (
                        <option key={choice.key} value={choice.key}>{choice.name}</option>
                    ))}
                </select>
            )}

            <Divider />

            <Btn
                title="Bold"
                label="bold"
                active={editor.isActive('bold')}
                onClick={() => editor.chain().focus().toggleBold().run()}
            >
                <strong>B</strong>
            </Btn>
            <Btn
                title="Italic"
                label="italic"
                active={editor.isActive('italic')}
                onClick={() => editor.chain().focus().toggleItalic().run()}
            >
                <em className="font-serif">I</em>
            </Btn>
            <Btn
                title="Strikethrough"
                label="strike"
                active={editor.isActive('strike')}
                onClick={() => editor.chain().focus().toggleStrike().run()}
            >
                <s>S</s>
            </Btn>

            <Divider />

            {[1, 2, 3].map((level) => (
                <Btn
                    key={level}
                    title={`Heading ${level}`}
                    label={`h${level}`}
                    active={editor.isActive('heading', { level })}
                    onClick={() =>
                        editor.chain().focus().toggleHeading({ level }).run()
                    }
                >
                    H{level}
                </Btn>
            ))}

            <Divider />

            <Btn
                title="Bullet list"
                label="ul"
                active={editor.isActive('bulletList')}
                onClick={() => editor.chain().focus().toggleBulletList().run()}
            >
                <Icon name="ul" size={16} />
            </Btn>
            <Btn
                title="Numbered list"
                label="ol"
                active={editor.isActive('orderedList')}
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
            >
                <Icon name="ol" size={16} />
            </Btn>
            <Btn
                title="Quote"
                label="quote"
                active={editor.isActive('blockquote')}
                onClick={() => editor.chain().focus().toggleBlockquote().run()}
            >
                <Icon name="cite" size={16} />
            </Btn>
            <Btn
                title="Code block"
                label="code"
                active={editor.isActive('codeBlock')}
                onClick={() => editor.chain().focus().toggleCodeBlock().run()}
            >
                <Icon name="code" size={16} />
            </Btn>
            <Btn
                title="Insert table"
                label="table"
                active={editor.isActive('table')}
                onClick={() =>
                    editor
                        .chain()
                        .focus()
                        .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                        .run()
                }
            >
                <TableGlyph />
            </Btn>
            {onImage && (
                <Btn title="Insert picture" label="image" onClick={onImage}>
                    <ImageGlyph />
                </Btn>
            )}
            <Btn title="Insert footnote" label="footnote" onClick={onFootnote}>
                <span className="text-[12px] font-semibold">
                    fn<sup className="text-[8px]">1</sup>
                </span>
            </Btn>
            {onXref && (
                <Btn title="Insert cross-reference" label="xref" onClick={onXref}>
                    <span className="text-[12px] font-semibold">§→</span>
                </Btn>
            )}
            <Btn title="Insert chart" label="chart" onClick={onChart}>
                <ChartGlyph />
            </Btn>
            <Btn title="Inline equation" label="math-inline" onClick={() => onMath('inline')}>
                <span className="font-serif text-[14px] italic">x²</span>
            </Btn>
            <Btn title="Display equation" label="math-block" onClick={() => onMath('block')}>
                <span className="font-serif text-[15px]">∑</span>
            </Btn>

            {/* Row and column controls appear only inside a table, where
                they mean something. */}
            {editor.isActive('table') && (
                <>
                    <Divider />
                    {[
                        ['Add row below', 'row+', () => editor.chain().focus().addRowAfter().run()],
                        ['Delete row', 'row−', () => editor.chain().focus().deleteRow().run()],
                        ['Add column right', 'col+', () => editor.chain().focus().addColumnAfter().run()],
                        ['Delete column', 'col−', () => editor.chain().focus().deleteColumn().run()],
                        ['Delete table', 'del', () => editor.chain().focus().deleteTable().run()],
                    ].map(([title, text, run]) => (
                        <Btn key={text} title={title} label={`table:${text}`} onClick={run}>
                            <span className="text-[11.5px] font-medium">{text}</span>
                        </Btn>
                    ))}
                </>
            )}

            {/* The picture or table under the cursor: where it sits and how
                it is set (PlacementBar). */}
            {editor.isActive('table') && <TableBar editor={editor} />}
            {editor.isActive('image') && <ImageBar editor={editor} />}

            <Divider />

            <Btn
                title="Undo"
                label="undo"
                onClick={() => editor.chain().focus().undo().run()}
            >
                <Icon name="left" size={15} />
            </Btn>
            <Btn
                title="Redo"
                label="redo"
                onClick={() => editor.chain().focus().redo().run()}
            >
                <Icon name="right" size={15} />
            </Btn>

            {onDone && (
                <button
                    type="button"
                    data-a="toolbar:done"
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={onDone}
                    aria-label="Hide keyboard"
                    className="sticky right-0 ml-auto flex h-[40px] shrink-0 items-center gap-1.5 bg-bg-surface pr-1 pl-2 text-[13px] font-semibold text-brand-primary"
                >
                    <Icon name="kbd" size={17} />
                    Done
                </button>
            )}
        </div>
    );
}

/** A 3×3 grid; the icon set has no table glyph. */
function TableGlyph() {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" stroke="currentColor" strokeWidth="1.4" />
            <path d="M1.75 6.25h12.5M1.75 9.75h12.5M6 2.75v10.5M10 2.75v10.5" stroke="currentColor" strokeWidth="1.2" />
        </svg>
    );
}

/** A framed landscape; the icon set has no picture glyph. */
function ImageGlyph() {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" stroke="currentColor" strokeWidth="1.4" />
            <circle cx="5.5" cy="6.25" r="1.25" fill="currentColor" />
            <path d="M2.5 12l3.75-3.75 2.5 2.5L11 8.5l2.75 2.75" stroke="currentColor" strokeWidth="1.3" strokeLinejoin="round" />
        </svg>
    );
}

/** Three bars on a baseline. */
function ChartGlyph() {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M2 13.5h12" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
            <rect x="3" y="8" width="2.5" height="5" rx=".5" fill="currentColor" />
            <rect x="6.75" y="4" width="2.5" height="9" rx=".5" fill="currentColor" />
            <rect x="10.5" y="6.5" width="2.5" height="6.5" rx=".5" fill="currentColor" />
        </svg>
    );
}
