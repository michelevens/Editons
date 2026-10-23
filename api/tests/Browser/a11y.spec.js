import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * Keyboard, small screens and themes.
 *
 * Each test is a finding from the UI audit: dialogs that did not take focus
 * or close on Escape, phone drawers with no way out but the scrim, Overview
 * squeezed to 130px on a phone, the top nav printed over Search between 1024
 * and 1115px, no skip link, no dark mode, no distraction-free mode, and a
 * shelf that downloaded every full-size cover.
 */

// Each test signs in and opens the studio; on a loaded local server that
// is most of the time budget.
test.describe.configure({ timeout: 240_000 });

/** Where focus is, as something a failure message can show. */
const focusIsInside = (page, selector) =>
    page.evaluate((sel) => {
        const root = document.querySelector(sel);

        return Boolean(root && root.contains(document.activeElement));
    }, selector);

async function openEditor(page) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    return id;
}

test.describe('editor dialogs', () => {
    for (const [tool, label] of [
        ['image', 'Insert picture'],
        ['footnote', 'Insert footnote'],
        ['chart', 'Insert chart'],
        ['math-inline', 'Insert equation'],
    ]) {
        test(`${label}: focus moves in, Tab stays in, Escape closes, focus comes back`, async ({ page }) => {
            const errors = watchForErrors(page);
            await openEditor(page);

            const opener = page.locator(`[data-a="toolbar:${tool}"]`);
            await opener.focus();
            await page.keyboard.press('Enter');

            const dialog = page.getByRole('dialog', { name: label });
            await expect(dialog).toBeVisible();
            await expect.poll(() => focusIsInside(page, '[role="dialog"]')).toBe(true);

            for (let i = 0; i < 15; i++) {
                await page.keyboard.press('Tab');
                expect(await focusIsInside(page, '[role="dialog"]'), `Tab ${i + 1} left the dialog`).toBe(true);
            }

            for (let i = 0; i < 4; i++) await page.keyboard.press('Shift+Tab');
            expect(await focusIsInside(page, '[role="dialog"]')).toBe(true);

            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();

            // Back where the author was: the button, or the manuscript the
            // toolbar keeps focus in.
            await expect
                .poll(() =>
                    page.evaluate(() => {
                        const el = document.activeElement;

                        return Boolean(el?.closest('[data-a^="toolbar:"]') || el?.closest('.ProseMirror'));
                    }),
                )
                .toBe(true);

            expect(errors).toEqual([]);
        });
    }

    test('the search palette is a dialog that Escape closes', async ({ page }) => {
        await openEditor(page);

        await page.click('[data-a="palette:open"]');
        await expect(page.locator('[data-a="palette:input"]')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-a="palette:input"]')).toHaveCount(0);
        await expect(page.locator('[data-a="palette:open"]')).toBeFocused();
    });
});

test.describe('phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    for (const [trigger, name, close] of [
        ['mobile:menu', 'Menu', 'Close menu'],
        ['mobile:chapters', 'Chapters', 'Close chapters'],
        ['mobile:ai', 'AI Editor', 'Close AI Editor'],
    ]) {
        test(`the ${name} drawer takes focus, has a close button and closes on Escape`, async ({ page }) => {
            const errors = watchForErrors(page);
            await openEditor(page);

            await page.click(`[data-a="${trigger}"]`);
            const drawer = page.getByRole('dialog', { name });
            await expect(drawer).toBeVisible();
            await expect.poll(() => focusIsInside(page, '[role="dialog"]')).toBe(true);
            await expect(drawer.getByRole('button', { name: close })).toBeVisible();

            await page.keyboard.press('Escape');
            await expect(drawer).toBeHidden();
            await expect(page.locator(`[data-a="${trigger}"]`)).toBeFocused();

            // And the visible button closes it too.
            await page.click(`[data-a="${trigger}"]`);
            await drawer.getByRole('button', { name: close }).click();
            await expect(drawer).toBeHidden();

            expect(errors).toEqual([]);
        });
    }

    for (const pageName of ['overview', 'settings']) {
        test(`${pageName} fits a phone and its sidebar is a drawer`, async ({ page }) => {
            const errors = watchForErrors(page);
            await signIn(page);
            const id = await firstBookId(page);
            await page.goto(`/books/${id}/${pageName}`);
            await expect(page.locator('h1')).toBeVisible();

            // Nothing runs past the right edge (the sidebar used to squeeze
            // the page to ~130px and push its buttons off screen).
            const overflow = await page.evaluate(() =>
                [...document.querySelectorAll('main *')].filter((el) => {
                    const r = el.getBoundingClientRect();

                    return r.width > 0 && r.right > window.innerWidth + 1;
                }).length,
            );
            expect(overflow).toBe(0);
            await expect(page.locator('[data-a="side:overview"]')).toBeHidden();

            // The header's menu opens the sidebar, with the header's areas
            // in it: Analytics had no way in below 1024px.
            await page.click('[data-a="header:menu"]');
            const drawer = page.getByRole('dialog', { name: 'Menu' });
            await expect(drawer).toBeVisible();
            await expect(drawer.locator('[data-a="side:overview"]')).toBeVisible();
            await drawer.locator('[data-a="drawer:nav:analytics"]').click();
            await page.waitForURL(/pane=analytics/);

            expect(errors).toEqual([]);
        });
    }
});

