import { expect, test } from '@playwright/test';
import {
    expectRendered,
    expectSurvives,
    flagshipBookId,
    signIn,
    watchForErrors,
} from './helpers.js';

/**
 * The whole-book review in a browser.
 *
 * The bug this replaced was a label, not a crash: "Run Full Editorial
 * Review" ran seven agents over one chapter. So the first assertion here is
 * about what the buttons say.
 */
test.describe('book director', () => {
    test('offers a chapter review and a book review, named honestly', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await flagshipBookId(page);
        await page.goto(`/books/${id}/edit`);
        await expectRendered(page, { minText: 300 });

        await expect(page.locator('[data-a="ai:run-full"]')).toContainText('Review this chapter');
        await expect(page.locator('[data-a="ai:direct"]')).toContainText('Review the whole book');

        expect(errors).toEqual([]);
    });

    test('reads the whole book without blanking the page', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await flagshipBookId(page);
        await page.goto(`/books/${id}/edit`);
        await expectRendered(page, { minText: 300 });

        const response = page.waitForResponse((r) => r.url().includes('/direct'));
        await expectSurvives(page, () => page.click('[data-a="ai:direct"]'));
        expect((await response).status()).toBe(200);

        // The demo book is evenly paced, so the honest result is a clean
        // report rather than invented findings. Asserted on the report's own
        // wording: "The whole book" also matches the button that opened it.
        await expect(
            page.getByText('Nothing structural to raise', { exact: false }),
        ).toBeVisible();

        expect(errors).toEqual([]);
    });
});
