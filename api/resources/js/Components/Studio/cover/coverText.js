/**
 * Where every character of a cover text layer goes.
 *
 * A line-for-line port of App\Covers\CoverText: the server draws the cover
 * JPEG with that, Cover Studio's live canvas with this, from the same font
 * metrics (each face's hmtx advances, fetched from /cover-fonts/{face}/metrics)
 * summed in the same order, so both break at the same words and put every
 * glyph at the same x. tests/Feature/CoverTextParityTest.php runs this file
 * under node against the PHP. Change one, change both.
 */

const EPSILON = 0.01;

/**
 * @param layer   a layer as CoverLayers stores it
 * @param text    what it shows
 * @param width   canvas width, px
 * @param height  canvas height, px
 * @param metrics { [faceKey]: { upm, asc, desc, adv: { [codePoint]: units } } }
 */
export function layoutLayer(layer, text, width, height, metrics) {
    const face = faceKey(layer.font, layer.weight, layer.italic);
    const m = metrics[face];
    const fallback = metrics.fallback;

    const size = layer.size * height;
    const spacing = layer.tracking * size;
    const boxX = layer.x * width;
    const boxY = layer.y * height;
    const boxWidth = layer.width * width;

    const advance = (char) => {
        const code = char.codePointAt(0);

        if (m.adv[code] !== undefined) {
            return (m.adv[code] / m.upm) * size;
        }

        return ((fallback.adv[code] ?? fallback.upm / 2) / fallback.upm) * size;
    };

    const measure = (chars) => {
        let sum = 0;

        for (let i = 0; i < chars.length; i++) {
            sum += advance(chars[i]);

            if (i > 0) {
                sum += spacing;
            }
        }

        return sum;
    };

    const lines = [];

    for (const paragraph of paragraphs(prepare(text, layer.uppercase))) {
        for (const line of wrap(paragraph, measure, boxWidth)) {
            lines.push(line);
        }
    }

    const ascent = (m.asc / m.upm) * size;
    const descent = (m.desc / m.upm) * size;
    const step = layer.leading * size;

    const placed = lines.map((chars, i) => {
        const lineWidth = measure(chars);
        const start =
            layer.align === 'left'
                ? boxX
                : layer.align === 'right'
                  ? boxX + boxWidth - lineWidth
                  : boxX + (boxWidth - lineWidth) / 2;

        const glyphs = [];
        let cursor = start;

        for (const char of chars) {
            glyphs.push({
                char,
                x: cursor,
                face: m.adv[char.codePointAt(0)] !== undefined ? face : 'fallback',
            });
            cursor += advance(char) + spacing;
        }

        return {
            text: chars.join(''),
            baseline: boxY + ascent + i * step,
            x: start,
            width: lineWidth,
            glyphs,
        };
    });

    const textHeight = lines.length === 0 ? 0 : ascent + descent + (lines.length - 1) * step;

    return {
        box: {
            x: boxX,
            y: boxY,
            width: boxWidth,
            height: Math.max(textHeight, (layer.height ?? 0) * height),
        },
        size,
        face,
        lines: placed,
    };
}

export function faceKey(family, weight, italic) {
    return `${family}.${weight}${italic ? '-italic' : ''}`;
}

/** NFC, so "é" is one character on both ends; capitals if asked. */
export function prepare(text, uppercase) {
    const flat = String(text ?? '')
        .replace(/\r\n|\r/g, '\n')
        .replace(/\t/g, ' ')
        .normalize('NFC');

    return uppercase ? flat.toUpperCase() : flat;
}

function paragraphs(text) {
    // PHP's trim() set, not JavaScript's wider one.
    return /^[ \t\n\r\0\x0B]*$/.test(text) ? [] : text.split('\n');
}

/** Greedy: as many words as fit, a too-wide word broken between characters. */
function wrap(paragraph, measure, width) {
    const words = paragraph.split(' ').filter((w) => w !== '');

    if (words.length === 0) {
        return [[]];
    }

    const lines = [];
    let line = null;

    for (const word of words) {
        const chars = Array.from(word);

        if (measure(chars) > width + EPSILON) {
            if (line !== null) {
                lines.push(line);
                line = null;
            }

            let chunk = [];

            for (const char of chars) {
                if (chunk.length > 0 && measure([...chunk, char]) > width + EPSILON) {
                    lines.push(chunk);
                    chunk = [];
                }

                chunk.push(char);
            }

            line = chunk;

            continue;
        }

        const candidate = line === null ? chars : [...line, ' ', ...chars];

        if (line === null || measure(candidate) <= width + EPSILON) {
            line = candidate;
        } else {
            lines.push(line);
            line = chars;
        }
    }

    lines.push(line);

    return lines;
}
