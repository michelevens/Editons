import { expect, test } from '@playwright/test';
import { expectRendered, ignoring, registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * Writing goals and touch reordering, on a phone.
 *
 * The chapter header's ring opens the goals sheet; a goal set there fills
 * the ring and puts a small one beside the chapter in the binder. The
 * binder's Reorder mode moves chapters with ↑/↓ and with a finger on a drag
 * handle (real touch events, through the DevTools protocol), and a long
 * press on a row opens its actions. Each change is checked after a reload,
 * which only a save on the server survives.
 */
test.describe.configure({ timeout: 240_000 });
test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1536, height: 1024 };

/** The phone's chapters drawer. */
const drawer = (page) => page.getByRole('dialog', { name: 'Chapters' });

/** Chapter ids in binder order, inside one binder. */
async function order(scope) {
    return scope
        .locator('[data-a^="outline:open:"]')
        .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-a').replace('outline:open:', '')));
}

/**
 * A fresh author's book with three chapters, made at desktop width (where
 * the binder is on screen), then shown on the phone.
 */
async function bookWithThreeChapters(page) {
    await page.setViewportSize(DESKTOP);
    await registerFreshAuthor(page);
    await page.goto('/books');
    await expectRendered(page);

    await page.click('[data-a="books:new"]');
    await page.fill('[data-a="books:new:title"]', `Goals Probe ${Date.now()}`);
    await page.click('[data-a="books:new:skip"]');
    await page.waitForURL(/\/edit/);

    for (let added = 1; added < 3; added++) {
        await page.click('[data-a="outline:add"]');
        await expect(page.locator('[data-a^="outline:open:"]')).toHaveCount(added + 1, { timeout: 30_000 });
    }

    const id = page.url().match(/books\/(\d+)/)[1];

    await page.setViewportSize(PHONE);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    return id;
}

async function openDrawer(page) {
    await page.click('[data-a="editor:chapters"]');
    await expect(drawer(page)).toBeVisible();

    return drawer(page);
}

/** A real touch: press at the centre of `locator`, optionally move, lift. */
async function touch(page, locator, { holdMs = 0, toY = null, steps = 8 } = {}) {
    const cdp = await page.context().newCDPSession(page);
    const box = await locator.boundingBox();
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;

    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });

    if (holdMs) await page.waitForTimeout(holdMs);

    if (toY !== null) {
        for (let i = 1; i <= steps; i++) {
            await cdp.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: [{ x, y: y + ((toY - y) * i) / steps }],
            });
        }
    }

    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
}