test('the top nav never runs under Search between 1024 and 1280px', async ({ page }) => {
    await signIn(page);
    const id = await firstBookId(page);

    for (const width of [1024, 1060, 1100, 1180, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/books/${id}/edit`);
        await expect(page.locator('[data-a="nav:analytics"]')).toBeVisible();

        const navRight = (await page.locator('[data-a="nav:analytics"]').boundingBox()).x
            + (await page.locator('[data-a="nav:analytics"]').boundingBox()).width;
        const searchLeft = (await page.locator('[data-a="palette:open"]').boundingBox()).x;

        expect(navRight, `overlap at ${width}px`).toBeLessThanOrEqual(searchLeft);
    }
});

test('the first Tab is a skip link to the manuscript', async ({ page }) => {
    await openEditor(page);

    await page.locator('body').click({ position: { x: 1, y: 1 } });
    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press('Tab');
    await expect(page.locator('[data-a="skip"]')).toBeFocused();
    await expect(page.locator('[data-a="skip"]')).toBeVisible();

    await page.keyboard.press('Enter');
    await expect(page.locator('.manuscript .ProseMirror')).toBeFocused();

    // And the manuscript has a name for a screen reader.
    await expect(page.locator('.manuscript .ProseMirror')).toHaveAttribute('aria-label', /manuscript/);
});

test('distraction-free mode hides everything but the manuscript', async ({ page }) => {
    const errors = watchForErrors(page);
    await openEditor(page);

    await page.click('[data-a="editor:focus-mode"]');
    await expect(page.locator('header [data-a="nav:writing"]')).toHaveCount(0);
    await expect(page.locator('[data-a="side:manuscript"]')).toHaveCount(0);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.locator('[data-a="nav:writing"]')).toBeVisible();

    // The shortcut, both ways.
    await page.keyboard.press('Control+Shift+F');
    await expect(page.locator('[data-a="nav:writing"]')).toHaveCount(0);
    await page.keyboard.press('Control+Shift+F');
    await expect(page.locator('[data-a="nav:writing"]')).toBeVisible();

    expect(errors).toEqual([]);
});

test('dark mode follows the device, and a choice is saved to the account', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'dark' });
    const page = await context.newPage();

    try {
        await signIn(page);
        await page.goto('/books');

        // Following the device by default.
        await expect(page.locator('html')).toHaveClass(/dark/);

        await page.click('[data-a="user:menu"]');
        await page.click('[data-a="theme:light"]');
        await expect(page.locator('html')).not.toHaveClass(/dark/);

        // Saved: a fresh load in a dark-preferring browser stays light.
        await page.waitForTimeout(500);
        await page.reload();
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
        await expect(page.locator('html')).not.toHaveClass(/dark/);

        await page.click('[data-a="user:menu"]');
        await page.click('[data-a="theme:dark"]');
        await expect(page.locator('html')).toHaveClass(/dark/);
        const background = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        expect(background).toBe('rgb(14, 20, 34)');
    } finally {
        // The demo account is shared by the suite: put it back.
        await page.evaluate(() => window.axios.patch('/profile/theme', { theme: 'system' }));
        await context.close();
    }
});

test('the shelf shows thumbnails, lazily, behind named links', async ({ page }) => {
    await signIn(page);
    await page.goto('/books');

    const cover = page.locator('[data-a^="books:open:"]').first();
    await expect(cover).toHaveAttribute('aria-label', /^Open /);

    const img = cover.locator('img');

    if (await img.count()) {
        await expect(img).toHaveAttribute('src', /size=thumb/);
        await expect(img).toHaveAttribute('loading', 'lazy');
    }
});
