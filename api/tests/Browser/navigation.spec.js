import { expect, test } from '@playwright/test';
import { expectRendered, firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Buttons that did nothing, or the wrong thing.
 *
 * Every link on Overview opened the manuscript, because Inertia's get()
 * dropped the pane; the top nav on the shelf did nothing; Search said it was
 * not built; and a chapter link inside a full-screen panel changed chapter
 * behind the panel, so the click looked dead. Each test clicks the control
 * an author would and checks they arrive where it says.
 */
test.describe('navigation', () => {
    test.setTimeout(240_000);

    test('Overview links open the screen they name', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/overview`);
        await page.click('[data-a="side:layout"]');
        await page.waitForURL(new RegExp(`/books/${id}/edit\\?pane=layout$`));
        await expectRendered(page);
        await expect(page.locator('[data-a="side:layout"]')).toHaveAttribute('aria-current', 'page');

        // The checklist's editorial step opens the AI Editor, not the manuscript.
        await page.goto(`/books/${id}/overview`);
        const step = page.locator('[data-a="overview:step:ai"]');

        if (await step.count()) {
            await step.click();
            await page.waitForURL(new RegExp(`/books/${id}/edit\\?pane=ai$`));
            await expect(page.locator('[data-a="nav:ai"]')).toHaveAttribute('aria-current', 'page');
        }

        expect(errors).toEqual([]);
    });

    test('the top nav works from the shelf', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await page.click('[data-a="nav:design"]');
        await page.waitForURL(/\/books\/\d+\/edit\?pane=design$/);
        await expectRendered(page);
        await expect(page.locator('[data-a="nav:design"]')).toHaveAttribute('aria-current', 'page');

        expect(errors).toEqual([]);
    });

    test('search opens a screen by name', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await expectRendered(page);

        await page.click('[data-a="palette:open"]');
        await page.fill('[data-a="palette:input"]', 'cover studio');
        await page.keyboard.press('Enter');

        await expect(page.locator('[data-a="side:cover"]')).toHaveAttribute('aria-current', 'page');

        // And from the keyboard, as the label promises.
        await page.keyboard.press('Control+k');
        await expect(page.locator('[data-a="palette:input"]')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-a="palette:input"]')).toHaveCount(0);

        expect(errors).toEqual([]);
    });

    test('a chapter link in a panel shows the chapter', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit?pane=analytics`);
        await expectRendered(page);

        // The "Words by chapter" bars are keyed by section id.
        await page.locator('[data-a^="analytics:bar:"]').first().waitFor();
        const key = await page
            .locator('[data-a^="analytics:bar:"]')
            .evaluateAll((els) =>
                els.map((e) => e.dataset.a.replace('analytics:bar:', '')).find((k) => /^\d+$/.test(k)),
            );
        await page.click(`[data-a="analytics:bar:${key}"]`);

        await page.waitForURL(/section=\d+/);
        await expect(page.locator('[data-a="nav:writing"]')).toHaveAttribute('aria-current', 'page');
        await expect(page.locator('[data-a="side:manuscript"]')).toHaveAttribute('aria-current', 'page');

        expect(errors).toEqual([]);
    });
});
