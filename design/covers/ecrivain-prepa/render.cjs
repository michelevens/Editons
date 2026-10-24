// node render.cjs [--back] [book...] — renders front/<book>.png (1600 × 2560) or, with --back,
// back/<book>.png (1838 × 2775, print back cover with bleed), from specs.json and art/<book>.*.
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '../../../api/node_modules/playwright'));

(async () => {
    const args = process.argv.slice(2);
    // --back renders the print back covers (back.html, 1838 × 2775) into back/.
    const side = args.includes('--back') ? 'back' : 'front';
    const size = side === 'back' ? { width: 1838, height: 2775 } : { width: 1600, height: 2560 };
    const only = args.filter((a) => !a.startsWith('--'));
    const specs = JSON.parse(fs.readFileSync(path.join(__dirname, 'specs.json'), 'utf8'));
    const read = (f) => fs.readFileSync(path.join(__dirname, f), 'utf8');
    const template = read(side === 'back' ? 'back.html' : 'cover.html');
    const out = path.join(__dirname, side);
    fs.mkdirSync(out, { recursive: true });
    const browser = await chromium.launch();
    for (const [book, spec] of Object.entries(specs)) {
        if (only.length && !only.includes(book)) continue;
        // A fresh page per cover: nothing carries over from the last one.
        const page = await browser.newPage({ viewport: size });
        page.on('pageerror', (e) => console.log(book, 'ERROR', e.message));
        const html = template;
        // Loaded from a file URL beside the templates, so supplied art/ images resolve.
        await page.goto('file:///' + path.join(__dirname, 'blank.html').split(path.sep).join('/'));
        await page.setContent(html.replace('<script>', () => `<script>window.COVER = ${JSON.stringify(spec)};`), { waitUntil: 'networkidle' });
        // The page fits the title only once the real fonts are in.
        await page.waitForFunction(() => window.FITTED !== undefined);
        await page.screenshot({ path: path.join(out, `${book}.png`) });
        const fitted = await page.evaluate(() => window.FITTED);
        const over = await page.evaluate(() => window.OVERFLOW || []);
        if (over.length) console.log(book, 'OVERFLOW', over);
        await page.close();
        console.log('rendered', side, book, side === 'front' ? `title ${fitted}px` : `description ${fitted}px`);
    }
    await browser.close();
})();
