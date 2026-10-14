import Icon from '@/Components/Studio/Icon';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useCallback, useEffect, useRef, useState } from 'react';

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

export default function ManuscriptEditor({ section, onSaved }) {
    const [status, setStatus] = useState('idle'); // idle | dirty | saving | saved | error
    const [wordCount, setWordCount] = useState(section?.word_count ?? 0);

    // Refs rather than state: these are read inside callbacks and must not
    // retrigger the effect that wires the editor up.
    const timer = useRef(null);
    const sectionId = useRef(section?.id);

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
            extensions: [
                StarterKit.configure({
                    heading: { levels: [1, 2, 3] },
                }),
            ],
            content: section?.content ?? '',
            editorProps: {
                attributes: {
                    class: 'prose prose-slate max-w-none focus:outline-none min-h-[60vh] px-2 py-4',
                },
            },
            onUpdate: ({ editor }) => {
                setStatus('dirty');
                clearTimeout(timer.current);
                timer.current = setTimeout(
                    () => save(editor.getJSON()),
                    AUTOSAVE_DEBOUNCE_MS,
                );
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

        if (editor && section) {
            editor.commands.setContent(section.content ?? '', { emitUpdate: false });
        }
    }, [section?.id, section?.revision, editor]);

    // Flush a pending save when the component goes away, so switching
    // chapters or closing the tab does not drop the last edit.
    useEffect(() => {
        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
                if (editor && status === 'dirty') {
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
    }, [editor, status]);

    // Ctrl/Cmd+S forces a save and a version, because writers press it.
    useEffect(() => {
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                clearTimeout(timer.current);
                if (editor) save(editor.getJSON(), 'manual');
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

            <Toolbar editor={editor} />

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
 * Only the commands StarterKit actually provides. The mockup also shows link,
 * image and table buttons; those need extensions that are not installed, and a
 * button that does nothing is worse than an absent one.
 */
function Toolbar({ editor }) {
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
