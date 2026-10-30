import { expect, test } from '@playwright/test';
import { flagshipBookId, signIn, watchForErrors } from './helpers';

/**
 * Research library organisation and import, and comment filters (R3, R4).
 *
 * At 1440px and at 390px: import a BibTeX file (preview, then import),
 * search and filter the library, file a source under tags, then narrow the
 * comments by chapter and bulk-resolve the filtered set. Nothing may be
 * wider than the window and the console stays clean.
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

/** A small real-world .bib: accents in LaTeX, a corporate author, a Kreyòl title. */
function bib(stamp) {
    return `@article{preval${stamp},
  author  = {Pr{\\'e}val, Ren{\\'e} and Fran{\\c c}ois, Marie},
  title   = {Lavi nan m\\\`on yo ${stamp}},
  journal = {Journal of Haitian Studies},
  year    = 2019,
  pages   = {112--140}
}

@book{who${stamp},
  author    = {{World Health Organization}},
  title     = {Guidelines on Physical Activity ${stamp}},
  publisher = {WHO Press},
  year      = {2021}
}
`;
}

for (const size of [
    { name: 'desktop', viewport: { width: 1440, height: 960 } },
    { name: 'phone', viewport: { width: 390, height: 844 } },
]) {
    test.describe(`research library and comment filters (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('import, filter and tag sources; filter and bulk-resolve comments', async ({ page }) => {
            test.setTimeout(900_000);
            const errors = watchForErrors(page);
            page.on('dialog', (dialog) => dialog.accept());

            await signIn(page);
            const id = await flagshipBookId(page);
            const stamp = `${size.name}${Date.now()}`;

            // --- Research: import -------------------------------------------
            await page.goto(`/books/${id}/edit?pane=research`);
            await page.click('[data-a="research:import"]');
            await page.setInputFiles('[data-a="research:import-file"]', {
                name: 'library.bib',
                mimeType: 'text/plain',
                buffer: Buffer.from(bib(stamp)),
            });

            await expect(page.locator('[data-a="research:import-summary"]')).toContainText('2');
            await expect(page.getByText(`Lavi nan mòn yo ${stamp}`)).toBeVisible();
            await noSideScroll(page, 'import preview');
            await shot(page, `research-import-${size.name}`);

            await page.click('[data-a="research:import-submit"]');
            await expect(page.locator('[data-a="research:notice"]')).toBeVisible();

            // --- Research: search, filter, tag ------------------------------
            await page.fill('[data-a="research:search"]', stamp);
            await expect(page.locator('[data-a^="research:source:"]')).toHaveCount(2);
            await expect(page.getByText('Préval, René; François, Marie (2019)')).toBeVisible();

            await page.selectOption('[data-a="research:filter:kind"]', 'book');
            await expect(page.locator('[data-a^="research:source:"]')).toHaveCount(1);
            await expect(page.locator('[data-a="research:showing"]')).toBeVisible();

            const row = page.locator('[data-a^="research:source:"]').first();
            await row.locator('[data-a^="research:organise:"]').click();
            await row.locator('[data-a="research:tags-input"]').fill(`primary, ${stamp}`);
            await row.locator('[data-a="research:collection-input"]').fill('Guidelines');
            await row.locator('[data-a="research:organise-save"]').click();
            await expect(row.getByText('#primary')).toBeVisible();
            await expect(row.getByText('Guidelines', { exact: true })).toBeVisible();

            await page.click('[data-a="research:clear"]');
            await page.selectOption('[data-a="research:filter:tag"]', stamp);
            await expect(page.locator('[data-a^="research:source:"]')).toHaveCount(1);
            await noSideScroll(page, 'research filters');
            await shot(page, `research-filters-${size.name}`);

            // Export is a plain download of the library.
            const exported = await page.evaluate(async (bookId) => (await fetch(`/books/${bookId}/sources/export.bib`)).text(), id);
            expect(exported).toContain(`Lavi nan mòn yo ${stamp}`);

            // --- Comments: filters and bulk resolve -------------------------
            await page.goto(`/books/${id}/edit?pane=comments`);
            const draft = page.locator('[data-a="comments:draft"]');
            await expect(draft).toBeEnabled();

            for (const n of [1, 2]) {
                await draft.fill(`Check this ${stamp} #${n}`);
                await page.click('[data-a="comments:post"]');
                await expect(page.getByText(`Check this ${stamp} #${n}`)).toBeVisible();
                // The panel clears the draft once the post lands; typing
                // before then would be wiped.
                await expect(draft).toHaveValue('');
                await expect(page.locator('[data-a="comments:post"]')).not.toHaveText(/…/);
            }

            await expect(page.locator('[data-a="comments:filters"]')).toBeVisible();
            const chapter = page.locator('[data-a="comments:filter:chapter"]');
            const firstChapter = await chapter.locator('option').nth(1).getAttribute('value');
            await chapter.selectOption(firstChapter);
            await noSideScroll(page, 'comment filters');
            await shot(page, `comments-filters-${size.name}`);

            const resolve = page.locator('[data-a="comments:bulk-resolve"]');
            await expect(resolve).toBeVisible();
            await resolve.click();
            await expect(page.getByText(`Check this ${stamp} #1`)).toHaveCount(0);

            await page.click('[data-a="comments:filter:resolved"]');
            await expect(page.getByText(`Check this ${stamp} #1`)).toBeVisible();
            await shot(page, `comments-resolved-${size.name}`);

            expect(errors).toEqual([]);
        });
    });
}
