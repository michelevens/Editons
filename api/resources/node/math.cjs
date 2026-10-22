// Typesets LaTeX for the exports: SVG for print, MathML for EPUB and HTML.
//
// Reads a JSON array of {latex, display} on stdin and writes a JSON array of
// {svg, width, height, valign, mathml} or {error} in the same order. One
// process per batch, because starting MathJax costs more than typesetting a
// chapter's worth of equations.
//
// MathJax rather than KaTeX here: it produces self-contained SVG paths that a
// PDF renderer can draw without the KaTeX web fonts, and MathML from the same
// parse. The editor previews with KaTeX; both read standard LaTeX, and the
// AllPackages set includes mhchem (\ce{...}) for chemistry.
const { mathjax } = require('mathjax-full/js/mathjax.js');
const { TeX } = require('mathjax-full/js/input/tex.js');
const { SVG } = require('mathjax-full/js/output/svg.js');
const { liteAdaptor } = require('mathjax-full/js/adaptors/liteAdaptor.js');
const { RegisterHTMLHandler } = require('mathjax-full/js/handlers/html.js');
const { AllPackages } = require('mathjax-full/js/input/tex/AllPackages.js');
const { STATE } = require('mathjax-full/js/core/MathItem.js');
const { SerializedMmlVisitor } = require('mathjax-full/js/core/MmlTree/SerializedMmlVisitor.js');
const fs = require('fs');
const path = require('path');

const adaptor = liteAdaptor();
RegisterHTMLHandler(adaptor);

// Letters the TeX fonts lack (é in \text{réel}) come out as <text>, which
// the print renderer redraws as outlines from EB Garamond (GlyphOutlines.php).
// Without a browser MathJax guesses every such letter at 0.6em, which left a
// gap after each é; measured from Garamond's own advance widths, the space
// it leaves is the space the outline fills.
const faces = {};

function face(name) {
    if (faces[name] === undefined) {
        try {
            faces[name] = readAdvances(fs.readFileSync(path.join(__dirname, '..', 'fonts', `EBGaramond-${name}.ttf`)));
        } catch (error) {
            faces[name] = null;
        }
    }

    return faces[name];
}

// Units per em, and each character's advance width, from a TrueType font's
// head, hhea, hmtx and cmap (format 4 or 12) tables.
function readAdvances(font) {
    const tables = {};
    for (let i = 0, n = font.readUInt16BE(4); i < n; i++) {
        const at = 12 + i * 16;
        tables[font.toString('latin1', at, at + 4)] = font.readUInt32BE(at + 8);
    }

    const upem = font.readUInt16BE(tables.head + 18);
    const metrics = font.readUInt16BE(tables.hhea + 34);
    const advance = (glyph) => font.readUInt16BE(tables.hmtx + 4 * Math.min(glyph, metrics - 1));
    const glyphs = new Map();

    const cmap = tables.cmap;
    for (let i = 0, n = font.readUInt16BE(cmap + 2); i < n; i++) {
        const platform = font.readUInt16BE(cmap + 4 + i * 8);
        const sub = cmap + font.readUInt32BE(cmap + 8 + i * 8);
        const format = font.readUInt16BE(sub);

        if (platform === 3 && format === 4) {
            const segments = font.readUInt16BE(sub + 6) / 2;
            const ends = sub + 14, starts = ends + segments * 2 + 2, deltas = starts + segments * 2, offsets = deltas + segments * 2;
            for (let s = 0; s < segments; s++) {
                const end = font.readUInt16BE(ends + s * 2), start = font.readUInt16BE(starts + s * 2);
                const delta = font.readInt16BE(deltas + s * 2), offset = font.readUInt16BE(offsets + s * 2);
                for (let c = start; c <= end && c !== 0xFFFF; c++) {
                    let glyph = offset === 0 ? c + delta : font.readUInt16BE(offsets + s * 2 + offset + (c - start) * 2);
                    glyph = offset !== 0 && glyph !== 0 ? glyph + delta : glyph;
                    if ((glyph & 0xFFFF) !== 0 && !glyphs.has(c)) glyphs.set(c, glyph & 0xFFFF);
                }
            }
        } else if (platform === 3 && format === 12) {
            for (let g = 0, n = font.readUInt32BE(sub + 12); g < n; g++) {
                const at = sub + 16 + g * 12;
                const start = font.readUInt32BE(at), end = font.readUInt32BE(at + 4), first = font.readUInt32BE(at + 8);
                for (let c = start; c <= end; c++) if (!glyphs.has(c)) glyphs.set(c, first + c - start);
            }
        }
    }

    return { upem, width: (code) => (glyphs.has(code) ? advance(glyphs.get(code)) / upem : null) };
}

const guess = adaptor.nodeSize.bind(adaptor);
adaptor.nodeSize = function (node, em = 1, local = null) {
    const italic = adaptor.getAttribute(node, 'font-style') === 'italic';
    const bold = adaptor.getAttribute(node, 'font-weight') === 'bold';
    const font = face(bold ? (italic ? 'SemiBoldItalic' : 'SemiBold') : (italic ? 'Italic' : 'Regular'));
    // The text is drawn at this fraction of the formula's em.
    const scale = parseFloat(adaptor.getAttribute(node, 'font-size') || '1000') / 1000;

    if (!font) return guess(node, em, local);

    let width = 0;
    for (const ch of adaptor.textContent(node)) {
        const w = font.width(ch.codePointAt(0));
        width += w === null ? 0.6 : w * scale;
    }

    return [width, 0.8];
};

const packages = AllPackages.filter((name) => name !== 'bussproofs');
const svgDocument = mathjax.document('', {
    InputJax: new TeX({ packages, formatError: (jax, err) => { throw err; } }),
    // No font cache: every glyph is an inline path, so the SVG stands alone.
    OutputJax: new SVG({ fontCache: 'none' }),
});
const mmlDocument = mathjax.document('', {
    InputJax: new TeX({ packages, formatError: (jax, err) => { throw err; } }),
});
const visitor = new SerializedMmlVisitor();

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => (input += chunk));
process.stdin.on('end', () => {
    const items = JSON.parse(input || '[]');

    const results = items.map(({ latex, display }) => {
        try {
            const container = svgDocument.convert(latex, { display: !!display, em: 16, ex: 8, containerWidth: 1280 });
            const svg = adaptor.firstChild(container);
            const style = adaptor.getAttribute(svg, 'style') || '';
            const valign = (style.match(/vertical-align:\s*(-?[\d.]+)ex/) || [0, '0'])[1];

            const mml = visitor.visitTree(mmlDocument.convert(latex, { display: !!display, end: STATE.CONVERT }));

            return {
                svg: adaptor.outerHTML(svg),
                // Raw: "8.7ex" normally, "100%" for a numbered (	ag) display.
                width: adaptor.getAttribute(svg, 'width') || '',
                height: adaptor.getAttribute(svg, 'height') || '',
                valign: parseFloat(valign) || 0,
                mathml: mml,
            };
        } catch (error) {
            return { error: String(error.message || error) };
        }
    });

    process.stdout.write(JSON.stringify(results));
});
