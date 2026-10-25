import { useState } from 'react';

/**
 * The Amazon KDP guided page: KDP's three pages (Details, Content, Rights &
 * Pricing) with every value ready and a Copy button per field
 * (App\Publishing\Publish\KdpGuide). Used by the studio's Publish pane and
 * by the printable KDP checklist.
 *
 * `onFix(pane)` opens the studio pane that fixes a field; `editor` renders
 * the keyword / category / audience editor where a field is editable.
 */
export default function KdpGuideView({ guide, onFix, editor = null, idPrefix = 'kdp' }) {
    return (
        <div className="space-y-3" data-a={`publish:kdp:${guide.format}`}>
            {guide.sections.map((section, index) => (
                <section key={section.key} className="rounded-tile border border-border-default bg-bg-surface">
                    <h4 className="flex items-center gap-2 border-b border-border-default px-3 py-2 text-[12.5px] font-semibold text-text-primary">
                        <span className="grid h-5 w-5 place-items-center rounded-full bg-brand-fill text-[11px] font-bold text-white" aria-hidden="true">
                            {index + 1}
                        </span>
                        {section.title}
                    </h4>
                    <dl className="divide-y divide-border-default">
                        {section.fields.map((field) => (
                            <Field key={field.key} field={field} onFix={onFix} editor={editor} id={`${idPrefix}-${guide.format}-${field.key}`} />
                        ))}
                    </dl>
                </section>
            ))}
        </div>
    );
}

const TONE = {
    ok: 'text-status-success',
    warn: 'text-status-warning',
    fail: 'text-status-danger',
};

const MARK = { ok: '✓', warn: '!', fail: '✕' };

function Field({ field, onFix, editor, id }) {
    const values = Array.isArray(field.value) ? field.value : field.value != null ? [field.value] : [];
    const multi = Array.isArray(field.value) && field.key === 'keywords';

    return (
        <div className="grid grid-cols-1 gap-1 px-3 py-2.5 sm:grid-cols-[180px_minmax(0,1fr)] sm:gap-3" data-a={`kdp:field:${field.key}`}>
            <dt className="flex items-start gap-1.5 text-[12px] font-medium text-text-secondary">
                <span className={`${TONE[field.status] ?? ''} w-3 shrink-0 font-bold`} aria-label={field.status}>
                    {MARK[field.status] ?? ''}
                </span>
                <span>{field.label}</span>
            </dt>
            <dd className="min-w-0">
                {values.length === 0 ? (
                    <p className="text-[12.5px] italic text-text-secondary">—</p>
                ) : multi ? (
                    <ol className="space-y-1">
                        {values.map((value, i) => (
                            <li key={`${value}-${i}`} className="flex items-center gap-2">
                                <span className="w-4 text-right text-[11px] tabular-nums text-text-secondary">{i + 1}</span>
                                <code className="min-w-0 flex-1 break-words rounded bg-bg-subtle px-1.5 py-0.5 text-[12.5px] text-text-primary">{value}</code>
                                <CopyButton text={value} label={`Copy keyword ${i + 1}`} />
                            </li>
                        ))}
                    </ol>
                ) : (
                    <div className="flex items-start gap-2">
                        <div className="min-w-0 flex-1">
                            {values.map((value, i) => (
                                <p
                                    key={`${i}`}
                                    className={`break-words text-[12.5px] text-text-primary ${field.html ? 'max-h-32 overflow-auto whitespace-pre-wrap rounded bg-bg-subtle px-1.5 py-1 font-mono text-[11.5px]' : ''}`}
                                >
                                    {value}
                                </p>
                            ))}
                            {field.extra && (
                                <p className="mt-0.5 text-[12px] text-text-body">
                                    {field.extra.label}: <span className="font-semibold">{field.extra.value}</span>{' '}
                                    <CopyButton text={field.extra.value} label={`Copy ${field.extra.label}`} small />
                                </p>
                            )}
                        </div>
                        {field.copy && <CopyButton text={values.join('\n')} label={`Copy ${field.label}`} />}
                    </div>
                )}

                {field.download && (
                    <a
                        href={field.download}
                        data-a={`kdp:download:${field.key}`}
                        className="mt-1 inline-block text-[12px] font-semibold text-brand-primary hover:underline"
                    >
                        Download
                    </a>
                )}

                {field.royalty != null && (
                    <p className="mt-0.5 text-[12px] text-text-body">
                        Royalty about <span className="font-semibold">${Number(field.royalty).toFixed(2)}</span> a copy
                    </p>
                )}

                {field.note && <p className={`mt-0.5 text-[11.5px] leading-snug ${field.status === 'fail' ? 'text-status-danger' : 'text-text-secondary'}`}>{field.note}</p>}

                <div className="mt-0.5 flex flex-wrap gap-x-3">
                    {field.fix && onFix && (
                        <button type="button" onClick={() => onFix(field.fix.pane)} className="text-[11.5px] font-semibold text-brand-primary hover:underline">
                            {field.fix.label}
                        </button>
                    )}
                    {field.source && (
                        <a href={field.source} target="_blank" rel="noopener noreferrer" className="text-[11.5px] text-text-secondary underline hover:text-text-primary">
                            KDP help
                        </a>
                    )}
                </div>

                {field.editable && editor && editor(field, id)}
            </dd>
        </div>
    );
}

/** Copy one value to the clipboard, saying so for a screen reader too. */
export function CopyButton({ text, label, small = false }) {
    const [done, setDone] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(String(text ?? ''));
            setDone(true);
            window.setTimeout(() => setDone(false), 1500);
        } catch {
            // No clipboard (an old browser, an insecure origin): the value
            // is on screen to select by hand.
        }
    };

    return (
        <button
            type="button"
            onClick={copy}
            aria-label={label}
            className={`shrink-0 rounded-control border border-border-input font-medium text-text-body transition hover:border-brand-primary/40 ${small ? 'px-1.5 py-0 text-[10.5px]' : 'px-2 py-0.5 text-[11.5px]'}`}
        >
            <span aria-live="polite">{done ? 'Copied' : 'Copy'}</span>
        </button>
    );
}
