import { expect, test } from '@playwright/test';
import { expectRendered, signIn, watchForErrors } from './helpers.js';

/**
 * Registering, and getting out again.
 *
 * The failure this covers was a 200: every Breeze auth controller redirects
 * to `dashboard`, which rendered the stock "You're logged in!" placeholder,
 * so a new author's first screen was not the product.
 */
test.describe('arrival', () => {
    test('a brand new author lands somewhere they can write', async ({ page }) => {
        const errors = watchForErrors(page);
        const email = `new-${Date.now()}@example.test`;

        await page.goto('/register');
        await expectRendered(page);

        await page.fill('#name', 'New Author');
        await page.fill('#email', email);
        await page.fill('#password', 'correct-horse-battery');
        await page.fill('#password_confirmation', 'correct-horse-battery');
        await page.click('button[type="submit"]');

        await page.waitForURL(/\/start$/);
        await expectRendered(page, { minText: 200 });

        // The product's own first question, not a placeholder.
        await expect(page.getByText('What are you writing?')).toBeVisible();
        await expect(page.getByText("You're logged in!")).toHaveCount(0);

        // And a way back out. This screen has no studio header, so a new
        // account could not sign out from the page registration sends it to.
        await page.click('[data-a="quick:signout"]');
        await page.waitForURL(/\/login$/);

        expect(errors).toEqual([]);
    });

    test('the account menu opens and can sign out', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await expectRendered(page);

        await page.click('[data-a="user:menu"]');
        await expect(page.locator('[data-a="user:billing"]')).toBeVisible();
        await expect(page.locator('[data-a="user:signout"]')).toBeVisible();

        // Escape closes it: without that a menu over the manuscript has to be
        // dismissed by navigating away.
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-a="user:signout"]')).toBeHidden();

        await page.click('[data-a="user:menu"]');
        await page.click('[data-a="user:signout"]');
        await page.waitForURL(/\/login$/);

        expect(errors).toEqual([]);
    });

    test('the menu reaches billing', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await expectRendered(page);

        await page.click('[data-a="user:menu"]');
        await page.click('[data-a="user:billing"]');
        await page.waitForURL(/billing/);
        await expectRendered(page, { minText: 200 });

        expect(errors).toEqual([]);
    });
});
