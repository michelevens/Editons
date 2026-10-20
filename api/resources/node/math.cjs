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

const adaptor = liteAdaptor();
RegisterHTMLHandler(adaptor);

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
