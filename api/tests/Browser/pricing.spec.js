import { expect, test } from '@playwright/test';
import { expectRendered, ignoring, signIn, watchForErrors } from './helpers';

/**
 * Storefront pricing (ADR — per-format pricing, sales, author codes,
 * bundles and pre-orders): an author prices two books per format, puts a
 * sale on one, makes them pre-orders (so no export is needed), makes a
 * discount code and a bundle on the Sales page; a signed-out reader sees the
 * formats with the struck-through price and the bundle page. Each screen at
 * 1440 and 390 px: no console errors, nothing wider than the screen.
 *
 * Needs a server with direct sales on over the FAKE gateway
 * (COMMERCE_ENABLED=true, COMMERCE_GATEWAY=fake). Nothing talks to Stripe.
 * SHOTS, when set, is a directory the screenshots are written to.
 */

const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png`, fullPage: true }) : null);

async function noSideScroll(page, where) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `${where}: page scrolls sideways`).toBeLessThanOrEqual(1);
}

function xsrf(cookies) {
    return decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

async function bookIds(page) {
    await page.goto('/books');
    const hrefs = await page.locator('a[href*="/edit"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
    const ids = [...new Set(hrefs.map((h) => h?.match(/books\/(\d+)/)?.[1]).filter(Boolean))];
    expect(ids.length, 'the demo shelf has at least two books').toBeGreaterThanOrEqual(2);

    return ids.slice(0, 2);
}

test('an author prices formats, runs a sale, makes a code and a bundle; a reader sees them', async ({ page, browser }, testInfo) => {
    test.setTimeout(300_000);
    const errors = watchForErrors(page);
    await signIn(page);
    const [first, second] = await bookIds(page);
    const headers = { 'X-XSRF-TOKEN': xsrf(await page.context().cookies()), Accept: 'application/json' };

    const release = new Date(Date.now() + 30 * 86400_000).toISOString().slice(0, 10);
    const start = new Date(Date.now() - 86400_000).toISOString().slice(0, 16);
    const end = new Date(Date.now() + 7 * 86400_000).toISOString().slice(0, 16);

    // The second book through the API: a pre-order at 10.00.
    const second_ = await page.request.put(`/books/${second}/product`, {
        headers,
        data: { price: '10.00', currency: 'usd', formats: ['epub', 'pdf'], license: 'personal', published: true, release_on: release },
    });
    expect(second_.status()).toBe(200);

    // The first through the form: per-format prices, a sale, a release date.
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/books/${first}/edit?pane=distribution`);
        await expect(page.locator('[data-a="store:pricing"]')).toBeVisible();
        await noSideScroll(page, `product form ${width}`);
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.fill('[data-a="store:price"]', '12.00');
    await page.selectOption('[data-a="store:currency"]', 'usd');
    await page.check('[data-a="store:format:epub"]');
    await page.check('[data-a="store:format:pdf"]');
    await page.check('[data-a="store:per-format"]');
    await page.fill('[data-a="store:format-price:epub"]', '4.99');
    await page.fill('[data-a="store:format-price:pdf"]', '6.99');
    await page.click('[data-a="store:add-sale"]');
    await page.selectOption('[data-a="store:sale-offer"]', 'all');
    await page.fill('[data-a="store:sale-price"]', '8.00');
    await page.fill('[data-a="store:sale-starts"]', start);
    await page.fill('[data-a="store:sale-ends"]', end);
    await page.fill('[data-a="store:release-on"]', release);
    await page.click('[data-a="store:publish"]');
    const link = page.locator('[data-a="store:link"]');
    await expect(link).toBeVisible();
    const productUrl = new URL(await link.getAttribute('href')).pathname;
    await page.locator('[data-a="store:pricing"]').scrollIntoViewIfNeeded();
    await shot(page, 'product-form-1440');

    // Sales page: a code for the first book, and a bundle of both.
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/sales');
        await expect(page.locator('[data-a="tools:codes"]')).toBeVisible();
        await expect(page.locator('[data-a="tools:bundles"]')).toBeVisible();
        await noSideScroll(page, `sales ${width}`);
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    const code = `E2E${Date.now().toString(36).toUpperCase()}`;
    await page.fill('[data-a="tools:code-name"]', code);
    await page.fill('[data-a="tools:code-percent"]', '20');
    await page.click('[data-a="tools:code-create"]');
    await expect(page.locator('[data-a="tools:code"]').filter({ hasText: code })).toBeVisible();

    await page.fill('[data-a="tools:bundle-title"]', 'E2E series bundle');
    await page.fill('[data-a="tools:bundle-price"]', '15.00');
    const products = await page.request.get('/sales/bundles', { headers });
    const ids = (await products.json()).products.filter((p) => p.published).map((p) => p.id).slice(0, 2);
    for (const id of ids) {
        await page.check(`[data-a="tools:bundle-product:${id}"]`);
    }
    await page.click('[data-a="tools:bundle-publish"]');
    const bundleRow = page.locator('[data-a="tools:bundle"]').filter({ hasText: 'E2E series bundle' }).first();
    await expect(bundleRow).toBeVisible();
    await shot(page, 'sales-tools-1440');
    await page.setViewportSize({ width: 390, height: 900 });
    await shot(page, 'sales-tools-390');
    const bundleUrl = new URL(await bundleRow.locator('a').first().getAttribute('href')).pathname;
    expect(ignoring(errors)).toEqual([]);

    // A reader with no account.
    const reader = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await reader.newPage();
    const readerErrors = watchForErrors(shop);

    for (const width of [1440, 390]) {
        await shop.setViewportSize({ width, height: 900 });
        await shop.goto(productUrl);
        await expectRendered(shop);
        await expect(shop.locator('[data-a="store:offers"]')).toBeVisible();
        await expect(shop.locator('[data-a="store:offer-price:epub"]')).toHaveText(/\$4\.99/);
        await expect(shop.locator('[data-a="store:offer-price:all"]')).toHaveText(/\$8\.00/);
        await expect(shop.locator('[data-a="store:list-price"]').first()).toHaveText(/\$12\.00/);
        await expect(shop.locator('[data-a="store:preorder"]')).toBeVisible();
        await noSideScroll(shop, `book page ${width}`);
        await shot(shop, `book-page-${width}`);

        await shop.goto(bundleUrl);
        await expectRendered(shop);
        await expect(shop.locator('[data-a="bundle:price"]')).toHaveText(/\$15\.00/);
        await noSideScroll(shop, `bundle page ${width}`);
        await shot(shop, `bundle-page-${width}`);
    }

    // The EPUB alone, with the author's code: checkout opens at 4.99 − 20 %.
    await shop.setViewportSize({ width: 1440, height: 900 });
    await shop.goto(productUrl);
    await shop.check('[data-a="store:offer:epub"]');
    await shop.fill('[data-a="store:code"]', code);
    await shop.click('[data-a="store:buy"]');
    await shop.waitForURL(/\/store\/checkout\/test\/cs_fake_/);
    await expect(shop.locator('[data-a="store:order"]')).toContainText('$3.99');

    expect(ignoring(readerErrors, '409 (Conflict)')).toEqual([]);
    await reader.close();
});
