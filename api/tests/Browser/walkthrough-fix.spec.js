import { expect, test } from '@playwright/test';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * The author walkthrough fixes (ADR — Author walkthrough fixes, book 733),
 * at 1440 and 390 px:
 *
 *  - find (Ctrl/Cmd+F) and replace (Ctrl/Cmd+Shift+H) in the chapter, and
 *    find across the manuscript;
 *  - "Track changes" (was Suggest), its bar and its Turn off;
 *  - "Name the chapters" on a Quick Start book with no type;
 *  - /books/{id} and a studio pane's URL opened in the browser.
 *
 * Every account is a throwaway registered through the form. No console
 * errors, no horizontal scroll.
 */
test.describe.configure({ mode: 'serial', timeout: 900_000 });
test.use({ actionTimeout: 120_000, navigationTimeout: 180_000 });
const slow = expect.configure({ timeout: 120_000 });

const SHOTS = process.env.FRIC_SHOTS ?? null;

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function noHorizontalScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

async function quickStart(page, title) {
    await registerFreshAuthor(page);
    await page.goto('/start');
    await page.fill('[data-a="quick:title"]', title);
    await page.click('[data-a="quick:start"]');
    await page.waitForURL(/\/books\/\d+\/edit/);

    return Number(page.url().match(/\/books\/(\d+)\/edit/)[1]);
}

/** Type into the chapter and wait until it is saved. */
async function write(page, text) {
    await page.click('.ProseMirror');
    await page.keyboard.type(text);
    await slow(page.locator('[role="status"]').filter({ hasText: /saved|enregistr/i }).first()).toBeVisible();
}

test('1440: find and replace, track changes, outline, URLs', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchForErrors(page);

    const bookId = await quickStart(page, 'The Kite Over Jacmel');
    await slow(page.locator('[data-a="editor:first-run"]')).toBeVisible();
    await page.click('[data-a="editor:open-studio"]');

    await write(page, 'The kite rose over Jacmel. Ti Jak held the Kite string. The kite was red.');

    // Find in the chapter.
    await page.click('.ProseMirror');
    await page.keyboard.press('Control+f');
    await expect(page.locator('[data-a="find:bar"]')).toBeVisible();
    await page.fill('[data-a="find:term"]', 'kite');
    await expect(page.locator('[data-a="find:count"]')).toHaveText(/of 3/);
    await expect(page.locator('.ProseMirror .find-match')).toHaveCount(3);
    await page.click('[data-a="find:next"]');
    await expect(page.locator('[data-a="find:count"]')).toHaveText(/2 of 3/);
    await page.click('[data-a="find:case"]');
    await expect(page.locator('[data-a="find:count"]')).toHaveText(/of 2/);
    await shot(page, 'd01-find');

    // Replace: Ctrl+Shift+H opens the replace row.
    await page.keyboard.press('Control+Shift+H');
    await expect(page.locator('[data-a="find:replacement"]')).toBeVisible();
    await page.fill('[data-a="find:replacement"]', 'cerf-volant');
    await page.click('[data-a="find:replace-one"]');
    await expect(page.locator('[data-a="find:count"]')).toHaveText(/of 1/);
    await page.click('[data-a="find:replace-all"]');
    await expect(page.locator('.ProseMirror')).toContainText('The cerf-volant rose over Jacmel. Ti Jak held the Kite string. The cerf-volant was red.');
    await shot(page, 'd02-replaced');

    // Across the manuscript, after the edit is saved.
    await slow(page.locator('[role="status"]').filter({ hasText: /saved/i }).first()).toBeVisible();
    await page.click('[data-a="find:scope:book"]');
    await page.fill('[data-a="find:term"]', 'cerf-volant');
    await slow(page.locator('[data-a="find:results"] li')).toHaveCount(1);
    await expect(page.locator('[data-a="find:count"]')).toHaveText(/2 matches in 1 chapter/);
    await shot(page, 'd03-find-book');
    await page.click('[data-a="find:close"]');
    await expect(page.locator('[data-a="find:bar"]')).toHaveCount(0);

    // Track changes: named so, says it is on, turns off from its bar.
    const track = page.locator('[data-a="editor:suggest"]');
    await expect(track).toContainText('Track changes');
    await track.click();
    await expect(page.locator('[data-a="editor:suggesting"]')).toContainText('Track changes is on');
    await shot(page, 'd04-tracking');
    await page.click('[data-a="editor:suggest-off"]');
    await expect(page.locator('[data-a="editor:suggesting"]')).toHaveCount(0);

    // Name the chapters on a book with no type: no 422.
    const status = await page.evaluate(async (id) => (await window.axios.post(`/books/${id}/outline`)).status, bookId);
    expect(status).toBe(200);

    // Book Settings has the kind of book.
    await page.goto(`/books/${bookId}/settings`);
    await expect(page.locator('[data-a="settings:field:book_type"]')).toBeVisible();
    await shot(page, 'd05-settings-type');

    // Guided: Draft with me is coming soon.
    await page.goto(`/books/${bookId}/guided`);
    await expect(page.locator('[data-a="guided:assist:collaborative"]')).toBeDisabled();
    await shot(page, 'd06-guided');

    // URLs: the book's own address and a pane's address open the studio.
    await page.goto(`/books/${bookId}`);
    await expect(page).toHaveURL(new RegExp(`/books/${bookId}/edit`));
    await page.goto(`/books/${bookId}/design`);
    await expect(page).toHaveURL(new RegExp(`/books/${bookId}/edit\\?pane=design`));
    expect((await page.locator('body').innerText()).trim().startsWith('{')).toBe(false);
    await shot(page, 'd07-design-url');

    await noHorizontalScroll(page, 1440);
    expect(errors).toEqual([]);
});

test('390: the same on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const errors = watchForErrors(page);

    const bookId = await quickStart(page, 'Pocket Kite');
    await slow(page.locator('[data-a="editor:first-run"]')).toBeVisible();
    await page.click('[data-a="editor:open-studio"]');
    await noHorizontalScroll(page, 390);

    await write(page, 'The kite rose. The kite fell.');
    await page.keyboard.press('Control+f');
    await expect(page.locator('[data-a="find:bar"]')).toBeVisible();
    await page.fill('[data-a="find:term"]', 'kite');
    await expect(page.locator('[data-a="find:count"]')).toHaveText(/of 2/);
    await noHorizontalScroll(page, 390);
    await shot(page, 'm01-find');
    await page.click('[data-a="find:close"]');

    // The chapter's title is shown whole enough to read, not "1.".
    const status = await page.evaluate(async (id) => (await window.axios.post(`/books/${id}/outline`)).status, bookId);
    expect(status).toBe(200);
    await page.reload();
    const title = page.locator('[data-a="editor:chapters"]');
    await expect(title).toContainText('Beginnings');
    expect((await title.boundingBox()).width).toBeGreaterThan(90);
    await shot(page, 'm02-title');

    await page.click('[data-a="editor:suggest"]');
    await expect(page.locator('[data-a="editor:suggest-off"]')).toBeVisible();
    await noHorizontalScroll(page, 390);
    await shot(page, 'm03-tracking');
    await page.click('[data-a="editor:suggest-off"]');

    await page.goto(`/books/${bookId}/rights`);
    await expect(page).toHaveURL(new RegExp(`/books/${bookId}/edit\\?pane=rights`));
    await noHorizontalScroll(page, 390);
    await shot(page, 'm04-rights-url');

    expect(errors).toEqual([]);
});
