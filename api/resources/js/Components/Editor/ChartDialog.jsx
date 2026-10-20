import { useEffect, useState } from 'react';
import { drawChart } from './ChartNode';

/**
 * Make or edit a chart from a small table.
 *
 * The data box takes what a spreadsheet copies: select the cells in Excel,
 * Sheets or Numbers, copy, paste. First row is headers, first column the
 * categories (or x values for a scatter), each further column a series.
 */
const TYPES = [
    ['bar', 'Bar'],
    ['line', 'Line'],
    ['scatter', 'Scatter'],
    ['pie', 'Pie'],
];

const EXAMPLE = 'Model,Traditional,Hybrid\nNaive Bayes,73.5,73.1\nLogistic regression,80.2,79.4\nDecision tree,78.6,79.0';

export default function ChartDialog({ initial, onSave, onDelete, onClose }) {
    const [spec, setSpec] = useState({
        type: initial.attrs?.type || 'bar',
        title: initial.attrs?.title ?? '',
        xLabel: initial.attrs?.xLabel ?? '',
        yLabel: initial.attrs?.yLabel ?? '',
        data: initial.attrs?.data ?? '',
    });
    const [preview, setPreview] = useState(null);
    const editing = initial.pos !== null && initial.pos !== undefined;
    const set = (key) => (event) => setSpec({ ...spec, [key]: event.target.value });

    // Redrawn a moment after typing stops, not on every keystroke.
    useEffect(() => {
        if (!spec.data.trim()) {
            setPreview(null);

            return undefined;
        }

        let live = true;
        const timer = setTimeout(() => {
            drawChart(spec).then((value) => live && setPreview(value));
        }, 350);

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [spec]);

    const ready = preview?.svg && !preview.error;

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-black/30 p-4"
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={editing ? 'Edit chart' : 'Insert chart'}
                className="grid max-h-[92vh] w-full max-w-[980px] grid-cols-1 gap-5 overflow-y-auto rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl md:grid-cols-[380px_1fr]"
                onKeyDown={(event) => event.key === 'Escape' && onClose()}
            >
                <div>
                    <h3 className="text-[15px] font-semibold text-text-primary">
                        {editing ? 'Edit chart' : 'Insert chart'}
                    </h3>

                    <div className="mt-3 flex gap-1 rounded-control border border-border-input p-0.5 text-[12.5px]">
                        {TYPES.map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                data-a={`chart:type:${value}`}
                                onClick={() => setSpec({ ...spec, type: value })}
                                className={`flex-1 rounded-[5px] py-1 ${
                                    spec.type === value
                                        ? 'bg-brand-primary-subtle font-semibold text-brand-primary'
                                        : 'text-text-secondary'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <label className="mt-3 block text-[12px] font-medium text-text-secondary">
                        Title
                        <input value={spec.title} onChange={set('title')} data-a="chart:title"
                            className="mt-1 h-[32px] w-full rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0" />
                    </label>

                    {spec.type !== 'pie' && (
                        <div className="mt-2 grid grid-cols-2 gap-2">
                            <label className="text-[12px] font-medium text-text-secondary">
                                Horizontal axis
                                <input value={spec.xLabel} onChange={set('xLabel')} placeholder="First column's name"
                                    className="mt-1 h-[32px] w-full rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0" />
                            </label>
                            <label className="text-[12px] font-medium text-text-secondary">
                                Vertical axis
                                <input value={spec.yLabel} onChange={set('yLabel')} placeholder="e.g. Accuracy (%)"
                                    className="mt-1 h-[32px] w-full rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0" />
                            </label>
                        </div>
                    )}

                    <label className="mt-3 block text-[12px] font-medium text-text-secondary">
                        Data: paste from a spreadsheet, or type with commas
                        <textarea
                            value={spec.data}
                            onChange={set('data')}
                            data-a="chart:data"
                            rows={9}
                            spellCheck={false}
                            placeholder={EXAMPLE}
                            className="mt-1 w-full rounded-control border-border-input font-mono text-[12.5px] focus:border-brand-primary focus:ring-0"
                        />
                    </label>
                    <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                        First row: headers. First column: categories
                        {spec.type === 'scatter' ? ' (x values)' : ''}
                        {spec.type === 'pie' ? '; second column: the amounts' : '; each further column is a series'}.
                        {!spec.data.trim() && (
                            <button type="button" className="ml-1 text-brand-primary underline"
                                onClick={() => setSpec({ ...spec, data: EXAMPLE })}>
                                Use an example
                            </button>
                        )}
                    </p>
                </div>

                <div className="flex min-h-[300px] flex-col">
                    <div className="flex-1 rounded-control border border-border-divider bg-white p-3">
                        {preview?.error && <p className="text-[12.5px] text-status-danger">{preview.error}</p>}
                        {preview?.svg && (
                            <div className="[&>svg]:h-auto [&>svg]:w-full" dangerouslySetInnerHTML={{ __html: preview.svg }} />
                        )}
                        {!preview && (
                            <p className="grid h-full place-items-center text-[12.5px] text-text-secondary">
                                The chart appears here as you type.
                            </p>
                        )}
                    </div>

                    <div className="mt-4 flex items-center gap-2">
                        {editing && (
                            <button type="button" onClick={onDelete} data-a="chart:delete"
                                className="h-[34px] rounded-control px-3 text-[13px] text-status-danger hover:bg-status-danger-bg">
                                Delete
                            </button>
                        )}
                        <button type="button" onClick={onClose}
                            className="ml-auto h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle">
                            Cancel
                        </button>
                        <button
                            type="button"
                            data-a="chart:save"
                            disabled={!ready}
                            onClick={() => onSave(spec)}
                            className="h-[34px] rounded-control bg-brand-primary px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            {editing ? 'Update' : 'Insert'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
