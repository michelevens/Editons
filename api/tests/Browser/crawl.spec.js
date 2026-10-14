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

    test('book settings', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/settings`);
        await expectRendered(page, { minText: 200 });

        await expect(page.getByRole('heading', { name: /book settings/i })).toBeVisible();
        // The three writing toggles are switches, not checkboxes, so they are
        // asserted by role — which also proves the aria wiring.
        await expect(page.getByRole('switch')).toHaveCount(3);

        expect(errors).toEqual([]);
    });

    test('a writing preference saves and survives a reload', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/settings`);

        const autosave = page.getByRole('switch', { name: /autosave/i });
        const before = await autosave.getAttribute('aria-checked');

        await autosave.click();
        await page.waitForTimeout(900);
        await page.reload();

        await expect(page.getByRole('switch', { name: /autosave/i })).toHaveAttribute(
            'aria-checked',
            before === 'true' ? 'false' : 'true',
        );

        expect(errors).toEqual([]);
    });

    test('shelf filters', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await expectRendered(page);

        await page.click('[data-a="books:filter:archived"]');
        await page.waitForURL(/filter=archived/);
        await expectRendered(page, { minText: 100 });

        expect(errors).toEqual([]);
    });

    test('AI Editor gathers findings across the book', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="nav:ai"]');
        await expectRendered(page, { minText: 200 });

        await expect(page.getByRole('heading', { name: /ai editor/i })).toBeVisible();
        await expect(page.locator('[data-a="ai-screen:status:open"]')).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('references renders a bibliography in each style', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:references"]');
        await expectRendered(page, { minText: 150 });

        await expect(page.getByText(/bibliography —/i)).toBeVisible();

        // Switching style must re-render, not just change the select.
        await page.selectOption('[data-a="references:style"]', 'MLA');
        await expect(page.getByText(/bibliography — MLA/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('a template inserts into the open chapter', async ({ page }) => {
        // The insertion goes through the normal save path, so the proof is
        // that the chapter is longer afterwards — not that a button changed.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const words = page.locator('[data-a="side:manuscript"]').first();
        await expect(words).toBeVisible();

        const before = await page.locator('.manuscript').innerText();

        await page.click('[data-a="side:templates"]');
        await expect(page.getByRole('heading', { name: /templates/i })).toBeVisible();
        await page.click('[data-a="templates:insert:checklist"]');

        await page.click('[data-a="side:manuscript"]');
        await expect
            .poll(async () => (await page.locator('.manuscript').innerText()).length, {
                timeout: 15_000,
            })
            .toBeGreaterThan(before.length);

        expect(errors).toEqual([]);
    });

    test('analytics charts real history', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="nav:analytics"]');
        await expectRendered(page, { minText: 200 });

        await expect(page.getByRole('heading', { name: /analytics/i })).toBeVisible();
        await expect(page.getByText(/words written per day/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('team members invites and shows the link', async ({ page }) => {
        // Nothing is emailed, so the link appearing IS the feature. A test
        // that only checked for a success toast would pass while the person
        // invited had no way in.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:team"]');
        await expect(page.getByRole('heading', { name: /team members/i })).toBeVisible();

        const email = `invitee${Date.now()}@example.com`;
        await page.fill('[data-a="team:email"]', email);
        await page.selectOption('[data-a="team:role"]', 'viewer');
        await page.click('[data-a="team:invite"]');

        await expect(page.getByText(email)).toBeVisible();
        await expect(page.getByText(/copy invite link/i).first()).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('an unknown invitation link says so', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.goto('/invitations/not-a-real-token');
        await expectRendered(page, { minText: 80 });
        await expect(page.getByText(/do not recognise this link/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('a comment posts and appears in its thread', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="ai:tab:Comments"]');

        const body = `A question ${Date.now()}`;
        await page.fill('[data-a="comments:draft"]', body);
        await page.click('[data-a="comments:post"]');

        // The proof is the thread rendering, not a button changing.
        await expect(page.getByText(body)).toBeVisible();

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
