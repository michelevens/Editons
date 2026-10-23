import { expect, test } from '@playwright/test';
import { firstBookId, flagshipBookId, signIn, watchForErrors } from './helpers.js';

/**
 * The full-screen book preview on a phone: the book as the EPUB and the
 * printed book set it, a page at a time.
 */
test.describe.configure({ timeout: 240_000 });
test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

const preview = (page) => page.locator('[data-a="preview:root"]');
const ready = (page) => expect(preview(page)).toHaveAttribute('data-ready', 'true', { timeout: 60_000 });
const pageNumber = async (page) => parseInt(await page.locator('[data-a="preview:indicator"]').innerText(), 10);
const position = async (page) => ({
    item: await preview(page).getAttribute('data-item'),
    page: await preview(page).getAttribute('data-page'),
});

/** Open the preview on the book's first chapter of prose. */
async function openOnChapter(page, id) {
    await page.goto(`/books/${id}/edit?pane=preview`);
    await ready(page);

    await page.click('[data-a="preview:contents"]');
    const sheet = page.getByRole('dialog', { name: 'Contents' });
    await expect(sheet).toBeVisible();
    // The first entry that is a section of the book, not the title page.
    await sheet.locator('[data-a^="preview:jump:s"]').first().click();
    await expect(sheet).toBeHidden();
    await ready(page);
}

test('opens from Export & Publish, turns pages by key, tap and swipe, and closes back', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);

    await page.goto(`/books/${id}/edit?pane=export`);
    await page.click('[data-a="publish:preview"]');
    await ready(page);
    await expect(page.locator('[data-a="preview:indicator"]')).toHaveText(/^1 \/ ≈?\d+$/);

    // A key.
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => pageNumber(page)).toBe(2);
    await ready(page);

    // A tap on the right third, then the left third.
    const stage = await page.locator('[data-a="preview:stage"]').boundingBox();
    await page.mouse.click(stage.x + stage.width * 0.85, stage.y + stage.height / 2);
    await expect.poll(() => pageNumber(page)).toBe(3);
    await ready(page);
    await page.mouse.click(stage.x + stage.width * 0.15, stage.y + stage.height / 2);
    await expect.poll(() => pageNumber(page)).toBe(2);
    await ready(page);

    // A swipe to the left turns forward; a tap in the middle does nothing.
    const y = stage.y + stage.height / 2;
    await page.locator('[data-a="preview:stage"]').dispatchEvent('pointerdown', { clientX: stage.x + 300, clientY: y, pointerType: 'touch', isPrimary: true });
    await page.locator('[data-a="preview:stage"]').dispatchEvent('pointerup', { clientX: stage.x + 90, clientY: y + 6, pointerType: 'touch', isPrimary: true });
    await expect.poll(() => pageNumber(page)).toBe(3);
    await ready(page);

    const before = await position(page);
    await page.mouse.click(stage.x + stage.width / 2, y);
    expect(await position(page)).toEqual(before);

    // The buttons, for anybody without a swipe.
    await page.click('[data-a="preview:prev"]');
    await expect.poll(() => pageNumber(page)).toBe(2);

    // Escape goes back to where it was opened from.
    await page.keyboard.press('Escape');
    await expect(preview(page)).toBeHidden();
    await expect(page.locator('[data-a="publish:preview"]')).toBeVisible();

    expect(errors).toEqual([]);
});

test('the type size reflows the chapter, and the device switch changes the page', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await flagshipBookId(page);
    await openOnChapter(page, id);

    const fontSize = () =>
        page.locator('[data-a="preview:page"] > div').first().evaluate((host) =>
            parseFloat(getComputedStyle(host.shadowRoot.querySelector('.pv-flow')).fontSize),
        );

    await expect(page.locator('[data-a="preview:font-size"]')).toHaveText('100%');
    const small = { size: await fontSize(), pages: Number(await preview(page).getAttribute('data-pages')) };

    await page.click('[data-a="preview:font-up"]');
    await page.click('[data-a="preview:font-up"]');
    await expect(page.locator('[data-a="preview:font-size"]')).toHaveText('130%');
    await ready(page);
    await expect.poll(fontSize).toBeGreaterThan(small.size);
    expect(Number(await preview(page).getAttribute('data-pages'))).toBeGreaterThanOrEqual(small.pages);

    // Print: the trim's proportions, and no type size (the book sets it).
    await page.click('[data-a="preview:device:print"]');
    await expect(preview(page)).toHaveAttribute('data-device', 'print');
    await ready(page);
    const sheet = await page.locator('[data-a="preview:page"]').boundingBox();
    expect(sheet.height / sheet.width).toBeCloseTo(9 / 6, 1);
    await expect(page.locator('[data-a="preview:font-up"]')).toHaveCount(0);
    // PrintBook's own block markup, not the EPUB's.
    await expect
        .poll(() => page.locator('[data-a="preview:page"] > div').first().evaluate((host) => Boolean(host.shadowRoot?.querySelector('section[data-block]'))))
        .toBe(true);

    // E-reader: reflowed again, on e-ink paper.
    await page.click('[data-a="preview:device:ereader"]');
    await ready(page);
    expect(await page.locator('[data-a="preview:page"]').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(242, 239, 230)');

    // Remembered on this device.
    await page.reload();
    await ready(page);
    await expect(preview(page)).toHaveAttribute('data-device', 'ereader');
    await expect(page.locator('[data-a="preview:font-size"]')).toHaveText('130%');

    // Back to the defaults for the next test.
    await page.click('[data-a="preview:device:phone"]');
    await page.click('[data-a="preview:font-down"]');
    await page.click('[data-a="preview:font-down"]');
    await expect(page.locator('[data-a="preview:font-size"]')).toHaveText('100%');

    // Nothing runs off the side of the phone.
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    expect(errors).toEqual([]);
});

test('the check flips through the book and reports where it stopped', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit?pane=preview`);
    await ready(page);

    await page.click('[data-a="preview:check"]');
    await expect(page.locator('[data-a="preview:check"]')).toHaveAttribute('aria-pressed', 'true');

    const result = page.locator('[data-a="preview:check-result"]');
    await expect(result).toBeVisible({ timeout: 180_000 });
    await expect(result).toContainText(/no layout problems found|Page \d+:/);
    expect(await pageNumber(page)).toBeGreaterThan(1);

    await page.click('[data-a="preview:check-dismiss"]');
    await expect(result).toBeHidden();
    expect(errors).toEqual([]);
});

test('More opens the preview; the contents jump to a chapter', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript')).toBeVisible({ timeout: 25_000 });

    await page.click('[data-a="mobile:more"]');
    await page.getByRole('dialog', { name: 'Menu' }).locator('[data-a="sheet:preview"]').click();
    await ready(page);

    await page.click('[data-a="preview:contents"]');
    const entries = page.getByRole('dialog', { name: 'Contents' }).locator('[data-a^="preview:jump:s"]');
    const last = entries.last();
    const key = (await last.getAttribute('data-a')).replace('preview:jump:', '');
    await last.click();
    await expect(preview(page)).toHaveAttribute('data-item', key);
    await ready(page);

    // Closing returns to the manuscript it was opened over.
    await page.click('[data-a="dialog:close"]');
    await expect(page.locator('.manuscript')).toBeVisible();
    expect(errors).toEqual([]);
});
