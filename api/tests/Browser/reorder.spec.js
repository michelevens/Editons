import { expect, test } from '@playwright/test';
import { expectRendered, registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * Reordering chapters in the binder.
 *
 * The audit's drag from the last row onto the first left the order exactly
 * as it was: no row was draggable and no endpoint took an order. These move
 * a chapter three ways (keyboard, the move buttons, drag-and-drop) and check
 * each order survives a reload, which only a save on the server can do.
 */

/** Chapter ids in binder order. */
async function order(page) {
    return page
        .locator('[data-a^="outline:open:"]')
        .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-a').replace('outline:open:', '')));
}

async function bookWithThreeChapters(page) {
    await registerFreshAuthor(page);
    await page.goto('/books');
    await expectRendered(page);

    await page.click('[data-a="books:new"]');
    await page.fill('[data-a="books:new:title"]', `Reorder Probe ${Date.now()}`);
    await page.click('[data-a="books:new:skip"]');
    await page.waitForURL(/\/edit/);

    for (let added = 1; added < 3; added++) {
        await page.click('[data-a="outline:add"]');
        await expect(page.locator('[data-a^="outline:open:"]')).toHaveCount(added + 1);
    }

    return page.url().match(/books\/(\d+)/)[1];
}

test('a chapter moves by keyboard, by button and by drag, and stays moved', async ({ page }) => {
    const errors = watchForErrors(page);
    const id = await bookWithThreeChapters(page);

    const [a, b, c] = await order(page);

    // Alt+ArrowUp on the focused row: the last chapter moves up one.
    await page.focus(`[data-a="outline:open:${c}"]`);
    await page.keyboard.press('Alt+ArrowUp');
    await expect.poll(() => order(page)).toEqual([a, c, b]);

    // Focus stays on the row that moved, so the key can be pressed again.
    await expect(page.locator(`[data-a="outline:open:${c}"]`)).toBeFocused();
    await page.keyboard.press('Alt+ArrowUp');
    await expect.poll(() => order(page)).toEqual([c, a, b]);

    // The move-down button on a row.
    await page.hover(`[data-a="outline:item:${a}"]`);
    await page.click(`[data-a="outline:down:${a}"]`);
    await expect.poll(() => order(page)).toEqual([c, b, a]);

    // Drag the last row onto the first.
    await page
        .locator(`[data-a="outline:item:${a}"]`)
        .dragTo(page.locator(`[data-a="outline:item:${c}"]`));
    await expect.poll(() => order(page)).toEqual([a, c, b]);

    // Saved, not only drawn.
    await page.goto(`/books/${id}/edit`);
    await expectRendered(page, { minText: 200 });
    expect(await order(page)).toEqual([a, c, b]);

    expect(errors).toEqual([]);
});
