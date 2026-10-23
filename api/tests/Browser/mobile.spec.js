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
    expect(await tabs(page)).toEqual(['books', 'continue', 'store-sales', 'inbox', 'more']);
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

    for (const key of ['overview', 'research', 'comments', 'workflow', 'layout', 'cover', 'export', 'preview', 'rights', 'distribution', 'launch', 'marketing']) {
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

// --- Writing on a phone ----------------------------------------------------

test('while writing, the formatting bar moves down to the keyboard and the tab bar steps aside', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const prose = page.locator('.manuscript .ProseMirror');
    await expect(prose).toBeVisible({ timeout: 25_000 });
    await expect(page.locator('[data-a="mobile:tabs"]')).toBeVisible();

    await prose.tap();
    const dock = page.locator('[data-a="editor:toolbar-dock"]');
    await expect(dock).toHaveAttribute('data-docked', 'true');
    await expect(page.locator('[data-a="mobile:tabs"]')).toBeHidden();

    // Below the text, not above it.
    const bar = await dock.boundingBox();
    const text = await page.locator('.manuscript .ProseMirror').boundingBox();
    expect(bar.y).toBeGreaterThan(text.y);

    // Done puts the keyboard away and everything back.
    await page.locator('[data-a="toolbar:done"]').tap();
    await expect(dock).not.toHaveAttribute('data-docked', 'true');
    await expect(page.locator('[data-a="mobile:tabs"]')).toBeVisible();

    expect(errors).toEqual([]);
});

test('offline, the chapter says so and saves when the connection returns', async ({ page, context }) => {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const prose = page.locator('.manuscript .ProseMirror');
    await expect(prose).toBeVisible({ timeout: 25_000 });
    await prose.click();
    await page.keyboard.press('Control+End');

    await context.setOffline(true);
    await page.keyboard.type(' Written offline.');
    await expect(page.getByRole('status').filter({ hasText: 'Offline' })).toBeVisible({ timeout: 15_000 });

    const saved = page.waitForResponse((r) => /\/sections\/\d+\/content$/.test(r.url()) && r.ok());
    await context.setOffline(false);
    await saved;
    await expect(page.getByRole('status').filter({ hasText: 'Saved' })).toBeVisible({ timeout: 15_000 });
});

test('typewriter scrolling is a remembered choice', async ({ page }) => {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    const button = page.locator('[data-a="editor:typewriter"]');
    const was = await button.getAttribute('aria-pressed');
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', was === 'true' ? 'false' : 'true');

    await page.reload();
    await expect(page.locator('[data-a="editor:typewriter"]')).toHaveAttribute('aria-pressed', was === 'true' ? 'false' : 'true');

    // Put it back for the next test.
    await page.locator('[data-a="editor:typewriter"]').click();
});

test('findings one at a time: apply, then undo takes the fix back out', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const prose = page.locator('.manuscript .ProseMirror');
    await expect(prose).toBeVisible({ timeout: 25_000 });

    const marker = `deck${Date.now().toString(36)}`;
    await prose.click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(` The the ${marker} word.`);
    await page.waitForTimeout(2000);
    await page.locator('[data-a="toolbar:done"]').click().catch(() => {});

    await page.click('[data-a="mobile:ai"]');
    const drawer = page.getByRole('dialog', { name: 'AI Editor' });
    await expect(drawer).toBeVisible();

    const reviewed = page.waitForResponse(
        (r) => /\/sections\/\d+\/review$/.test(r.url()) && r.request().method() === 'POST',
    );
    await drawer.locator('[data-a="ai:run:copy"]').click();
    const { findings } = await (await reviewed).json();
    const finding = findings.find((f) => f.title === 'Repeated word' && f.status === 'open' && f.suggestion);
    expect(finding, 'the copy editor flagged the doubled word').toBeTruthy();

    await drawer.locator('[data-a="ai:review-one-by-one"]').click();
    const card = drawer.locator(`[data-a="review:card:${finding.id}"]`);

    // Step through to this run's card.
    for (let i = 0; i < 60 && !(await card.isVisible()); i++) {
        await drawer.locator('[data-a="review:next"]').click();
    }
    await expect(card).toBeVisible();

    await drawer.locator('[data-a="review:apply"]').click();
    await expect(drawer.locator('[data-a="review:undo-bar"]')).toContainText('Fix applied');
    await expect(card).toBeHidden();

    const reopened = page.waitForResponse((r) => r.url().endsWith(`/findings/${finding.id}/reopen`) && r.ok());
    await drawer.locator('[data-a="review:undo"]').click();
    await reopened;
    await expect(card).toBeVisible({ timeout: 15_000 });

    await page.keyboard.press('Escape');
    await expect(prose).toContainText(`The the ${marker}`);

    expect(errors).toEqual([]);
});

test('the app can be installed, and caches no manuscript', async ({ page, request }) => {
    await signIn(page);
    await page.goto('/books');

    const href = await page.locator('link[rel="manifest"]').getAttribute('href');
    const manifest = await (await request.get(href)).json();
    expect(manifest.display).toBe('standalone');
    expect(manifest.icons.some((i) => i.sizes === '512x512')).toBe(true);

    const worker = await (await request.get('/sw.js')).text();
    expect(worker).toContain("request.mode !== 'navigate'");
    expect((await request.get('/offline.html')).ok()).toBe(true);
});
