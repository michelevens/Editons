import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Design & Layout.
 *
 * The preview renders the author's own prose at the chosen size, not filler.
 * A sample paragraph would show the typography but tell them nothing about
 * how their book actually sets — which is the only question this screen
 * exists to answer.
 */
export default function LayoutPanel({ bookId, onNotify }) {
    const [data, setData] = useState(null);
    const [sectionId, setSectionId] = useState(null);
    const [busy, setBusy] = useState(false);

    // Sliders fire continuously. Without this, dragging the font size sends
    // a request per pixel and the preview arrives out of order.
    const pending = useRef(null);
    // Every change since the last send. A single timer used to send only
    // the last edit, so a second field typed within the debounce dropped
    // the first, and the server's reply then put the old value back.
    const queued = useRef({});

    const load = useCallback(async (id) => {
        const query = id ? `?section=${id}` : '';
        const response = await window.axios.get(`/books/${bookId}/design${query}`);
        setData(response.data);
        setSectionId(response.data.preview?.id ?? null);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const save = (changes, { immediate = false } = {}) => {
        // Optimistic: the preview should track the slider, not lag a round
        // trip behind it.
        setData((current) => ({ ...current, layout: { ...current.layout, ...changes } }));

        queued.current = { ...queued.current, ...changes };
        clearTimeout(pending.current);

        const send = async () => {
            const batch = queued.current;
            queued.current = {};
            setBusy(true);

            try {
                const response = await window.axios.patch(
                    `/books/${bookId}/layout${sectionId ? `?section=${sectionId}` : ''}`,
                    batch,
                );

                setData(response.data);
            } catch (error) {
                onNotify?.(error.response?.data?.message ?? 'That layout change was not saved.');
                load(sectionId);
            } finally {
                setBusy(false);
            }
        };

        immediate ? send() : (pending.current = setTimeout(send, 400));
    };

    const reset = async () => {
        setBusy(true);

        try {
            const response = await window.axios.post(`/books/${bookId}/layout/reset`);
            setData(response.data);
        } finally {
            setBusy(false);
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { layout, trims, preview, sections } = data;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">
                        Design &amp; Layout
                    </h2>
                    <p className="text-[11.5px] text-text-secondary">
                        Interior layout for print and eBook
                    </p>
                </div>

                <button
                    data-a="layout:reset"
                    onClick={reset}
                    disabled={busy}
                    className="ml-auto rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50"
                >
                    Reset to defaults
                </button>
            </header>

            <div className="grid min-h-0 flex-1 gap-4 overflow-auto p-4 lg:grid-cols-[320px_1fr]">
                <div className="space-y-4">
                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">Page</h3>

                        <Field label="Trim size">
                            <select
                                value={layout.trim}
                                data-a="layout:trim"
                                onChange={(event) => save({ trim: event.target.value }, { immediate: true })}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            >
                                {trims.map((trim) => (
                                    <option key={trim} value={trim}>{trim}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Margins">
                            <select
                                value={layout.margins}
                                data-a="layout:margins"
                                onChange={(event) => save({ margins: event.target.value }, { immediate: true })}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            >
                                {['Narrow', 'Standard', 'Wide'].map((option) => (
                                    <option key={option} value={option}>{option}</option>
                                ))}
                            </select>
                        </Field>
                    </section>

                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">Type</h3>

                        <Field label="Body font">
                            <select
                                value={layout.font}
                                data-a="layout:font"
                                onChange={(event) => save({ font: event.target.value }, { immediate: true })}
                                className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                            >
                                <option value="serif">Serif (Source Serif)</option>
                                <option value="sans">Sans (Inter)</option>
                            </select>
                        </Field>

                        <Field label={`Font size — ${Number(layout.size).toFixed(1)} pt`}>
                            <input
                                type="range"
                                min="9"
                                max="14"
                                step="0.5"
                                value={layout.size}
                                data-a="layout:size"
                                onChange={(event) => save({ size: Number(event.target.value) })}
                                className="w-full accent-brand-primary"
                            />
                        </Field>

                        <Field label={`Line spacing — ${Number(layout.leading).toFixed(2)}`}>
                            <input
                                type="range"
                                min="1.2"
                                max="1.8"
                                step="0.05"
                                value={layout.leading}
                                data-a="layout:leading"
                                onChange={(event) => save({ leading: Number(event.target.value) })}
                                className="w-full accent-brand-primary"
                            />
                        </Field>
                    </section>

                    <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                        <h3 className="mb-2.5 text-[13px] font-semibold text-text-primary">Details</h3>

                        <Toggle
                            label="Drop cap"
                            hint="A large first letter at the start of each chapter."
                            checked={layout.dropcap}
                            name="dropcap"
                            onChange={(value) => save({ dropcap: value }, { immediate: true })}
                        />

                        <Toggle
                            label="Running headers"
                            hint="The book title at the top of every page."
                            checked={layout.headers}
                            name="headers"
                            onChange={(value) => save({ headers: value }, { immediate: true })}
                        />
                    </section>
                </div>

                <section className="min-w-0 rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <h3 className="text-[13px] font-semibold text-text-primary">Preview</h3>

                        {sections.length > 1 && (
                            <select
                                value={sectionId ?? ''}
                                data-a="layout:preview-section"
                                onChange={(event) => load(Number(event.target.value))}
                                className="ml-auto rounded-control border border-border-input px-2 py-1 text-[11.5px] focus:border-brand-primary focus:ring-0"
                            >
                                {sections.map((section) => (
                                    <option key={section.id} value={section.id}>
                                        {section.title}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    {preview ? (
                        <>
                            <PagePreview layout={layout} html={preview.html} title={preview.title} />
                            <p className="mt-2.5 text-[11.5px] text-text-secondary">
                                {layout.trim} · {layout.font === 'serif' ? 'Serif' : 'Sans'}{' '}
                                {Number(layout.size).toFixed(1)} pt ·{' '}
                                {layout.margins.toLowerCase()} margins
                            </p>
                        </>
                    ) : (
                        <p className="py-8 text-center text-[12px] text-text-secondary">
                            Write a chapter to see how the book will set.
                        </p>
                    )}
                </section>
            </div>
        </div>
    );
}

/**
 * A page at the book's real proportions.
 *
 * The page is scaled to fit the panel, and the type is scaled with it, so the
 * ratio of text to page stays true. Showing 11pt type at screen size inside a
 * shrunken page would misrepresent how dense the printed page is.
 */
function PagePreview({ layout, html, title }) {
    const [width, height] = parseTrim(layout.trim);
    const aspect = height / width;

    const margin = { Narrow: 0.5, Wide: 1 }[layout.margins] ?? 0.75;

    // The page is drawn at a fixed CSS width; everything in inches is
    // converted at that scale.
    const pageWidth = 320;
    const pxPerInch = pageWidth / width;
    const fontPx = (layout.size / 72) * pxPerInch;

    return (
        <div
            className="mx-auto overflow-hidden bg-white shadow-[0_1px_8px_rgba(0,0,0,0.12)]"
            style={{
                width: pageWidth,
                height: pageWidth * aspect,
                padding: margin * pxPerInch,
                fontFamily: layout.font === 'serif'
                    ? "'Source Serif 4', Georgia, serif"
                    : "Inter, system-ui, sans-serif",
                fontSize: fontPx,
                lineHeight: layout.leading,
                color: '#1a1a1a',
            }}
        >
            {layout.headers && (
                <p
                    className="mb-2 truncate text-center uppercase tracking-wide opacity-55"
                    style={{ fontSize: fontPx * 0.72 }}
                >
                    {title}
                </p>
            )}

            <div
                className={`manuscript-preview ${layout.dropcap ? 'has-dropcap' : ''}`}
                dangerouslySetInnerHTML={{ __html: html }}
            />

            <style>{`
                .manuscript-preview p { margin: 0; text-indent: 1.5em; text-align: justify; }
                .manuscript-preview h1, .manuscript-preview h2, .manuscript-preview h3 {
                    margin: 0 0 .5em; line-height: 1.2;
                }
                .manuscript-preview h1 { font-size: 1.5em; }
                .manuscript-preview h2 { font-size: 1.2em; }
                .manuscript-preview h1 + p, .manuscript-preview h2 + p,
                .manuscript-preview h3 + p { text-indent: 0; }
                .manuscript-preview.has-dropcap h1 + p::first-letter,
                .manuscript-preview.has-dropcap h2 + p::first-letter {
                    float: left; font-size: 3.2em; line-height: .85; padding: .05em .08em 0 0;
                }
            `}</style>
        </div>
    );
}

/** "6 x 9 in" to inches. Falls back to US trade, the commonest trim. */
function parseTrim(trim) {
    const found = /([\d.]+)\s*x\s*([\d.]+)/.exec(trim ?? '');

    return found ? [Number(found[1]), Number(found[2])] : [6, 9];
}

function Field({ label, children }) {
    return (
        <label className="mb-2.5 block">
            <span className="mb-1 block text-[11.5px] font-medium text-text-secondary">
                {label}
            </span>
            {children}
        </label>
    );
}

function Toggle({ label, hint, checked, name, onChange }) {
    return (
        <div className="flex items-start justify-between gap-3 border-b border-border-default py-2 last:border-0">
            <div className="min-w-0">
                <p className="text-[12.5px] font-medium text-text-primary">{label}</p>
                <p className="text-[11px] leading-snug text-text-secondary">{hint}</p>
            </div>

            <button
                role="switch"
                aria-checked={checked}
                aria-label={label}
                data-a={`layout:${name}`}
                onClick={() => onChange(!checked)}
                className={`mt-0.5 h-[20px] w-[34px] shrink-0 rounded-full p-[2px] transition ${
                    checked ? 'bg-brand-fill' : 'bg-border-input'
                }`}
            >
                <span
                    className={`block h-4 w-4 rounded-full bg-white transition-transform ${
                        checked ? 'translate-x-[14px]' : ''
                    }`}
                />
            </button>
        </div>
    );
}
