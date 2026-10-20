import Icon from '@/Components/Studio/Icon';
import { EditorContent, useEditor } from '@tiptap/react';
import { BlockMath, InlineMath } from '@tiptap/extension-mathematics';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';
import 'katex/contrib/mhchem';
import 'katex/dist/katex.min.css';
import { useCallback, useEffect, useRef, useState } from 'react';
import ChartDialog from './ChartDialog';
import { CrossRef, localTargets, refreshNumbering, Xref, XrefDialog } from './CrossRef';
import { Chart } from './ChartNode';
import { Footnote, FootnoteDialog } from './FootnoteNode';
import ImageDialog from './ImageDialog';
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

export default function ManuscriptEditor({ section, bookId, onSaved, autosave = true }) {
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

    autosaveRef.current = autosave;

    // Read by the unmount flush. As an effect dependency, status re-ran that
    // effect on every change, and its cleanup cleared the save timer just
    // armed: a single edit (one equation, one paste) never saved, and every
    // status change fired a spurious beacon save.
    const statusRef = useRef(status);
    statusRef.current = status;

    const save = useCallback(
        async (json, reason = 'autosave') => {
            if (!sectionId.current) return;
            setStatus('saving');
            try {
                const res = await window.axios.put(
                    `/sections/${sectionId.current}/content`,
                    { content: json, reason },
                );
                setWordCount(res.data.word_count);
                setStatus('saved');
                onSaved?.(res.data);
            } catch {
                // Deliberately not silent. A writer who believes their work
                // is saved when it is not is the worst failure this product
                // can have, so the footer says so and keeps saying so.
                setStatus('error');
            }
        },
        [onSaved],
    );

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
                // Theses and reports are made of tables. Resizing is off:
                // column widths are a typesetting decision the export makes,
                // and widths dragged in the editor would not survive it.
                TableKit.configure({ table: { resizable: false } }),
                Image.configure({ inline: false, allowBase64: false }),
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
            ],
            content: section?.content ?? '',
            onUpdate: ({ editor }) => {
                setStatus('dirty');
                clearTimeout(timer.current);

                // With autosave off the edit waits for Ctrl/Cmd+S. A truthy
                // timer still marks it pending, so the flush on leaving the
                // chapter runs and turning autosave off never loses work to a
                // closed tab.
                if (!autosaveRef.current) {
                    timer.current = true;

                    return;
                }

                timer.current = setTimeout(
                    () => editor.isDestroyed || save(editor.getJSON()),
                    AUTOSAVE_DEBOUNCE_MS,
                );
            },
            editorProps: {
                attributes: {
                    class: 'prose prose-slate max-w-none focus:outline-none min-h-[60vh] px-2 py-4',
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
        setStatus('idle');

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
            storage.place = { scheme: data.scheme, prefix: data.section.prefix, offsets: data.section.offsets };
            storage.remote = data.targets;
            refreshNumbering(editor);

            return data;
        } catch {
            return null;
        }
    }, [bookId, section?.id, editor]);

    useEffect(() => {
        loadPlace();
    }, [loadPlace]);

    // Flush a pending save when the component goes away, so switching
    // chapters or closing the tab does not drop the last edit.
    useEffect(() => {
        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
                // isDestroyed matters most here: this runs DURING teardown,
                // which is exactly when Tiptap has already disposed of the
                // editor. Reading getJSON() off a destroyed instance throws
                // inside a cleanup function, where React cannot recover —
                // and the unsaved edit this exists to rescue is lost with it.
                if (editor && ! editor.isDestroyed && statusRef.current === 'dirty') {
                    // Keepalive so the request survives the page unloading.
                    //
                    // The token travels in the body because sendBeacon cannot
                    // set headers, and Laravel reads `_token` from there.
                    // Without it the save is rejected as CSRF and the last
                    // edit is lost — silently, since a beacon has no response
                    // to check. Exempting the route instead would drop CSRF
                    // on an endpoint that rewrites a manuscript.
                    navigator.sendBeacon?.(
                        `/sections/${sectionId.current}/content`,
                        new Blob(
                            [
                                JSON.stringify({
                                    content: editor.getJSON(),
                                    _token: csrfToken(),
                                }),
                            ],
                            { type: 'application/json' },
                        ),
                    );
                }
            }
        };
    }, [editor]);

    // Ctrl/Cmd+S forces a save and a version, because writers press it.
    useEffect(() => {
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                clearTimeout(timer.current);
                timer.current = null;
                if (editor && ! editor.isDestroyed) {
                    save(editor.getJSON(), 'manual');
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [editor, save]);

    if (!section) {
        return (
            <div className="grid h-full place-items-center p-12 text-center">
                <p className="text-[12.5px] text-text-secondary">
                    Select a chapter from the outline to start writing.
                </p>
            </div>
        );
    }

    const readingMinutes = Math.max(1, Math.round(wordCount / WORDS_PER_MINUTE));

    return (
        <div className="flex h-full flex-col">
            {/* Chapter header, h48 per the handoff: title, live word count,
                save state. */}
            <header className="flex h-[48px] shrink-0 items-center gap-3 border-b border-border-divider px-[21px]">
                <h2 className="truncate text-[14px] font-semibold text-text-primary">
                    {section.title}
                </h2>
                <span className="ml-auto shrink-0 text-[11.5px] tabular-nums text-text-secondary">
                    {wordCount.toLocaleString()} words
                </span>
                <SaveStatus status={status} />
            </header>

            <Toolbar
                editor={editor}
                onMath={(kind) => setMathEdit({ kind, latex: '', pos: null })}
                onImage={bookId ? () => setInserting(true) : null}
                onChart={() => setChartEdit({ attrs: {}, pos: null })}
                onFootnote={() => setNoteEdit({ text: '', pos: null })}
                onXref={bookId ? async () => {
                    const place = await loadPlace();
                    setXrefPick({ local: localTargets(editor.state.doc), remote: place?.targets ?? [] });
                } : null}
            />

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

            <div className="flex-1 overflow-y-auto px-[21px] pb-10 pt-[30px]">
                <EditorContent editor={editor} />
            </div>

            <footer className="flex h-[30px] shrink-0 items-center justify-between border-t border-border-divider px-[21px] text-[11px] text-text-secondary">
                <span>Reading time ~{readingMinutes} min</span>
                <span className="tabular-nums">
                    {wordCount.toLocaleString()} words
                </span>
            </footer>
        </div>
    );
}

function SaveStatus({ status }) {
    const map = {
        idle: ['text-text-secondary', 'Ready', false],
        dirty: ['text-status-warning', 'Unsaved changes', false],
        saving: ['text-text-secondary', 'Saving…', false],
        saved: ['text-status-success', 'Saved', true],
        error: ['text-status-danger', 'Not saved — check your connection', false],
    };
    const [cls, label, showCheck] = map[status] ?? map.idle;

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
function Toolbar({ editor, onMath, onImage, onChart, onFootnote, onXref }) {
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
            className={`grid h-[28px] min-w-[28px] place-items-center rounded-button px-1.5 text-[13px] transition ${
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

    return (
        <div className="flex h-[47px] shrink-0 flex-wrap items-center gap-0.5 border-b border-border-divider px-[21px]">
            <select
                value={block}
                onChange={(event) => setBlock(event.target.value)}
                data-a="toolbar:block"
                aria-label="Block type"
                className="mr-1 h-[28px] rounded-control border border-border-input bg-bg-surface py-0 pl-2 pr-7 text-[12px] text-text-body focus:border-brand-primary focus:ring-0"
            >
                <option value="p">Paragraph</option>
                <option value="h1">Heading 1</option>
                <option value="h2">Heading 2</option>
                <option value="h3">Heading 3</option>
                <option value="quote">Quote</option>
            </select>

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
