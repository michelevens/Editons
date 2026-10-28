import { faceKey } from './coverText';
import { cssFamily, ready } from './coverFonts';
import { placeLayer } from './surfaces';

/**
 * A cover panel as SVG: its ground (the server's render of the picture or
 * colour), then each visible layer in order — a picture, or a text layer's
 * panel, shadow and type, every glyph at the x coordinate coverText
 * computed, which is the x the server's GD render uses. The browser is
 * told where each glyph goes and does no wrapping, kerning or spacing of
 * its own, so this is the JPEG (or the print wrap), live.
 *
 * The surface (surfaces.jsx) says which panel: the front, the back, or the
 * spine in its reading frame.
 *
 * Pure: the editor draws its interaction layer over this, and the
 * thumbnail preview is this again, small.
 */
export default function CoverCanvas({ surface, items, texts, metrics, images, className, label, children, svgRef, style, ...rest }) {
    const { width, height } = surface;

    return (
        <svg
            ref={svgRef}
            viewBox={`0 0 ${width} ${height}`}
            className={className}
            style={style}
            role="img"
            aria-label={label}
            {...rest}
        >
            {surface.ground}

            {items.map((layer) => {
                if (!layer.visible) return null;

                if (layer.source === 'image') {
                    return <Picture key={layer.id} layer={layer} surface={surface} image={images?.find((i) => i.id === layer.image)} />;
                }

                return ready(metrics, layer) ? (
                    <Layer key={layer.id} layer={layer} set={placeLayer(layer, textOf(layer, texts), surface, metrics)} surface={surface} />
                ) : null;
            })}

            {children}
        </svg>
    );
}

export function textOf(layer, texts) {
    return layer.source === 'custom' ? layer.text ?? '' : texts?.[layer.source] ?? '';
}

/** A picture in its box, cropped as the server crops it (CoverRenderer::picture). */
function Picture({ layer, surface, image }) {
    if (!image) return null;

    const box = placeLayer(layer, '', surface).box;
    const crop = layer.crop ?? { x: 0, y: 0, width: 1, height: 1 };

    return (
        <svg
            data-layer={layer.id}
            x={box.x}
            y={box.y}
            width={box.width}
            height={box.height}
            viewBox={`${crop.x * image.width} ${crop.y * image.height} ${crop.width * image.width} ${crop.height * image.height}`}
            preserveAspectRatio="none"
            opacity={layer.opacity}
            overflow="hidden"
        >
            <image href={image.url} x={0} y={0} width={image.width} height={image.height} preserveAspectRatio="none" />
        </svg>
    );
}

function Layer({ layer, set, surface }) {
    const panel = layer.background;
    const shadow = surface.shadows?.[layer.shadow];
    const pad = panel ? panel.padding * surface.width : 0;
    // Shadows are fractions of the cover; on the spine, of its length.
    const unitY = surface.spine ? surface.width : surface.height;

    return (
        <g data-layer={layer.id}>
            {panel && (
                <rect
                    x={Math.round(set.box.x - pad)}
                    y={Math.round(set.box.y - pad)}
                    width={Math.round(set.box.x + set.box.width + pad) - Math.round(set.box.x - pad)}
                    height={Math.round(set.box.y + set.box.height + pad) - Math.round(set.box.y - pad)}
                    fill={panel.color}
                    fillOpacity={panel.opacity}
                />
            )}

            {shadow && (
                <Glyphs
                    lines={set.lines}
                    size={set.size}
                    fill="#000000"
                    opacity={shadow.opacity * layer.opacity}
                    dx={shadow.dx * surface.width}
                    dy={shadow.dy * unitY}
                />
            )}

            <Glyphs lines={set.lines} size={set.size} fill={layer.color} opacity={layer.opacity} dx={0} dy={0} />
        </g>
    );
}

function Glyphs({ lines, size, fill, opacity, dx, dy }) {
    return (
        <text
            fontSize={size}
            fill={fill}
            fillOpacity={opacity}
            style={{ fontKerning: 'none', fontVariantLigatures: 'none', whiteSpace: 'pre' }}
            aria-hidden="true"
        >
            {lines.flatMap((line, i) =>
                line.glyphs.map((glyph, j) =>
                    glyph.char.trim() === '' ? null : (
                        <tspan
                            key={`${i}-${j}`}
                            // Rounded as GD rounds its pen position.
                            x={Math.round(glyph.x + dx)}
                            y={Math.round(line.baseline + dy)}
                            fontFamily={`${cssFamily(glyph.face)}, ${cssFamily('fallback')}`}
                        >
                            {glyph.char}
                        </tspan>
                    ),
                ),
            )}
        </text>
    );
}

export { faceKey };
