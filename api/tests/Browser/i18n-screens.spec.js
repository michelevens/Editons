import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Interface languages, part 2 (ADR-0050): the editorial, business and
 * account screens in French and Kreyòl. Each one is visited at 1440px and at
 * 390px; nothing may be wider than the screen (French runs a fifth longer
 * than English, Kreyòl about as long) and the console stays clean. One word
 * per screen is checked, so a screen still in English fails here.
 *
 * SHOTS, when set, is a directory the screenshots are written to.
 */

const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png`, fullPage: false }) : null);

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
    await page.goto('/books');
    await page.click('[data-a="user:menu"]');
    await page.click(`[data-a="locale:${locale}"]`);
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
}

/** name, path (id → url), and a word each language must show there. */
const SCREENS = [
    ['ai-editor', (id) => `/books/${id}/edit?pane=ai`, { fr: /Remarques|Relecture|Éditeur/, ht: /Remak|Revizyon|Editè/ }],
    ['analytics', (id) => `/books/${id}/edit?pane=analytics`, { fr: /Mots|mots/, ht: /Mo|mo/ }],
    ['research', (id) => `/books/${id}/edit?pane=research`, { fr: /Source|source/, ht: /Sous|sous/ }],
    ['references', (id) => `/books/${id}/edit?pane=references`, { fr: /Références|Bibliographie|citation/i, ht: /Referans|sitasyon/i }],
    ['terms', (id) => `/books/${id}/edit?pane=terms`, { fr: /Termes|terme|cohérence/i, ht: /Tèm|tèm/ }],
    ['notes', (id) => `/books/${id}/edit?pane=notes`, { fr: /Notes|note/, ht: /Nòt|nòt/ }],
    ['team', (id) => `/books/${id}/edit?pane=team`, { fr: /Inviter|Équipe|équipe/, ht: /Envite|Ekip|ekip/ }],
    ['workflow', (id) => `/books/${id}/edit?pane=workflow`, { fr: /Brouillon|Approuvé|révision/i, ht: /Bouyon|Apwouve|revizyon/i }],
    ['sales-panel', (id) => `/books/${id}/edit?pane=sales`, { fr: /Ventes|ventes|librairie/, ht: /Vant|vant|magazen/ }],
    ['overview', (id) => `/books/${id}/overview`, { fr: /Rédiger chaque chapitre/, ht: /Ekri chak chapit/ }],
    ['book-settings', (id) => `/books/${id}/settings`, { fr: /Enregistrer|Paramètres/, ht: /Anrejistre|Paramèt/ }],
    ['guided', (id) => `/books/${id}/guided`, { fr: /chapitre|livre/i, ht: /chapit|liv/i }],
    ['sales', () => '/sales', { fr: /Ventes|ventes/, ht: /Vant|vant/ }],
    ['billing', () => '/billing', { fr: /crédits|Offre|offre/, ht: /kredi|Plan|plan/ }],
    ['inbox', () => '/inbox', { fr: /Boîte|Tout|lu/, ht: /Bwat|Tout|li/ }],
    ['profile', () => '/profile', { fr: /Profil|Mot de passe|compte/, ht: /Pwofil|Modpas|kont/ }],
    ['support', () => '/support', { fr: /Support|Assistance|message/i, ht: /Sipò|mesaj/i }],
    ['partner-apply', () => '/partners/apply', { fr: /partenaire/i, ht: /patnè/i }],
];

for (const size of [
    { name: 'desktop', viewport: { width: 1440, height: 960 } },
    { name: 'phone', viewport: { width: 390, height: 844 } },
]) {
    test.describe(`editorial, business and account screens in French and Kreyòl (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('no screen is wider than the window, none is left in English', async ({ page }) => {
            test.setTimeout(600_000);
            const errors = watchForErrors(page);

            await signIn(page);
            const id = await firstBookId(page);

            try {
                for (const locale of ['fr', 'ht']) {
                    await choose(page, locale);

                    for (const [name, path, words] of SCREENS) {
                        // A module behind a feature flag that is off here
                        // (partners) answers 404: nothing to look at. Asked
                        // outside the page, so the console stays clean.
                        if ((await page.request.get(path(id))).status() === 404) continue;

                        await page.goto(path(id));
                        await expect(page.locator('html')).toHaveAttribute('lang', locale);
                        await page.waitForLoadState('networkidle');
                        await expect(page.locator('body'), `${locale} ${name}`).toContainText(words[locale]);
                        await noSideScroll(page, `${locale} ${name}`);
                        await shot(page, `${size.name}-${locale}-${name}`);
                    }
                }
            } finally {
                // The e2e account is shared by the whole suite.
                await choose(page, 'en');
            }

            expect(errors).toEqual([]);
        });
    });
}
