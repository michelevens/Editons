import { Node } from '@tiptap/core';
import { NodeViewWrapper, ReactNodeViewRenderer } from '@tiptap/react';
import { useEffect, useState } from 'react';

/**
 * A chart in the manuscript: its data and labels, drawn by the server.
 *
 * The document stores the table, never a picture, so every export draws the
 * same chart from the same numbers. The drawing here comes from the same
 * renderer the exports use; previews are cached by their spec, so scrolling
 * past a chart does not redraw it.
 */
const drawn = new Map();

export async function drawChart(spec) {
    const key = JSON.stringify(spec);

    if (!drawn.has(key)) {
        drawn.set(
            key,
            window.axios
                .post('/charts/preview', spec)
                .then((response) => ({ svg: response.data.svg, description: response.data.description }))
                .catch((error) => {
                    drawn.delete(key);

                    return { error: error.response?.data?.message ?? 'The chart could not be drawn.' };
                }),
        );
    }

    return drawn.get(key);
}

export const SPEC_KEYS = ['type', 'title', 'xLabel', 'yLabel', 'data'];

export const specOf = (attrs) => Object.fromEntries(SPEC_KEYS.map((key) => [key, attrs[key] ?? '']));

function ChartView({ node, getPos, extension, selected }) {
    const [result, setResult] = useState(null);
    const spec = specOf(node.attrs);
    const key = JSON.stringify(spec);

    useEffect(() => {
        let live = true;
        drawChart(spec).then((value) => live && setResult(value));

        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return (
        <NodeViewWrapper
            as="figure"
            data-type="chart"
            className={`chart-node my-4 cursor-pointer rounded-control border p-2 ${
                selected ? 'border-brand-primary' : 'border-transparent hover:border-border-default'
            }`}
            onClick={() => extension.options.onEdit?.(node.attrs, getPos())}
            title="Click to edit the chart's data"
        >
            {!result && <div className="grid h-[200px] place-items-center text-[12px] text-text-secondary">Drawing…</div>}
            {result?.error && <p className="text-[12.5px] text-status-danger">{result.error}</p>}
            {result?.svg && (
                // The SVG carries its own role and description.
                <div
                    className="[&>svg]:h-auto [&>svg]:w-full"
                    dangerouslySetInnerHTML={{ __html: result.svg }}
                />
            )}
        </NodeViewWrapper>
    );
}

export const Chart = Node.create({
    name: 'chart',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addOptions() {
        return { onEdit: null };
    },

    addAttributes() {
        // Kept out of the DOM: the data can be long, and it is not markup.
        return Object.fromEntries(
            SPEC_KEYS.map((key) => [
                key,
                { default: key === 'type' ? 'bar' : '', rendered: false },
            ]),
        );
    },

    parseHTML() {
        return [
            {
                tag: 'figure[data-type="chart"]',
                getAttrs: (element) => {
                    try {
                        return JSON.parse(element.getAttribute('data-spec') || '{}');
                    } catch {
                        return false;
                    }
                },
            },
        ];
    },

    renderHTML({ node }) {
        return ['figure', { 'data-type': 'chart', 'data-spec': JSON.stringify(specOf(node.attrs)) }];
    },

    addNodeView() {
        return ReactNodeViewRenderer(ChartView);
    },
});
