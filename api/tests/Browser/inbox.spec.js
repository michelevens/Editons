import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * The inbox on a phone: a badge on the library's tab bar, rows that swipe
 * left to done and right to tomorrow, each with Undo, and a tap that opens
 * the screen the item is about.
 *
 * The suite's account works alone, so its items come from what it does
 * itself: an AI review of three chapters leaves one line per chapter (as
 * read, since whoever ran it saw it finish), and the test marks them unread
 * through the inbox's own endpoint.
 */
test.describe.configure({ timeout: 240_000 });
test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

async function fillInbox(page) {
    const id = await firstBookId(page);

    await page.evaluate(async (bookId) => {
        const board = await window.axios.get(`/books/${bookId}/workflow`);

        for (const chapter of board.data.chapters.slice(0, 3)) {
            await window.axios.post(`/sections/${chapter.id}/review`, { agent: 'line' });
        }
    }, id);

    await page.goto('/inbox');
    const ids = await page.locator('[data-a="inbox:item"]').evaluateAll((rows) => rows.map((row) => row.dataset.id));

    await page.evaluate(async (items) => {
        for (const item of items) await window.axios.patch(`/inbox/${item}`, { action: 'unread' });
    }, ids);

    await page.reload();
    await expect(page.locator('[data-a="inbox:item"]').first()).toBeVisible();

    return { id, count: ids.length };
}

const rows = (page) => page.locator('[data-a="inbox:item"]');

/** Drag a row sideways with the pointer, the way a thumb swipes. */
async function swipe(page, row, distance) {
    const box = await row.boundingBox();
    const y = box.y + box.height / 2;
    const from = box.x + box.width / 2;

    await page.mouse.move(from, y);
    await page.mouse.down();
    await page.mouse.move(from + distance / 3, y, { steps: 3 });
    await page.mouse.move(from + distance, y, { steps: 4 });
    await page.mouse.up();
}

test('the library tab bar carries the inbox and its unread count', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const { count } = await fillInbox(page);
    expect(count).toBeGreaterThanOrEqual(3);

    await page.goto('/books');
    await expect(page.locator('[data-a="mobile:inbox-count"]')).toContainText(String(count));
    await expect(page.locator('[data-a="user:unread-dot"]')).toBeVisible();

    await page.click('[data-a="mobile:inbox"]');
    await page.waitForURL(/\/inbox$/);
    await expect(rows(page)).toHaveCount(count);
    await expect(rows(page).first().locator('[data-a="inbox:unread-dot"]')).toBeVisible();
    await expect(page.locator('[data-a="mobile:inbox"]')).toHaveAttribute('aria-current', 'page');

    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    expect(errors).toEqual([]);
});

test('swipe left marks done, and Undo puts it back', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const { count } = await fillInbox(page);

    const first = rows(page).first();
    const id = await first.getAttribute('data-id');
    await swipe(page, first, -170);

    await expect(page.locator('[data-a="inbox:toast"]')).toContainText('Marked done.');
    await expect(rows(page)).toHaveCount(count - 1);
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(0);

    await page.click('[data-a="inbox:undo"]');
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(1);
    await expect(rows(page)).toHaveCount(count);

    // And it stayed back: the server was told, not just the list.
    await page.reload();
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('swipe right snoozes until tomorrow, into the Snoozed view', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    await fillInbox(page);

    const first = rows(page).first();
    const id = await first.getAttribute('data-id');
    await swipe(page, first, 170);

    await expect(page.locator('[data-a="inbox:toast"]')).toContainText('Snoozed until tomorrow');
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(0);

    await page.click('[data-a="inbox:view:snoozed"]');
    await page.waitForURL(/view=snoozed/);
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(1);

    // Woken from there, it is back in the inbox.
    await swipe(page, page.locator(`[data-a="inbox:item"][data-id="${id}"]`), 170);
    await expect(page.locator('[data-a="inbox:toast"]')).toContainText('Back in the inbox');
    await page.click('[data-a="inbox:view:inbox"]');
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('a tap opens the screen the item is about, and reads it', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const { id: bookId } = await fillInbox(page);

    const first = rows(page).first();
    const id = await first.getAttribute('data-id');
    await first.locator('[data-a="inbox:open"]').click();

    await page.waitForURL(new RegExp(`/books/${bookId}/edit\\?pane=ai&section=\\d+`));
    await expect(page.locator('[data-a="mobile:tabs"]')).toBeVisible({ timeout: 25_000 });

    await page.goto('/inbox');
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveAttribute('data-read', 'true');
    expect(errors).toEqual([]);
});

test('the keyboard does what a swipe does', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const { count } = await fillInbox(page);

    const first = rows(page).first();
    const id = await first.getAttribute('data-id');
    await first.locator('[data-a="inbox:open"]').focus();

    // U: read, and unread again.
    await page.keyboard.press('u');
    await expect(first).toHaveAttribute('data-read', 'true');
    await page.keyboard.press('u');
    await expect(first).toHaveAttribute('data-read', 'false');

    // E: done, with the same Undo.
    await page.keyboard.press('e');
    await expect(page.locator(`[data-a="inbox:item"][data-id="${id}"]`)).toHaveCount(0);
    await expect(rows(page)).toHaveCount(count - 1);
    await page.click('[data-a="inbox:undo"]');
    await expect(rows(page)).toHaveCount(count);

    expect(errors).toEqual([]);
});
