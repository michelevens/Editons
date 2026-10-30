import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Interface languages, part 2 (ADR-0050): the publishing and design screens
 * — Publish, Export, Preview, Design & Layout, Cover Studio, Rights,
 * Marketing, Accessibility, Launch, Editions, Templates, Book settings'
 * parts and the KDP checklist — in French and in Kreyòl, with nothing wider
 * than the screen at 1440px and at 390px, and no console errors.
 *
 * SHOTS, when set, is a directory the screenshots are written to.
 */

const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png`, fullPage: false }) : null);

const PANES = ['publish', 'export', 'preview', 'layout', 'cover', 'rights', 'marketing', 'a11y', 'launch', 'editions', 'templates'];

// A word each language's publishing screens use, and English does not.
const WORDS = {
    fr: /Publier|Exporter|Couverture|Mise en page|Aperçu|Droits|Lancement|Éditions|Modèles|Accessibilité/,
    ht: /Pibliye|Ekspòte|Kouvèti|Mizanpaj|Apèsi|Dwa|Lansman|Edisyon|Modèl|Aksesiblite|Maketing|Piblisite/,
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
    test.describe(`publishing screens in French and Kreyòl (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('every publishing and design screen, in fr then ht', async ({ page }) => {
            test.setTimeout(600_000);
            const errors = watchForErrors(page);

            await signIn(page);
            const id = await firstBookId(page);

            for (const locale of ['fr', 'ht']) {
                await page.goto('/books');
                await choose(page, locale);

                for (const pane of PANES) {
                    await page.goto(`/books/${id}/edit?pane=${pane}`);
                    await expect(page.locator('html')).toHaveAttribute('lang', locale);
                    await page.waitForLoadState('networkidle');
                    await noSideScroll(page, `${locale} ${pane}`);
                    await shot(page, `${size.name}-${locale}-${pane}`);
                }

                // The words on the publish screen are the language's own.
                await page.goto(`/books/${id}/edit?pane=publish`);
                await page.waitForLoadState('networkidle');
                await expect(page.locator('body')).toContainText(WORDS[locale]);

                await page.goto(`/books/${id}/settings`);
                await expect(page.locator('html')).toHaveAttribute('lang', locale);
                await page.waitForLoadState('networkidle');
                await noSideScroll(page, `${locale} book settings`);
                await shot(page, `${size.name}-${locale}-settings`);

                await page.goto(`/publish/kdp-checklist?books[]=${id}`);
                await expect(page.locator('html')).toHaveAttribute('lang', locale);
                await noSideScroll(page, `${locale} KDP checklist`);
                await shot(page, `${size.name}-${locale}-kdp`);
            }

            // Back to English for the rest of the suite.
            await page.goto('/books');
            await choose(page, 'en');
            await page.goto(`/books/${id}/edit?pane=publish`);
            await page.waitForLoadState('networkidle');
            await expect(page.locator('body')).not.toContainText(WORDS.fr);

            expect(errors).toEqual([]);
        });
    });
}
