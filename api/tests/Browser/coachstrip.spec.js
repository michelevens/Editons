import { expect, test } from '@playwright/test';
import {
    chooseAssistance,
    currentAssistance,
    expectRendered,
    expectSurvives,
    flagshipBookId,
    signIn,
    watchForErrors,
} from './helpers.js';

/**
 * Coaching while writing.
 *
 * Coach::forSection() existed, was tested, and had no caller. These assert
 * an author actually meets it — and, just as importantly, that it stays out
 * of the way on an ordinary chapter.
 */
test.describe('coach strip', () => {
    // Both tests set the flagship's assistance level; put back what it was,
    // so the order these run in, and the tests after them, do not matter.
    let original;

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        await signIn(page);
        original = await currentAssistance(page, await flagshipBookId(page));
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await signIn(page);
        await chooseAssistance(page, await flagshipBookId(page), original);
        await page.close();
    });

    test('appears on a thin chapter when coaching is on', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await flagshipBookId(page);

        // Turn coaching on through the guided screen, as an author would.
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);
        await expectSurvives(page, () => page.click('[data-a="guided:assist:coach"]'));

        await page.goto(`/books/${id}/edit`);
        await expectRendered(page, { minText: 300 });

        // Front Matter is 9 words: the Coach should have something true to say.
        await expect(page.locator('[data-a="coach:strip"]')).toBeVisible();

        await expectSurvives(page, () => page.click('[data-a="coach:toggle"]'));
        await expect(page.locator('[data-a^="coach:note:"]').first()).toBeVisible();

        await expectSurvives(page, () => page.click('[data-a="coach:dismiss"]'));
        await expect(page.locator('[data-a="coach:strip"]')).toBeHidden();

        expect(errors).toEqual([]);
    });

    test('stays away when coaching is off', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await flagshipBookId(page);

        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);
        await expectSurvives(page, () => page.click('[data-a="guided:assist:author_led"]'));

        await page.goto(`/books/${id}/edit`);
        await expectRendered(page, { minText: 300 });

        await expect(page.locator('[data-a="coach:strip"]')).toHaveCount(0);

        expect(errors).toEqual([]);
    });
});
