import { useEffect, useState } from 'react';
import { faceKey } from './coverText';

/**
 * Cover faces in the browser: the same TTF files the server draws with
 * (App\Covers\CoverFonts), registered through the FontFace API under a name
 * of their own each, and their metrics, which coverText.js measures with.
 *
 * Each face is its own CSS family, so the browser never picks a weight or
 * fakes an italic: what is drawn is that file, as GD draws it.
 */

const metrics = {};
const loading = {};

export function cssFamily(face) {
    return `cvf-${face.replace(/[^a-z0-9-]/gi, '-')}`;
}

/** The URLs for a face key, from the payload's catalog. */
export function faceUrls(editor, face) {
    if (face === 'fallback') return editor.fallback;

    const [family, style] = face.split('.');

    return editor.fonts.find((f) => f.key === family)?.styles?.[style] ?? null;
}

/** Load a face and its metrics once; resolves when both are ready. */
export function loadFace(editor, face) {
    if (metrics[face]) return Promise.resolve(metrics[face]);

    if (!loading[face]) {
        const urls = faceUrls(editor, face);

        if (!urls) return Promise.reject(new Error(`No cover face ${face}`));

        const font = typeof FontFace === 'function'
            ? new FontFace(cssFamily(face), `url(${urls.font})`).load().then((loaded) => {
                document.fonts.add(loaded);
            }).catch(() => {
                // The metrics still place every glyph; only the shapes
                // would be the browser's fallback.
            })
            : Promise.resolve();

        loading[face] = Promise.all([
            window.axios.get(urls.metrics).then((response) => response.data),
            font,
        ]).then(([data]) => {
            metrics[face] = data;

            return data;
        }).catch((error) => {
            delete loading[face];
            throw error;
        });
    }

    return loading[face];
}

const shapes = {};

/**
 * Only the font file, for showing a family in its own type (the font
 * picker): no metrics, which only faces in use need.
 */
export function useFontFiles(editor, faces) {
    const [, setTick] = useState(0);
    const key = faces.join('|');

    useEffect(() => {
        let live = true;

        for (const face of faces) {
            if (shapes[face] || metrics[face] || typeof FontFace !== 'function') continue;

            const urls = faceUrls(editor, face);

            if (!urls) continue;

            shapes[face] = new FontFace(cssFamily(face), `url(${urls.font})`)
                .load()
                .then((loaded) => {
                    document.fonts.add(loaded);
                    if (live) setTick((t) => t + 1);
                })
                .catch(() => {});
        }

        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);
}

/** Every face the layers use, plus the fallback. */
export function facesOf(items) {
    const faces = new Set(['fallback']);

    for (const layer of items ?? []) {
        faces.add(faceKey(layer.font, layer.weight, layer.italic));
    }

    return [...faces];
}

/**
 * The metrics for a set of faces, loading any not yet here. Returns the
 * shared metrics table; it fills in as faces arrive, and the component
 * re-renders each time.
 */
export function useFaceMetrics(editor, faces) {
    const [, setTick] = useState(0);
    const key = faces.join('|');

    useEffect(() => {
        let live = true;

        for (const face of faces) {
            if (metrics[face]) continue;

            loadFace(editor, face)
                .then(() => live && setTick((t) => t + 1))
                .catch(() => {});
        }

        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return metrics;
}

/** Whether a layer's face (and the fallback) can be laid out yet. */
export function ready(table, layer) {
    return Boolean(table.fallback && table[faceKey(layer.font, layer.weight, layer.italic)]);
}
