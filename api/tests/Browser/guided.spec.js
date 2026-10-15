import { expect, test } from '@playwright/test';
import {
    expectRendered,
    expectSurvives,
    firstBookId,
    signIn,
    watchForErrors,
} from './helpers.js';

/**
 * The Guided Journey in a browser.
 *
 * Pest proves the props are right. It cannot prove the page mounts, and the
 * blank screen this suite exists to catch returned 200 with correct props and
 * simply never rendered. Everything here is an interaction, because that is
 * where this product's React failures have actually happened.
 */
test.describe('guided journey', () => {
    test('renders, and reaches it from the book', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        // Through the door an author uses, not by typing the URL: a route
        // nothing links to is a route nobody finds.
        await page.goto(`/books/${id}/overview`);
        await page.click('[data-a="overview:guided"]');
        await page.waitForURL(/\/guided$/);

        await expectRendered(page, { minText: 300 });

        await expect(page.getByRole('heading', { name: 'The journey' })).toBeVisible();
        await expect(page.locator('[data-a^="guided:stage:"]')).toHaveCount(6);

        expect(errors).toEqual([]);
    });

    test('opening a stage does not blank the page', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);

        // Every stage, because each re-renders from a fresh payload and any
        // one of them could be the one that throws.
        for (const stage of ['discover', 'plan', 'create', 'improve', 'design', 'publish']) {
            await expectSurvives(page, () =>
                page.click(`[data-a="guided:stage:${stage}"]`),
            );
        }

        await expect(page.locator('[data-a="guided:stage:publish"]')).toHaveAttribute(
            'aria-current',
            'step',
        );

        expect(errors).toEqual([]);
    });

    test('changing the assistance level survives', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);

        await expectSurvives(page, () => page.click('[data-a="guided:assist:coach"]'));

        await expect(page.locator('[data-a="guided:assist:coach"]')).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        expect(errors).toEqual([]);
    });

    test('saving the idea survives, and keeps what was typed', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);

        const premise = `A book about finishing books. ${Date.now()}`;

        await page.fill('[data-a="guided:idea:premise"]', premise);
        await expectSurvives(page, () => page.click('[data-a="guided:idea:save"]'));

        // Reload: the save is only real if it outlives the page.
        await page.reload();
        await expect(page.locator('[data-a="guided:idea:premise"]')).toHaveValue(premise);

        expect(errors).toEqual([]);
    });

    test('the outline importer opens and closes', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);

        await expectSurvives(page, () => page.click('[data-a="guided:import:open"]'));
        await expect(page.locator('[data-a="guided:import:text"]')).toBeVisible();

        // Cancel rather than import: this book is the demo account's, and a
        // test that rewrites somebody's binder to prove a textarea opens has
        // done more than it was asked to.
        await expectSurvives(page, () => page.click('[data-a="guided:import:cancel"]'));
        await expect(page.locator('[data-a="guided:import:text"]')).toBeHidden();

        expect(errors).toEqual([]);
    });

    test('works on a phone', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.setViewportSize({ width: 390, height: 844 });

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        expect(overflow, 'the page scrolls sideways on a phone').toBeLessThanOrEqual(0);

        await expectSurvives(page, () => page.click('[data-a="guided:stage:plan"]'));

        expect(errors).toEqual([]);
    });
});

test.describe('coaching', () => {
    test('appears when asked for and goes quiet when not', async ({ page }) => {
        // The whole point of the assistance level: choosing it has to change
        // what is on screen, with no other step.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/guided`);
        await expectRendered(page);

        await expectSurvives(page, () => page.click('[data-a="guided:assist:coach"]'));
        await expect(page.getByRole('heading', { name: 'What I notice' })).toBeVisible();

        await expectSurvives(page, () => page.click('[data-a="guided:assist:author_led"]'));
        await expect(page.getByRole('heading', { name: 'What I notice' })).toBeHidden();

        expect(errors).toEqual([]);
    });
});

test('renders what the server sends, not what it first sent', async ({ page }) => {
    /*
     * The production failure this guards.
     *
     * The guided page took its journey from useState(initial), which
     * captures props once. On a real book the server returned a coaching
     * note and the page showed none.
     *
     * Asserted by comparing the page against the endpoint rather than by
     * navigating: two earlier versions of this test passed against the
     * broken code, one because page.reload() remounts the component and one
     * because goBack() restores Inertia's cached history state. Both proved
     * something true and irrelevant.
     */
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/guided`);
    await expectRendered(page);

    await expectSurvives(page, () => page.click('[data-a="guided:assist:coach"]'));

    const fromServer = await page.evaluate(async (bookId) => {
        const response = await fetch(`/books/${bookId}/journey`, {
            headers: { Accept: 'application/json' },
        });

        return (await response.json()).coaching.map((note) => note.key);
    }, id);

    const onPage = await page
        .locator('[data-a^="guided:coach:"]')
        .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-a').split(':').pop()));

    expect(onPage, 'the page is showing different coaching from the server')
        .toEqual(fromServer);

    expect(errors).toEqual([]);
});
