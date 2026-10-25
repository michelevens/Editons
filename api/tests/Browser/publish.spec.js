import { expect, test } from '@playwright/test';
import { flagshipBookId, signIn, watchForErrors } from './helpers.js';

/**
 * Publish in three steps, on a phone and on a desktop: the check with its
 * single verdict and Fix links, choosing Amazon KDP, the KDP guided page
 * with a Copy button per field, and marking it submitted with an ASIN —
 * which then shows on the channel card and the book's Overview.
 *
 * Nothing here talks to Amazon: the "Open KDP" link is only checked for
 * its address.
 */
test.describe.configure({ timeout: 180_000 });

async function threeSteps(page, { asin }) {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await flagshipBookId(page);

    await page.goto(`/books/${id}/edit?pane=publish`);
    const flow = page.locator('[data-a="publish-flow"]');
    await expect(flow).toBeVisible({ timeout: 30_000 });

    // Nothing wider than the screen.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    // Step 1: one verdict, with a reason for each problem.
    const summary = page.locator('[data-a="publish-flow:summary"]');
    await expect(summary).toBeVisible();
    expect(['green', 'amber', 'red']).toContain(await summary.getAttribute('data-status'));

    // Step 2, by keyboard: choose Amazon KDP (ebook).
    await page.locator('[data-a="publish-flow:next:channels"]').focus();
    await page.keyboard.press('Enter');
    const kdp = page.locator('[data-a="publish-flow:choose:kdp_ebook"]');
    await expect(kdp).toBeVisible();
    await expect(page.locator('[data-a="publish-flow:card:kdp_ebook"]')).toContainText('about 10 minutes');
    await expect(page.locator('[data-a="publish-flow:card:apple_books"]')).toContainText('coming soon');
    await expect(page.locator('[data-a="publish-flow:choose:apple_books"]')).toHaveCount(0);

    if ((await kdp.getAttribute('aria-checked')) !== 'true') {
        await kdp.click();
        await expect(kdp).toHaveAttribute('aria-checked', 'true');
    }

    // Step 3: the KDP guided page, its three KDP pages, a Copy per value.
    await page.click('[data-a="publish-flow:next:publish"]');
    const guide = page.locator('[data-a="publish:kdp:ebook"]');
    await expect(guide).toBeVisible();
    await expect(guide.locator('[data-a="kdp:field:title"]')).toContainText('Building an Independent Practice');
    await expect(guide.locator('[data-a="kdp:field:keywords"]')).toBeVisible();
    await expect(guide.locator('[data-a="kdp:field:title"] button', { hasText: 'Copy' })).toBeVisible();
    await expect(page.locator('[data-a="publish-flow:open-kdp:kdp_ebook"]')).toHaveAttribute('href', 'https://kdp.amazon.com/');

    // Edit and save a keyword: kept, within KDP's box.
    await guide.locator('[data-a="kdp:edit:keywords"]').click();
    const first = page.locator('[data-a="kdp:keywords:0"]');
    await first.fill('independent practice guide');
    await expect(first).toHaveAttribute('maxlength', '50');
    await page.click('[data-a="kdp:save"]');
    await expect(guide.locator('[data-a="kdp:field:keywords"]')).toContainText('independent practice guide');

    // "I've submitted it": a bad ASIN is refused, a good one recorded.
    const form = page.locator('[data-a="publish-flow:submitted:kdp_ebook"]');
    await form.scrollIntoViewIfNeeded();
    await page.fill('[data-a="publish-flow:asin:kdp_ebook"]', 'NOTANASIN');
    await page.click('[data-a="publish-flow:mark:kdp_ebook"]');
    await expect(page.locator('[data-a="publish-flow:card-status:kdp_ebook"]')).toHaveCount(0);

    await page.fill('[data-a="publish-flow:asin:kdp_ebook"]', asin);
    await page.click('[data-a="publish-flow:mark:kdp_ebook"]');
    await expect(form).toContainText(`Submitted · ${asin}`);

    // Shown on the channel card and on the book's Overview.
    await page.click('[data-a="publish-flow:step:channels"]');
    await expect(page.locator('[data-a="publish-flow:card-status:kdp_ebook"]')).toContainText(asin);

    await page.goto(`/books/${id}/overview`);
    await expect(page.locator('[data-a="overview:channel:amazon_kdp"]')).toContainText(asin);

    // The printable checklist renders the same guided page.
    await page.goto(`/publish/kdp-checklist?books[]=${id}`);
    await expect(page.locator(`[data-a="kdp:book:${id}"] [data-a="publish:kdp:ebook"]`)).toBeVisible();

    expect(errors.filter((e) => !/422|Unprocessable/.test(e))).toEqual([]);
}

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('checks, chooses KDP and marks it submitted', async ({ page }) => {
        await threeSteps(page, { asin: 'B0PHONE123' });
    });
});

test('on a desktop: checks, chooses KDP and marks it submitted', async ({ page }) => {
    await threeSteps(page, { asin: 'B0DESKTOP1' });
});
