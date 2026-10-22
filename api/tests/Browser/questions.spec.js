import { expect, test } from '@playwright/test';
import { expectRendered, expectSurvives, registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * Per-genre interview questions.
 *
 * The claim: choosing a genre changes what the product asks. A generic
 * "What is it about?" gets a generic answer, so the test is that two
 * different genres produce two different questions.
 *
 * Each test registers its own new author: what the form offers must not
 * depend on how full some shared shelf is.
 */
test('the new-book form asks in the genre\'s own words', async ({ page }) => {
    const errors = watchForErrors(page);

    await registerFreshAuthor(page);
    await page.goto('/books');
    await expectRendered(page);

    await page.click('[data-a="books:new"]');
    await expectSurvives(page, () => page.click('[data-a="books:new:type:cookbook"]'));

    await expect(page.getByText('What ties these recipes together?')).toBeVisible();

    // A different genre, a different question — not the same sentence with a
    // noun swapped.
    await page.click('[data-a="books:new:back"]');
    await expectSurvives(page, () => page.click('[data-a="books:new:type:fiction"]'));

    await expect(page.getByText('What happens, and to whom?')).toBeVisible();
    await expect(page.getByText('What ties these recipes together?')).toHaveCount(0);

    expect(errors).toEqual([]);
});

test('every genre can actually be created', async ({ page }) => {
    /*
     * The bug this guards, which was shipped and invisible.
     *
     * The words input had min="100" step="1000", making the valid values
     * 100, 1100, 2100… Every one of the fifteen genres suggests a round
     * number, so checkValidity() was false for all of them and the browser
     * blocked submit with no message: "Create the book" simply did nothing.
     *
     * The Pest tests POST directly and never run HTML5 validation, so only a
     * browser can catch this. Asserted on the form rather than by creating
     * fifteen books, which the plan limit would refuse anyway.
     */
    const errors = watchForErrors(page);

    await registerFreshAuthor(page);
    await page.goto('/books');
    await expectRendered(page);
    await page.click('[data-a="books:new"]');

    const types = await page.locator('[data-a^="books:new:type:"]').evaluateAll((nodes) =>
        nodes.map((n) => n.getAttribute('data-a')),
    );

    expect(types.length).toBeGreaterThanOrEqual(15);

    for (const type of types) {
        await expectSurvives(page, () => page.click(`[data-a="${type}"]`));
        await page.fill('[data-a="books:new:title"]', 'Validity Probe');

        const invalid = await page
            .locator('[data-a="books:new:form"]')
            .evaluate((form) =>
                Array.from(form.elements)
                    .filter((el) => el.willValidate && !el.checkValidity())
                    .map((el) => `${el.getAttribute('data-a')}: ${el.validationMessage}`),
            );

        expect(invalid, `${type} cannot be submitted`).toEqual([]);

        await page.click('[data-a="books:new:back"]');
    }

    expect(errors).toEqual([]);
});

test('the guided view asks the same way', async ({ page }) => {
    const errors = watchForErrors(page);

    await registerFreshAuthor(page);
    await page.goto('/books');
    await expectRendered(page);

    // A book of a known genre, made through the form, then opened in Guided.
    await page.click('[data-a="books:new"]');
    await expectSurvives(page, () => page.click('[data-a="books:new:type:cookbook"]'));
    await page.fill('[data-a="books:new:title"]', `Probe Cookbook ${Date.now()}`);
    await page.click('[data-a="books:new:create"]');
    await page.waitForURL(/\/edit/);

    const id = page.url().match(/books\/(\d+)/)[1];
    await page.goto(`/books/${id}/guided`);
    await expectRendered(page, { minText: 300 });

    await expect(page.getByText('What ties these recipes together?')).toBeVisible();

    expect(errors).toEqual([]);
});
