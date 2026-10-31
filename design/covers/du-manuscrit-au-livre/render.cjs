// node render.cjs: renders front.png (1600 × 2560, digital front cover) and back.png
// (1838 × 2775, 6 × 9 in print back panel with bleed) from front.html and back.html.
const path = require('path');
const { chromium } = require(path.join(__dirname, '../../../api/node_modules/playwright'));

(async () => {
    const browser = await chromium.launch();
    for (const [side, size] of [['front', { width: 1600, height: 2560 }], ['back', { width: 1838, height: 2775 }]]) {
        const page = await browser.newPage({ viewport: size });
        page.on('pageerror', (e) => console.log(side, 'ERROR', e.message));
        await page.goto('file:///' + path.join(__dirname, `${side}.html`).split(path.sep).join('/'), { waitUntil: 'networkidle' });
        await page.waitForFunction(() => window.READY === true);
        // Anything that runs past its box or the page is a layout fault, not a design.
        const over = await page.evaluate(() => [...document.querySelectorAll('body *')]
            .filter((el) => el.scrollHeight > el.clientHeight + 1 || el.getBoundingClientRect().bottom > innerHeight)
            .map((el) => el.className || el.tagName));
        if (over.length) console.log(side, 'OVERFLOW', over);
        await page.screenshot({ path: path.join(__dirname, `${side}.png`) });
        await page.close();
        console.log('rendered', side);
    }
    await browser.close();
})();
