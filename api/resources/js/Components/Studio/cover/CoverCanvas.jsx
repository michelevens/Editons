import { layoutLayer, faceKey } from './coverText';
import { cssFamily, ready } from './coverFonts';

/**
 * The cover as SVG: the background picture, then each visible layer in
 * order — panel, shadow, type — every glyph at the x coordinate coverText
 * computed, which is the x the server's GD render uses. The browser is
 * told where each glyph goes and does no wrapping, kerning or spacing of
 * its own, so this is the JPEG, live.
 *
 * Pure: the editor draws its interaction layer over this, and the
 * thumbnail preview is this again, small.
 */
export default function CoverCanvas({ editor, items, texts, metrics, background, className, label, children, svgRef, ...rest }) {
    const { width, height } = editor;

    return (
        <svg
            ref={svgRef}
            viewBox={`0 0 ${width} ${height}`}
            className={className}
            role="img"
            aria-label={label}
            {...rest}
        >
            <image href={background} x={0} y={0} width={width} height={height} preserveAspectRatio="none" />

            {items.map((layer) =>
                layer.visible && ready(metrics, layer) ? (
                    <Layer
                        key={layer.id}
                        layer={layer}
                        set={layoutLayer(layer, textOf(layer, texts), width, height, metrics)}
                        editor={editor}
                    />
                ) : null,
            )}

            {children}
        </svg>
    );
}

export function textOf(layer, texts) {
    return layer.source === 'custom' ? layer.text ?? '' : texts?.[layer.source] ?? '';
}

function Layer({ layer, set, editor }) {
    const panel = layer.background;
    const shadow = editor.shadows[layer.shadow];
    const pad = panel ? panel.padding * editor.width : 0;

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
                    dx={shadow.dx * editor.width}
                    dy={shadow.dy * editor.height}
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
