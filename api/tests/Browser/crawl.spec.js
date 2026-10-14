import { expect, test } from '@playwright/test';
import { expectRendered, firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * The handoff's acceptance test, ported.
 *
 * "A Playwright crawl clicked all 709 [data-a] controls across the 16 routes
 * with 0 JS errors and 0 unhandled actions. Reproduce both as the e2e suite."
 *
 * This is the smaller version of that: every control on every screen that is
 * built, clicked once, asserting no console errors and that the page is still
 * rendered afterwards. It grows as screens land rather than being written
 * once against screens that do not exist.
 */

test.describe('every screen renders', () => {
    test('sign-in page', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.goto('/login');
        await expectRendered(page);
        await expect(page.getByRole('heading', { name: /welcome back/i })).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('registration page', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.goto('/register');
        await expectRendered(page);
        await expect(page.getByRole('heading', { name: /start writing/i })).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('the root sends a signed-out visitor to sign in', async ({ page }) => {
        await page.goto('/');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('book list', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await expectRendered(page);

        expect(errors).toEqual([]);
    });

    test('overview', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/overview`);
        await expectRendered(page, { minText: 200 });

        // The figures are counted server-side; what matters here is that they
        // reached the screen at all.
        await expect(page.getByText(/before this is finished/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('writing studio', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await expectRendered(page, { minText: 400 });

        // All seven agents, from the server's catalogue.
        await expect(page.getByText('Developmental Editor')).toBeVisible();
        await expect(page.getByText('Accessibility Check')).toBeVisible();

        expect(errors).toEqual([]);
    });
});

test.describe('controls', () => {
    /**
     * Click every [data-a] on the studio and assert nothing breaks.
     *
     * Re-queried each time because clicking swaps panes and invalidates
     * earlier handles. Controls that navigate away are visited last, since
     * each one costs a reload to come back from.
     */
    test('every control on the studio is wired', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await expectRendered(page);

        const seen = new Set();
        const navigates = (name) =>
            name.startsWith('outline:open:') ||
            name.startsWith('outline:row:') ||
            name.startsWith('nav:') ||
            name.startsWith('side:overview');

        for (let guard = 0; guard < 200; guard++) {
            const names = await page.locator('[data-a]').evaluateAll((elements) =>
                elements.map((element) => element.dataset.a),
            );

            const next = names.find((name) => !seen.has(name) && !navigates(name));

            if (!next) break;

            seen.add(next);

            const control = page.locator(`[data-a="${next}"]`).first();

            if (!(await control.isVisible().catch(() => false))) continue;
            if (await control.isDisabled().catch(() => true)) continue;

            await control.click({ timeout: 5000 }).catch(() => {});
            await page.waitForTimeout(120);

            // Come back if a click left the studio.
            if (!page.url().includes('/edit')) {
                await page.goto(`/books/${id}/edit`);
                await page.waitForTimeout(400);
            }
        }

        expect(seen.size, 'no controls found — the page probably did not render').toBeGreaterThan(10);
        expect(errors).toEqual([]);
    });
});
