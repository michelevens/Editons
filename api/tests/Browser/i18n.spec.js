import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Interface languages, end to end (ADR — Interface languages): switch to
 * Français from the account menu, then the shelf, the editor and the
 * publish screen are in French with nothing wider than the screen; the same
 * in Kreyòl; back to English and the words are the English ones. At 1440px
 * and at 390px, with no console errors.
 *
 * SHOTS, when set, is a directory the screenshots are written to.
 */

const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png`, fullPage: false }) : null);

const LANGUAGES = {
    fr: { shelf: 'Mes livres', newBook: 'Nouveau livre', saved: /Enregistr|Prêt|Modifications/, publish: 'Publier' },
    ht: { shelf: 'Liv mwen yo', newBook: 'Nouvo liv', saved: /Anrejistre|Pare|Chanjman/, publish: 'Pibliye' },
    en: { shelf: 'My Books', newBook: 'New book', saved: /Saved|Ready|Unsaved/, publish: 'Publish' },
};

async function noSideScroll(page, where) {
    const overflow = await page.evaluate(() => {
        const root = document.documentElement;
        const wide = [...document.querySelectorAll('body *')]
            .filter((el) => {
                const r = el.getBoundingClientRect();
                const style = getComputedStyle(el);

                return r.width > 0 && r.right > root.clientWidth + 1 && style.position !== 'fixed' && !el.closest('[class*="overflow-x-auto"], [class*="overflow-hidden"], .sr-only');
            })
            .slice(0, 3)
            .map((el) => `${el.tagName.toLowerCase()}${el.dataset.a ? `[data-a=${el.dataset.a}]` : ''} "${(el.textContent ?? '').trim().slice(0, 40)}"`);

        return { page: root.scrollWidth - root.clientWidth, wide };
    });

    expect(overflow.page, `${where}: page scrolls sideways (${overflow.wide.join(', ')})`).toBeLessThanOrEqual(1);
}

async function choose(page, locale) {
    await page.click('[data-a="user:menu"]');
    await page.click(`[data-a="locale:${locale}"]`);
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
}

for (const size of [
    { name: 'desktop', viewport: { width: 1440, height: 960 } },
    { name: 'phone', viewport: { width: 390, height: 844 } },
]) {
    test.describe(`interface languages (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('French, then Kreyòl, then English again: shelf, editor, publish', async ({ page }) => {
            test.setTimeout(300_000);
            const errors = watchForErrors(page);

            await signIn(page);
            const id = await firstBookId(page);

            for (const locale of ['fr', 'ht', 'en']) {
                const words = LANGUAGES[locale];

                await page.goto('/books');
                await choose(page, locale);

                // The shelf.
                await expect(page.locator('h1')).toHaveText(words.shelf);
                await expect(page.locator('[data-a="books:new"]')).toContainText(words.newBook);
                await noSideScroll(page, `${locale} shelf`);
                await shot(page, `${size.name}-${locale}-shelf`);

                // The account menu, with the switch.
                await page.click('[data-a="user:menu"]');
                await expect(page.locator(`[data-a="locale:${locale}"]`)).toHaveAttribute('aria-checked', 'true');
                await noSideScroll(page, `${locale} account menu`);
                await shot(page, `${size.name}-${locale}-menu`);
                await page.keyboard.press('Escape');

                // The editor.
                await page.goto(`/books/${id}/edit`);
                await expect(page.locator('html')).toHaveAttribute('lang', locale);
                await expect(page.locator('.ProseMirror').first()).toBeVisible();
                if (size.name === 'desktop') {
                    await expect(page.locator('[role="status"]').filter({ hasText: words.saved }).first()).toBeVisible();
                } else {
                    await expect(page.locator('[data-a="mobile:tabs"]')).toBeVisible();
                }
                await noSideScroll(page, `${locale} editor`);
                await shot(page, `${size.name}-${locale}-editor`);

                // Publish.
                await page.goto(`/books/${id}/edit?pane=publish`);
                await expect(page.locator('html')).toHaveAttribute('lang', locale);
                await noSideScroll(page, `${locale} publish`);
                await shot(page, `${size.name}-${locale}-publish`);

                // The More sheet on a phone: the longest labels on the smallest screen.
                if (size.name === 'phone') {
                    await page.click('[data-a="mobile:more"]');
                    await expect(page.locator('[data-a="sheet:more"]')).toBeVisible();
                    await noSideScroll(page, `${locale} more sheet`);
                    await shot(page, `${size.name}-${locale}-more`);
                    await page.keyboard.press('Escape');
                }
            }

            // Back in English: the words are the English ones.
            await page.goto('/books');
            await expect(page.locator('h1')).toHaveText('My Books');

            expect(errors).toEqual([]);
        });

        test('the sign-in page switches language before signing in', async ({ browser }) => {
            const context = await browser.newContext({ viewport: size.viewport, locale: 'fr-FR' });
            const page = await context.newPage();
            const errors = watchForErrors(page);

            // Accept-Language fr: French from the first visit.
            await page.goto('/login');
            await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
            await expect(page.getByRole('heading', { level: 1, name: 'Bon retour' })).toBeVisible();
            await noSideScroll(page, 'fr login');
            await shot(page, `${size.name}-fr-login`);

            await page.selectOption('[data-a="locale:select"]', 'ht');
            await expect(page.locator('html')).toHaveAttribute('lang', 'ht');
            await expect(page.getByRole('heading', { level: 1, name: 'Byenveni ankò' })).toBeVisible();
            await noSideScroll(page, 'ht login');
            await shot(page, `${size.name}-ht-login`);

            await page.goto('/register');
            await expect(page.getByRole('heading', { level: 1, name: 'Kòmanse ekri' })).toBeVisible();
            await noSideScroll(page, 'ht register');
            await shot(page, `${size.name}-ht-register`);

            await page.selectOption('[data-a="locale:select"]', 'en');
            await expect(page.getByRole('heading', { level: 1, name: 'Start writing' })).toBeVisible();

            expect(errors).toEqual([]);
            await context.close();
        });
    });
}
