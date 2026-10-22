import { expect, test } from '@playwright/test';
import { expectRendered, firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Create a product → its public storefront page → Buy → the checkout
 * redirect.
 *
 * Runs against a server with direct sales switched on over the FAKE gateway
 * (COMMERCE_ENABLED=true, COMMERCE_GATEWAY=fake) and QUEUE_CONNECTION=sync so
 * the EPUB export finishes inside the request. The fake gateway's session
 * points at the local test checkout page instead of Stripe: that redirect is
 * the stub. Nothing here talks to Stripe.
 */
test('an author puts a book on sale and a signed-out reader reaches checkout', async ({ page, browser }, testInfo) => {
    test.setTimeout(300_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);

    // Buyers download the latest export, so there must be one.
    const xsrf = decodeURIComponent(
        (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '',
    );
    const exported = await page.request.post(`/books/${id}/exports`, {
        data: { format: 'epub' },
        headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
    });
    expect([200, 202]).toContain(exported.status());

    await page.goto(`/books/${id}/edit?pane=distribution`);
    const editor = page.locator('[data-a="store:product"]');
    await expect(editor).toBeVisible();

    await page.fill('[data-a="store:price"]', '7.50');
    await page.selectOption('[data-a="store:currency"]', 'usd');
    // EPUB only: PDF may not have been exported on this server.
    await page.check('[data-a="store:format:epub"]');
    await page.uncheck('[data-a="store:format:pdf"]');
    await page.fill('[data-a="store:description"]', 'A book about <b>practice</b>.\nSecond line.');
    await page.click('[data-a="store:publish"]');

    const link = page.locator('[data-a="store:link"]');
    await expect(link).toBeVisible();
    const productUrl = await link.getAttribute('href');
    expect(productUrl).toMatch(/\/store\/[a-z0-9-]+$/);
    expect(errors).toEqual([]);

    // A reader with no account and no session.
    const reader = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await reader.newPage();
    const readerErrors = watchForErrors(shop);

    await shop.goto(new URL(productUrl).pathname);
    await expectRendered(shop);
    await expect(shop.locator('[data-a="store:price"]')).toHaveText(/\$7\.50/);
    // Tags were stripped and nothing was rendered as HTML.
    await expect(shop.locator('[data-a="store:description"]')).toHaveText(/A book about practice\.\s+Second line\./);
    await expect(shop.locator('[data-a="store:description"] b')).toHaveCount(0);

    await shop.click('[data-a="store:buy"]');
    await shop.waitForURL(/\/store\/checkout\/test\/cs_fake_/);
    await expect(shop.locator('[data-a="store:test-checkout"]')).toBeVisible();

    // Completing the stubbed payment runs the same fulfilment as the webhook.
    await shop.fill('#test-email', 'reader@example.com');
    await shop.click('[data-a="store:test-pay"]');
    await shop.waitForURL(/\/store\/checkout\/success\?session_id=cs_fake_/);
    await expect(shop.locator('[data-a="store:thanks"]')).toContainText('your book is ready');
    await expect(shop.locator('[data-a="store:download:epub"]')).toBeVisible();

    expect(readerErrors).toEqual([]);
    await reader.close();

    // The author's royalties pane now has a real order in it.
    await page.goto(`/books/${id}/edit?pane=royalties`);
    await expect(page.locator('[data-a="sales:totals:usd"]')).toContainText('$7.50');
});
