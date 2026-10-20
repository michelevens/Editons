import katex from 'katex';
import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Write or edit an equation, with the typeset result beside the source.
 *
 * LaTeX because it is what every discipline that writes equations already
 * uses, and what a supervisor will paste in a comment. The preview uses the
 * same KaTeX the editor renders with; exports typeset the same source.
 *
 * The snippets are for the student who knows what a fraction is but not
 * \frac: they insert the pattern at the cursor, not a finished equation.
 */
const SNIPPETS = [
    ['Fraction', '\\frac{a}{b}'],
    ['Power', 'x^{2}'],
    ['Subscript', 'x_{i}'],
    ['Root', '\\sqrt{x}'],
    ['Sum', '\\sum_{i=1}^{n} x_i'],
    ['Integral', '\\int_{a}^{b} f(x)\\,dx'],
    ['Limit', '\\lim_{x \\to \\infty}'],
    ['Matrix', '\\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix}'],
    ['Aligned', '\\begin{aligned} a &= b + c \\\\ &= d \\end{aligned}'],
    ['Cases', 'f(x) = \\begin{cases} 1 & x > 0 \\\\ 0 & \\text{otherwise} \\end{cases}'],
    ['Greek', '\\alpha \\beta \\gamma \\mu \\sigma'],
    ['Chemistry', '\\ce{2H2 + O2 -> 2H2O}'],
];

export default function MathDialog({ initial, onSave, onDelete, onClose }) {
    // The number is edited in its own field, so the source shows without it.
    const [latex, setLatex] = useState((initial.latex ?? '').replace(/\s*\\tag\*?\{[^}]*\}/g, ''));
    const [display, setDisplay] = useState(initial.kind === 'block');
    const [number, setNumber] = useState(() => (initial.latex ?? '').match(/\\tag\{([^}]*)\}/)?.[1] ?? '');
    const field = useRef(null);
    const editing = initial.pos !== null && initial.pos !== undefined;

    useEffect(() => {
        field.current?.focus();
    }, []);

    // The number lives in the LaTeX as \tag{…}, the standard way, so it
    // survives copy, export and a supervisor's LaTeX tools.
    const source = useMemo(() => {
        const body = latex.replace(/\\tag\*?\{[^}]*\}/g, '').trim();

        return display && number.trim() ? `${body} \\tag{${number.trim()}}` : body;
    }, [latex, display, number]);

    const preview = useMemo(() => {
        try {
            return {
                html: katex.renderToString(source || '\\square', {
                    displayMode: display,
                    throwOnError: true,
                }),
            };
        } catch (error) {
            return { error: error.message.replace(/^KaTeX parse error: /, '') };
        }
    }, [source, display]);

    const insert = (snippet) => {
        const node = field.current;
        const start = node?.selectionStart ?? latex.length;
        const end = node?.selectionEnd ?? latex.length;
        const next = latex.slice(0, start) + snippet + latex.slice(end);

        setLatex(next);
        requestAnimationFrame(() => {
            node?.focus();
            node?.setSelectionRange(start + snippet.length, start + snippet.length);
        });
    };

    const save = () => {
        if (!source.trim() || preview.error) return;
        onSave({ latex: source, display });
    };

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-black/30 p-4"
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={editing ? 'Edit equation' : 'Insert equation'}
                className="w-full max-w-[640px] rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
                onKeyDown={(event) => {
                    if (event.key === 'Escape') onClose();
                    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) save();
                }}
            >
                <div className="flex items-center justify-between">
                    <h3 className="text-[15px] font-semibold text-text-primary">
                        {editing ? 'Edit equation' : 'Insert equation'}
                    </h3>
                    <div className="flex rounded-control border border-border-input p-0.5 text-[12px]">
                        {[
                            [false, 'In the line'],
                            [true, 'On its own line'],
                        ].map(([value, label]) => (
                            <button
                                key={label}
                                type="button"
                                data-a={`math:${value ? 'block' : 'inline'}`}
                                onClick={() => setDisplay(value)}
                                className={`rounded-[5px] px-2.5 py-1 ${
                                    display === value
                                        ? 'bg-brand-primary-subtle font-semibold text-brand-primary'
                                        : 'text-text-secondary'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="mt-3 flex flex-wrap gap-1.5">
                    {SNIPPETS.map(([label, snippet]) => (
                        <button
                            key={label}
                            type="button"
                            onClick={() => insert(snippet)}
                            className="rounded-full border border-border-input px-2.5 py-0.5 text-[11.5px] text-text-body hover:bg-bg-subtle"
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <label className="mt-3 block text-[12px] font-medium text-text-secondary">
                    LaTeX
                    <textarea
                        ref={field}
                        data-a="math:latex"
                        value={latex}
                        onChange={(event) => setLatex(event.target.value)}
                        rows={3}
                        spellCheck={false}
                        placeholder="e.g. \bar{x} = \frac{1}{n}\sum_{i=1}^{n} x_i"
                        className="mt-1 w-full rounded-control border-border-input font-mono text-[13px] focus:border-brand-primary focus:ring-0"
                    />
                </label>

                {display && (
                    <label className="mt-2 flex items-center gap-2 text-[12px] text-text-secondary">
                        Equation number
                        <input
                            value={number}
                            data-a="math:number"
                            onChange={(event) => setNumber(event.target.value)}
                            placeholder="3.1"
                            className="h-[30px] w-[90px] rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0"
                        />
                        <span>optional, shown as ({number || '3.1'})</span>
                    </label>
                )}

                <div
                    className="mt-3 min-h-[64px] overflow-x-auto rounded-control border border-border-divider bg-bg-subtle px-4 py-3 text-center"
                    aria-live="polite"
                >
                    {preview.error ? (
                        <p className="text-left text-[12.5px] text-status-danger">{preview.error}</p>
                    ) : (
                        <span dangerouslySetInnerHTML={{ __html: preview.html }} />
                    )}
                </div>

                <div className="mt-4 flex items-center gap-2">
                    {editing && (
                        <button
                            type="button"
                            data-a="math:delete"
                            onClick={onDelete}
                            className="h-[34px] rounded-control px-3 text-[13px] text-status-danger hover:bg-status-danger-bg"
                        >
                            Delete
                        </button>
                    )}
                    <span className="ml-auto text-[11.5px] text-text-secondary">Ctrl/⌘+Enter</span>
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        data-a="math:save"
                        onClick={save}
                        disabled={!source.trim() || !!preview.error}
                        className="h-[34px] rounded-control bg-brand-primary px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        {editing ? 'Update' : 'Insert'}
                    </button>
                </div>
            </div>
        </div>
    );
}