test('the chapter ring sets a goal, fills, and shows in the binder', async ({ page }) => {
    const errors = watchForErrors(page);
    const id = await bookWithThreeChapters(page);

    // Six words in the open chapter, saved.
    await page.click('.manuscript .ProseMirror');
    await page.keyboard.type('One two three four five six');
    await expect(page.getByText('Saved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });

    // No goal yet: the ring offers to set one.
    const ring = page.locator('[data-a="editor:goals"]');
    await expect(ring).toBeVisible();
    await expect(ring).toHaveAttribute('aria-label', /no goal set/);

    await ring.click();
    const sheet = page.getByRole('dialog', { name: 'Goals' });
    await expect(sheet).toBeVisible();
    await expect(sheet.locator('[data-a="goals:streak"]')).toBeVisible();

    // A goal of ten: six of ten, 60%.
    await sheet.locator('#goal-chapter').fill('10');
    await sheet.locator('[data-a="goals:chapter"] button[type="submit"]').click();
    await expect(sheet.locator('[data-a="goals:chapter:progress"]')).toContainText('6 of 10 words');
    await expect(sheet.locator('[data-a="goals:chapter:progress"] svg')).toHaveAttribute('data-progress', '60');

    // Lower it to five: reached, said once and quietly.
    await sheet.locator('#goal-chapter').fill('5');
    await sheet.locator('[data-a="goals:chapter"] button[type="submit"]').click();
    await expect(sheet.locator('[data-a="goals:chapter:reached"]')).toHaveText('Goal reached');

    // A refused value is named, not swallowed.
    await sheet.locator('#goal-daily').fill('10');
    await sheet.locator('[data-a="goals:daily"] button[type="submit"]').click();
    await expect(sheet.locator('[data-a="goals:daily"] [role="alert"]')).toBeVisible();

    // The daily goal: today's words against it, from saved history.
    await sheet.locator('#goal-daily').fill('100');
    await sheet.locator('[data-a="goals:daily"] button[type="submit"]').click();
    await expect(sheet.locator('[data-a="goals:today"]')).toContainText('of 100 words today');

    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();
    await expect(ring.locator('svg')).toHaveAttribute('data-reached', 'true');

    // The binder's row carries a small ring for the chapter with a goal.
    const binder = await openDrawer(page);
    await expect(binder.locator('[data-a^="outline:goal:"]')).toHaveCount(1);
    await page.keyboard.press('Escape');

    // Saved, not only drawn.
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('[data-a="editor:goals"] svg')).toHaveAttribute('data-reached', 'true', { timeout: 25_000 });

    // The one refusal above was provoked on purpose.
    expect(ignoring(errors, '422')).toEqual([]);
});

test('Reorder mode moves chapters with ↑/↓ and with a finger on the handle', async ({ page }) => {
    const errors = watchForErrors(page);
    const id = await bookWithThreeChapters(page);

    const binder = await openDrawer(page);
    const [a, b, c] = await order(binder);

    await binder.locator('[data-a="outline:reorder"]').click();
    await expect(binder.locator('[data-a="outline:reorder"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(binder.locator('[data-a^="outline:handle:"]')).toHaveCount(3);

    // ↓ on the first row.
    await binder.locator(`[data-a="outline:down:${a}"]`).click();
    await expect.poll(() => order(binder)).toEqual([b, a, c]);

    // Focus stays on the button, so it can be pressed again by keyboard.
    await expect(binder.locator(`[data-a="outline:down:${a}"]`)).toBeFocused();
    await page.keyboard.press('Enter');
    await expect.poll(() => order(binder)).toEqual([b, c, a]);

    // A finger drags the last row's handle above the first row.
    const first = await binder.locator(`[data-a="outline:item:${b}"]`).boundingBox();
    await touch(page, binder.locator(`[data-a="outline:handle:${a}"]`), { toY: first.y + 4 });
    await expect.poll(() => order(binder)).toEqual([a, b, c]);

    // While arranging, a tap on a row does not open the chapter.
    const url = page.url();
    // (force: the row is aria-disabled while arranging, which Playwright
    // would otherwise wait out.)
    await binder.locator(`[data-a="outline:open:${c}"]`).click({ force: true });
    await expect(binder).toBeVisible();
    expect(page.url()).toBe(url);

    // Done leaves the mode.
    await binder.locator('[data-a="outline:reorder"]').click();
    await expect(binder.locator('[data-a^="outline:handle:"]')).toHaveCount(0);

    // Saved, not only drawn.
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    expect(await order(await openDrawer(page))).toEqual([a, b, c]);

    expect(errors).toEqual([]);
});

test('a long press opens a row\'s actions: rename, set goal, move, delete', async ({ page }) => {
    const errors = watchForErrors(page);
    const id = await bookWithThreeChapters(page);

    let binder = await openDrawer(page);
    const [a, b, c] = await order(binder);
    const actions = page.locator('[data-a="outline:actions"]');

    // Rename.
    await touch(page, binder.locator(`[data-a="outline:open:${b}"]`), { holdMs: 750 });
    await expect(actions).toBeVisible();
    await actions.locator('[data-a="outline:action:rename"]').click();
    await actions.locator('[data-a="outline:rename:input"]').fill('The Crossing');
    await actions.locator('[data-a="outline:rename:save"]').click();
    await expect(actions).toBeHidden();
    await expect(binder.locator(`[data-a="outline:open:${b}"]`)).toHaveText('The Crossing');

    // The press did not also open the chapter.
    await expect(binder).toBeVisible();

    // Move down from the sheet.
    await touch(page, binder.locator(`[data-a="outline:open:${b}"]`), { holdMs: 750 });
    await actions.locator('[data-a="outline:action:down"]').click();
    await expect.poll(() => order(binder)).toEqual([a, c, b]);

    // Set goal opens the goals sheet for that chapter.
    await touch(page, binder.locator(`[data-a="outline:open:${c}"]`), { holdMs: 750 });
    await actions.locator('[data-a="outline:action:goal"]').click();
    const sheet = page.getByRole('dialog', { name: 'Goals' });
    await expect(sheet.locator('#goal-chapter')).toBeVisible();
    await sheet.locator('#goal-chapter').fill('2500');
    await sheet.locator('[data-a="goals:chapter"] button[type="submit"]').click();
    await expect(sheet.locator('[data-a="goals:chapter:progress"]')).toContainText('of 2,500 words');
    await page.keyboard.press('Escape');
    await expect(binder.locator(`[data-a="outline:goal:${c}"]`)).toBeVisible();

    // Delete asks first; "Keep it" keeps it.
    await touch(page, binder.locator(`[data-a="outline:open:${b}"]`), { holdMs: 750 });
    await actions.locator('[data-a="outline:action:delete"]').click();
    await expect(actions).toContainText('Delete “The Crossing”');
    await actions.getByRole('button', { name: 'Keep it' }).click();
    await actions.locator('[data-a="outline:action:delete"]').click();
    await actions.locator('[data-a="outline:delete:confirm"]').click();
    await expect(actions).toBeHidden();
    await expect.poll(() => order(binder)).toEqual([a, c]);

    // The keyboard reaches the same actions: Shift+F10 on a row.
    await binder.locator(`[data-a="outline:open:${c}"]`).focus();
    await page.keyboard.press('Shift+F10');
    await expect(actions).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(actions).toBeHidden();
    await expect(binder).toBeVisible();

    // All of it saved.
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    binder = await openDrawer(page);
    expect(await order(binder)).toEqual([a, c]);
    await expect(binder.locator(`[data-a="outline:goal:${c}"]`)).toBeVisible();

    expect(errors).toEqual([]);
});

test.describe('with a mouse', () => {
    // A desktop: no touch emulation, so HTML5 drag-and-drop is on.
    test.use({ viewport: DESKTOP, hasTouch: false, isMobile: false });

    test('the desktop binder keeps mouse drag-and-drop, and its rows have actions', async ({ page }) => {
        const errors = watchForErrors(page);
        await bookWithThreeChapters(page);
        await page.setViewportSize(DESKTOP);

        const binder = page.locator('aside').filter({ has: page.locator('[data-a="outline:reorder"]') }).first();
        const [a, b, c] = await order(binder);

        await binder.locator(`[data-a="outline:item:${c}"]`).dragTo(binder.locator(`[data-a="outline:item:${a}"]`));
        await expect.poll(() => order(binder)).toEqual([c, a, b]);

        // Reorder mode with a mouse: the handle's pointer drag.
        await binder.locator('[data-a="outline:reorder"]').click();
        const handle = binder.locator(`[data-a="outline:handle:${b}"]`);
        const top = await binder.locator(`[data-a="outline:item:${c}"]`).boundingBox();
        await handle.hover();
        await page.mouse.down();
        await page.mouse.move(top.x + 20, top.y + 3, { steps: 8 });
        await page.mouse.up();
        await expect.poll(() => order(binder)).toEqual([b, c, a]);

        // The handle answers the arrow keys.
        await binder.locator(`[data-a="outline:handle:${b}"]`).focus();
        await page.keyboard.press('ArrowDown');
        await expect.poll(() => order(binder)).toEqual([c, b, a]);

        await binder.locator('[data-a="outline:reorder"]').click();

        // Right-click opens a row's actions.
        await binder.locator(`[data-a="outline:open:${a}"]`).click({ button: 'right' });
        await expect(page.locator('[data-a="outline:actions"]')).toBeVisible();
        await page.keyboard.press('Escape');

        expect(errors).toEqual([]);
    });
});
