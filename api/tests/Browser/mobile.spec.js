import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * The phone: a tab bar that follows where the author is.
 *
 * Below 1024px every signed-in page ends in a five-tab bar. Four tabs change
 * with the area (library, writing, review, publishing, selling); More is
 * always last and opens a sheet with every screen of the book.
 */
test.describe.configure({ timeout: 240_000 });
test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

const tabs = (page) =>
    page.$$eval('[data-a="mobile:tabs"] button', (buttons) =>
        buttons.map((b) => b.getAttribute('data-a').replace('mobile:', '')),
    );

const current = (page) =>
    page.$eval('[data-a="mobile:tabs"] [aria-current="page"]', (b) => b.getAttribute('data-a')).catch(() => null);

test('the tabs follow the area of the studio', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);

    await page.goto('/books');
    expect(await tabs(page)).toEqual(['books', 'continue', 'store-sales', 'billing', 'more']);
    expect(await current(page)).toBe('mobile:books');

    for (const [pane, expected, on] of [
        ['manuscript', ['overview', 'manuscript', 'ai', 'research', 'more'], 'manuscript'],
        ['comments', ['manuscript', 'ai', 'comments', 'workflow', 'more'], 'comments'],
        ['cover', ['manuscript', 'layout', 'cover', 'export', 'more'], 'cover'],
        ['launch', ['manuscript', 'distribution', 'launch', 'analytics', 'more'], 'launch'],
    ]) {
        await page.goto(`/books/${id}/edit?pane=${pane}`);
        await expect(page.locator('[data-a="mobile:tabs"]')).toBeVisible({ timeout: 25_000 });
        expect(await tabs(page), pane).toEqual(expected);
        expect(await current(page), pane).toBe(`mobile:${on}`);
    }

    expect(errors).toEqual([]);
});

test('a tab switches the screen, and the bar switches with it', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);

    await page.goto(`/books/${id}/edit?pane=cover`);
    await page.click('[data-a="mobile:export"]');
    await expect(page.locator('[data-a="mobile:export"]')).toHaveAttribute('aria-current', 'page');

    await page.click('[data-a="mobile:manuscript"]');
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    expect(await tabs(page)).toContain('research');

    expect(errors).toEqual([]);
});

test('Write while writing opens the chapters; AI opens this chapter\'s findings', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    await page.click('[data-a="mobile:manuscript"]');
    await expect(page.getByRole('dialog', { name: 'Chapters' })).toBeVisible();
    await page.keyboard.press('Escape');

    await page.click('[data-a="mobile:ai"]');
    await expect(page.getByRole('dialog', { name: 'AI Editor' })).toBeVisible();

    expect(errors).toEqual([]);
});

test('More holds every screen and opens the one tapped', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript')).toBeVisible({ timeout: 25_000 });

    await page.click('[data-a="mobile:more"]');
    const sheet = page.getByRole('dialog', { name: 'Menu' });
    await expect(sheet).toBeVisible();

    for (const key of ['overview', 'research', 'comments', 'workflow', 'layout', 'cover', 'export', 'rights', 'distribution', 'launch', 'marketing']) {
        await expect(sheet.locator(`[data-a="sheet:${key}"]`)).toBeVisible();
    }

    await sheet.locator('[data-a="sheet:rights"]').click();
    await expect(sheet).toBeHidden();
    expect(await current(page)).toBeNull(); // ISBN & Rights has no tab of its own…
    expect(await tabs(page)).toContain('export'); // …but it brings the publishing tabs.

    expect(errors).toEqual([]);
});

test('the formatting toolbar is one row, and nothing scrolls sideways', async ({ page }) => {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    const toolbar = page.getByRole('toolbar', { name: 'Formatting' });
    expect((await toolbar.boundingBox()).height).toBeLessThanOrEqual(53);

    for (const pane of ['manuscript', 'ai', 'analytics', 'workflow', 'layout', 'references']) {
        await page.goto(`/books/${id}/edit?pane=${pane}`);
        await page.waitForLoadState('networkidle');
        const width = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(width, pane).toBeLessThanOrEqual(390);
    }
});
