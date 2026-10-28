import { expect, test } from '@playwright/test';
import { signIn, watchForErrors } from './helpers.js';

/**
 * Margins, trims, typography and house styles on Design & Layout
 * (ADR-00XX — Mirrored margins, trims, typography and house styles): the
 * new controls change the page preview, mirrored margins show on the
 * spread, and a house style is saved, followed, detached and applied to
 * several books from the shelf.
 *
 * Every test puts the layout back to the defaults when it ends.
 */
const SHOTS = process.env.DESIGN_SHOTS ?? null;

async function shown(page, index = 0) {
    return page.evaluate((at) => {
        const host = document.querySelector(`[data-a="layout:print-preview"] [data-slot="shown"] [data-a="layout:page-host-${at}"]`);

        return host?.shadowRoot?.innerHTML ?? '';
    }, index);
}

/** Where a preview page's text block starts, in CSS pixels from the page's left edge. */
async function hostLeft(page, index) {
    return page.evaluate((at) => {
        const host = document.querySelector(`[data-a="layout:print-preview"] [data-slot="shown"] [data-a="layout:page-host-${at}"]`);

        return parseFloat(host?.style.left ?? 'NaN');
    }, index);
}

async function settled(page) {
    await expect(page.locator('[data-a="layout:preview-state"]')).toHaveText('As the PDF sets it', { timeout: 30_000 });
    await expect(page.locator('[data-a="layout:print-preview"]')).toHaveAttribute('data-busy', 'false', { timeout: 30_000 });
}

