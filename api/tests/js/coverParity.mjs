// Runs coverText.js on cases from stdin and prints the layouts, for
// tests/Feature/CoverTextParityTest.php to compare with the PHP.
import { pathToFileURL } from 'node:url';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const { layoutLayer, spineLayout, spinePoint } = await import(
    pathToFileURL(resolve(here, '../../resources/js/Components/Studio/cover/coverText.js')).href
);

let input = '';
for await (const chunk of process.stdin) input += chunk;

const { cases, metrics } = JSON.parse(input);

// A spine case is laid out in the spine's reading frame and every glyph's
// origin mapped onto the sheet, as the full-wrap view draws it.
const run = (c) => {
    if (!c.spine) return layoutLayer(c.layer, c.text, c.width, c.height, metrics);

    const s = c.spine;
    const set = spineLayout(c.layer, c.text, s.length, s.thickness, metrics);

    return {
        ...set,
        sheet: set.lines.map((line) =>
            line.glyphs.map((g) => spinePoint(g.x, line.baseline, s.direction, s.spineX, s.thickness, s.length)),
        ),
    };
};

process.stdout.write(JSON.stringify(cases.map(run)));
