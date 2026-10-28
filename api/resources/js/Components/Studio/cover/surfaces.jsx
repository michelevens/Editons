import { layoutLayer, spineLayout } from './coverText';

/**
 * The surfaces Cover Studio's layer editor works on: the front cover, and
 * the print wrap's back panel and spine. One editor, three models, each
 * matching the server's (App\Covers\CoverLayers, CoverRenderer::place):
 *
 *  - front: 1600 × 2560; x, width of the width, y, size of the height.
 *  - back: the back panel at 300 dpi, from the sheet's left edge (bleed
 *    included) to the spine fold; the same fractions.
 *  - spine: its reading frame, the spine's length by its thickness at 300
 *    dpi; x, width, size and y (the box's centre off the centre line) are
 *    all fractions of the length, so a layer stays centred as the page
 *    count changes.
 *
 * A surface turns a layer into a box in its pixels (place), knows its
 * units (unitY: what y, height and size are fractions of), its safe area,
 * the boxes nothing may cover (the barcode) and the ground drawn under it.
 */

const pxPerPt = 300 / 72;

/** The front: as ADR-0038 set it out. */
export function frontSurface(data) {
    const { editor, cover } = data;
    const W = editor.width;
    const H = editor.height;
    const inset = editor.safe * W;

    return {
        panel: 'front',
        width: W,
        height: H,
        unitY: H,
        yRange: [0, 1],
        safe: { x: inset, y: inset, width: W - 2 * inset, height: H - 2 * inset },
        noGo: [],
        sources: [...editor.sources, 'image'],
        ink: cover.artwork ? '#ffffff' : (data.palettes[cover.palette]?.ink ?? '#ffffff'),
        shadowDefault: cover.artwork ? 'soft' : 'none',
        // Sizes in pixels of the 1600 × 2560 cover, as before.
        size: { unit: 'px', min: 15, max: 600, step: 1, to: (s) => Math.round(s * H), from: (v) => Math.round((v / H) * 100000) / 100000 },
        fresh: { x: 0.15, y: 0.45, width: 0.7, size: 0.035, customSize: 0.03 },
        ground: <image href={cover.background} x={0} y={0} width={W} height={H} preserveAspectRatio="none" />,
        className: 'w-[min(70%,calc(40dvh*0.625))] lg:w-full lg:max-w-[360px]',
        label: 'Cover',
    };
}

/** The back panel of the print wrap. */
export function backSurface(wrap) {
    const { back, sheet } = wrap;
    const W = back.width;
    const H = back.height;

    return {
        panel: 'back',
        width: W,
        height: H,
        unitY: H,
        yRange: [0, 1],
        safe: back.safe,
        trim: back.trim,
        noGo: back.barcode ? [{ ...back.barcode, label: 'Barcode' }] : [],
        sources: ['title', 'subtitle', 'author', 'series', 'description', 'publisher', 'price', 'category', 'isbn', 'custom', 'image'],
        ink: wrap.ink,
        shadowDefault: 'none',
        size: pointSize(H, 5, 72),
        fresh: { x: 0.15, y: 0.4, width: 0.7, size: (12 * pxPerPt) / H, customSize: (12 * pxPerPt) / H },
        inchesPerPx: 1 / wrap.dpi,
        // The bare wrap, its back panel showing through the view box.
        ground: <image href={wrap.ground} x={0} y={0} width={sheet.width} height={sheet.height} preserveAspectRatio="none" />,
        className: 'w-[min(78%,calc(42dvh*0.66))] lg:w-full lg:max-w-[380px]',
        label: 'Back cover',
    };
}

/** The spine, in its reading frame. */
export function spineSurface(wrap) {
    const { spine, sheet } = wrap;
    const L = spine.width;
    const T = spine.height;
    // The bare wrap turned into the reading frame (CoverText::spinePoint,
    // inverted): top to bottom, u = sheet y and v = spine's right fold − x.
    const turn = spine.direction === 'btt'
        ? `matrix(0 1 -1 0 ${sheet.height} ${-spine.x})`
        : `matrix(0 -1 1 0 0 ${spine.x + T})`;

    return {
        panel: 'spine',
        spine: true,
        width: L,
        height: T,
        unitY: L,
        yRange: [-0.25, 0.25],
        safe: spine.safe,
        noGo: [],
        sources: ['title', 'subtitle', 'author', 'series', 'publisher', 'custom', 'image'],
        ink: wrap.ink,
        shadowDefault: 'none',
        size: pointSize(L, 4, Math.floor((0.05 * L) / pxPerPt)),
        fresh: { x: 0.3, y: 0, width: 0.4, size: (10 * pxPerPt) / L, customSize: (10 * pxPerPt) / L },
        inchesPerPx: 1 / wrap.dpi,
        ground: (
            <image
                href={wrap.ground}
                x={0}
                y={0}
                width={sheet.width}
                height={sheet.height}
                preserveAspectRatio="none"
                transform={turn}
            />
        ),
        // Long and thin: across the whole width, and for a thick spine
        // wider still, scrolled sideways, so it is tall enough to work on.
        // A very thin spine (which carries no type) is capped.
        className: 'shrink-0',
        style: { width: `${Math.min(3200, Math.max(320, (88 * L) / Math.max(T, 1)))}px`, minWidth: '100%' },
        scroll: true,
        label: 'Spine',
    };
}

/** Sizes in points, as print people speak, for the back and the spine. */
function pointSize(unit, min, max) {
    return {
        unit: 'pt',
        min,
        max,
        step: 0.5,
        to: (s) => Math.round(((s * unit) / pxPerPt) * 10) / 10,
        from: (v) => Math.round(((v * pxPerPt) / unit) * 1000000) / 1000000,
    };
}

/** Where a layer sits on a surface, in its pixels (CoverRenderer::place). */
export function placeLayer(layer, text, surface, metrics) {
    if (layer.source === 'image') return { box: imageBox(layer, surface), lines: [] };

    return surface.spine
        ? spineLayout(layer, text, surface.width, surface.height, metrics)
        : layoutLayer(layer, text, surface.width, surface.height, metrics);
}

export function imageBox(layer, surface) {
    const height = layer.height * surface.unitY;
    const top = surface.spine ? surface.height / 2 + layer.y * surface.width - height / 2 : layer.y * surface.height;

    return { x: layer.x * surface.width, y: top, width: layer.width * surface.width, height };
}

/** Do two boxes overlap? */
export function overlaps(a, b) {
    return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}
