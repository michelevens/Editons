import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/react';
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
    useEffect(() => {
        sectionId.current = section?.id;
        setWordCount(section?.word_count ?? 0);
        setStatus('idle');
        if (editor && section) {
            editor.commands.setContent(section.content ?? '', { emitUpdate: false });
        }
    }, [section?.id, editor]);

    // Flush a pending save when the component goes away, so switching
    // chapters or closing the tab does not drop the last edit.
    useEffect(() => {
        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
                if (editor && status === 'dirty') {
                    // Keepalive so the request survives the page unloading.
                    navigator.sendBeacon?.(
                        `/sections/${sectionId.current}/content`,
                        new Blob([JSON.stringify({ content: editor.getJSON() })], {
                            type: 'application/json',
                        }),
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
            <div className="flex h-full items-center justify-center p-12 text-center">
                <p className="text-sm text-slate-500">
                    Select a chapter from the binder to start writing.
                </p>
            </div>
        );
    }

    const readingMinutes = Math.max(1, Math.round(wordCount / WORDS_PER_MINUTE));

    return (
        <div className="flex h-full flex-col">
            <Toolbar editor={editor} />

            <div className="flex-1 overflow-y-auto bg-white px-8 py-6">
                <h1 className="mb-1 font-serif text-3xl font-bold text-slate-900">
                    {section.title}
                </h1>
                {section.subtitle && (
                    <p className="mb-6 text-slate-600">{section.subtitle}</p>
                )}
                <EditorContent editor={editor} />
            </div>

            <footer className="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-2 text-xs text-slate-500">
                <span>
                    {wordCount.toLocaleString()} words · reading time{' '}
                    {readingMinutes} min
                </span>
                <SaveStatus status={status} />
            </footer>
        </div>
    );
}

function SaveStatus({ status }) {
    const map = {
        idle: ['text-slate-400', 'Ready'],
        dirty: ['text-amber-600', 'Unsaved changes'],
        saving: ['text-slate-500', 'Saving…'],
        saved: ['text-emerald-600', 'Saved'],
        error: ['text-red-600', 'Not saved — check your connection'],
    };
    const [cls, label] = map[status] ?? map.idle;

    return (
        <span className={`font-semibold ${cls}`} role="status">
            {label}
        </span>
    );
}

/**
 * Formatting controls.
 *
 * Only the marks StarterKit actually provides. The screenshot shows links,
 * images, tables and code blocks too; those need extensions that are not
 * installed, and a button that does nothing is worse than an absent one.
 */
function Toolbar({ editor }) {
    if (!editor) return null;

    const Btn = ({ onClick, active, title, children }) => (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className={`h-8 min-w-8 rounded px-2 text-sm font-semibold transition ${
                active
                    ? 'bg-indigo-100 text-indigo-700'
                    : 'text-slate-600 hover:bg-slate-100'
            }`}
        >
            {children}
        </button>
    );

    return (
        <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-white px-4 py-2">
            <Btn
                title="Bold"
                active={editor.isActive('bold')}
                onClick={() => editor.chain().focus().toggleBold().run()}
            >
                <strong>B</strong>
            </Btn>
            <Btn
                title="Italic"
                active={editor.isActive('italic')}
                onClick={() => editor.chain().focus().toggleItalic().run()}
            >
                <em>I</em>
            </Btn>
            <Btn
                title="Strikethrough"
                active={editor.isActive('strike')}
                onClick={() => editor.chain().focus().toggleStrike().run()}
            >
                <s>S</s>
            </Btn>

            <span className="mx-1 h-5 w-px bg-slate-200" />

            {[1, 2, 3].map((level) => (
                <Btn
                    key={level}
                    title={`Heading ${level}`}
                    active={editor.isActive('heading', { level })}
                    onClick={() =>
                        editor.chain().focus().toggleHeading({ level }).run()
                    }
                >
                    H{level}
                </Btn>
            ))}

            <span className="mx-1 h-5 w-px bg-slate-200" />

            <Btn
                title="Bullet list"
                active={editor.isActive('bulletList')}
                onClick={() => editor.chain().focus().toggleBulletList().run()}
            >
                ••
            </Btn>
            <Btn
                title="Numbered list"
                active={editor.isActive('orderedList')}
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
            >
                1.
            </Btn>
            <Btn
                title="Quote"
                active={editor.isActive('blockquote')}
                onClick={() => editor.chain().focus().toggleBlockquote().run()}
            >
                ❝
            </Btn>

            <span className="mx-1 h-5 w-px bg-slate-200" />

            <Btn title="Undo" onClick={() => editor.chain().focus().undo().run()}>
                ↶
            </Btn>
            <Btn title="Redo" onClick={() => editor.chain().focus().redo().run()}>
                ↷
            </Btn>
        </div>
    );
}