/** The ids of the demo shelf's books. */
async function bookIds(page) {
    await page.goto('/books');
    const hrefs = await page.locator('a[href*="/edit"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));

    return [...new Set(hrefs.map((href) => href?.match(/books\/(\d+)/)?.[1]).filter(Boolean))];
}

async function openDesign(page, id) {
    await page.goto(`/books/${id}/edit?pane=design`);
    await expect(page.locator('[data-a="layout:print-preview"] [data-slot="shown"]')).toBeVisible({ timeout: 30_000 });
}

/** Put a book back: no house style, the default layout. */
async function restore(page, id) {
    await page.evaluate(async (book) => {
        await window.axios.post(`/books/${book}/house-style/detach`).catch(() => null);
        await window.axios.post(`/books/${book}/layout/reset`).catch(() => null);
    }, id);
}

async function deleteStyles(page) {
    await page.evaluate(async () => {
        const { data } = await window.axios.get('/house-styles');

        for (const style of data.styles) await window.axios.delete(`/house-styles/${style.id}`);
    });
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test.describe(`at ${viewport.width}px`, () => {
        test.use({ viewport });

        test('margins, trims, faces, paragraphs and headings change the preview', async ({ page }) => {
            test.setTimeout(300_000);
            const errors = watchForErrors(page);
            await signIn(page);
            const [id] = await bookIds(page);
            await openDesign(page, id);
            await restore(page, id);
            await openDesign(page, id);

            try {
                // Symmetric margins by default: verso and recto blocks start at the same place.
                await settled(page);
                expect(await hostLeft(page, 1)).toBeCloseTo(await hostLeft(page, 2), 1);
                await expect(page.locator('[data-a="layout:mirrored"]')).toHaveAttribute('aria-checked', 'false');

                // Mirrored margins: the recommended pair, the wide margin at the spine.
                await page.click('[data-a="layout:mirrored"]');
                await expect(page.locator('[data-a="layout:mirrored"]')).toHaveAttribute('aria-checked', 'true');
                await settled(page);
                await expect.poll(async () => (await hostLeft(page, 2)) - (await hostLeft(page, 1))).toBeGreaterThan(20);
                if (SHOTS) {
                    await page.locator('[data-a="layout:print-preview"]').scrollIntoViewIfNeeded();
                    await page.screenshot({ path: `${SHOTS}/typo-${viewport.width}-mirrored.png` });
                }

                // An inside margin typed in millimetres.
                await page.click('[data-a="layout:unit-mm"]');
                await expect(page.locator('[data-a="layout:margin-inside"]')).toHaveValue(/\d+\.\d/);
                await page.fill('[data-a="layout:margin-inside"]', '25.4');
                await page.press('[data-a="layout:margin-inside"]', 'Enter');
                await settled(page);
                await expect.poll(async () => (await (await page.request.get(`/books/${id}/design`)).json()).layout.page.inside).toBe(1);
                await expect(page.locator('[data-a="layout:margin-effective"]')).toContainText('mm');

                // A trim from the full list, then a custom one.
                await page.selectOption('[data-a="layout:trim"]', '5.83 x 8.27 in');
                await settled(page);
                await expect(page.locator('[data-a="layout:trim-services"]')).toContainText('IngramSpark');
                await page.click('[data-a="layout:unit-in"]');
                await page.selectOption('[data-a="layout:trim"]', 'custom');
                await page.fill('[data-a="layout:trim-width"]', '5.25');
                await page.press('[data-a="layout:trim-width"]', 'Enter');
                await settled(page);
                await expect.poll(async () => (await (await page.request.get(`/books/${id}/design`)).json()).layout.trim).toBe('5.25 x 8.27 in');

                // Faces: the body in Lora, headings in Literata.
                await page.selectOption('[data-a="layout:font"]', 'lora');
                await page.selectOption('[data-a="layout:heading-font"]', 'literata');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toContain("font-family: 'Lora'");
                await expect.poll(() => shown(page, 0)).toContain("font-family: 'Literata'");

                // Paragraphs spaced, ragged right; H2 centred in capitals.
                await page.selectOption('[data-a="layout:text-paragraph"]', 'block');
                await page.selectOption('[data-a="layout:text-align"]', 'left');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toContain('.text > p { margin: 0 0 0.6em; }');
                await page.click('[data-a="layout:heading-h2"]');
                await page.selectOption('[data-a="layout:heading-align"]', 'center');
                await page.selectOption('[data-a="layout:heading-case"]', 'upper');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toContain('text-transform: uppercase; letter-spacing: .08em;');

                // Type up to 24 pt.
                await expect(page.locator('[data-a="layout:size"]')).toHaveAttribute('max', '24');

                if (SHOTS) {
                    await page.locator('[data-a="layout:print-preview"]').scrollIntoViewIfNeeded();
                    await page.screenshot({ path: `${SHOTS}/typo-${viewport.width}-typography.png` });
                }

                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                expect(overflow).toBeLessThanOrEqual(1);
            } finally {
                await restore(page, id);
            }

            expect(errors).toEqual([]);
        });

        test('a house style is saved, followed, detached and applied to several books', async ({ page }) => {
            test.setTimeout(300_000);
            const errors = watchForErrors(page);
            await signIn(page);
            const ids = await bookIds(page);
            const [source, ...others] = ids;
            await openDesign(page, source);
            await deleteStyles(page);

            for (const book of ids) await restore(page, book);

            try {
                await openDesign(page, source);
                await page.selectOption('[data-a="layout:font"]', 'spectral');
                await settled(page);

                // Save: the book then follows the style and its controls lock.
                const name = `Series ${viewport.width}`;
                await page.fill('[data-a="layout:house-style:name"]', name);
                await page.click('[data-a="layout:house-style:save"]');
                await expect(page.locator('[data-a="layout:house-style:following"]')).toContainText(name);
                await expect(page.locator('[data-a="layout:controls"]')).toHaveAttribute('disabled', '');
                await expect(page.locator('[data-a="layout:font"]')).toBeDisabled();
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/typo-${viewport.width}-house-following.png` });

                // Detach: the design stays, the controls unlock.
                await page.click('[data-a="layout:house-style:detach"]');
                await expect(page.locator('[data-a="layout:house-style:following"]')).toHaveCount(0);
                await expect(page.locator('[data-a="layout:controls"]')).not.toHaveAttribute('disabled', '');
                await expect(page.locator('[data-a="layout:font"]')).toBeEnabled();
                await expect(page.locator('[data-a="layout:font"]')).toHaveValue('spectral');

                // From the shelf, to the other books.
                await page.goto('/books');
                await page.click('[data-a="books:prepare:start"]');

                for (const book of others) await page.click(`[data-a="books:select:${book}"]`);

                await expect(page.locator('[data-a="books:house-style:pick"]')).toBeVisible();
                await page.click('[data-a="books:house-style:apply"]');
                await expect(page.locator('[data-a="books:house-style:result"] li')).toHaveCount(others.length);
                await expect(page.locator('[data-a="books:house-style:result"]')).not.toContainText('Not applied');
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/typo-${viewport.width}-shelf-apply.png`, fullPage: true });

                for (const book of others) {
                    const saved = await (await page.request.get(`/books/${book}/design`)).json();
                    expect(saved.layout.font).toBe('spectral');
                    expect(saved.house_style.following?.name).toBe(name);
                }

                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                expect(overflow).toBeLessThanOrEqual(1);
            } finally {
                for (const book of ids) await restore(page, book);
                await deleteStyles(page);
            }

            expect(errors).toEqual([]);
        });
    });
}
