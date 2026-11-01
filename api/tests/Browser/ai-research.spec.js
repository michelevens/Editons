import { expect, test } from '@playwright/test';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * AI research, cover templates and store copy (ADR — AI research,
 * source-backed fact-checking, cover templates), at 1440 and 390 px, on
 * the sample drivers (no AI is called):
 *
 *  - research a topic, save two sources, cite one;
 *  - pick a starter cover template and use it;
 *  - write the store copy and approve the blurb, from "Finish my book".
 *
 * Every account is a throwaway registered through the form. No console
 * errors, no horizontal scroll.
 */
test.describe.configure({ mode: 'serial', timeout: 900_000 });
test.use({ actionTimeout: 120_000, navigationTimeout: 180_000 });
const slow = expect.configure({ timeout: 120_000 });

const SHOTS = process.env.RESEARCH_SHOTS ?? null;

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function noHorizontalScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

async function quickStart(page, title) {
    await registerFreshAuthor(page);
    await page.goto('/start');
    await page.fill('[data-a="quick:title"]', title);
    await page.click('[data-a="quick:start"]');
    await page.waitForURL(/\/books\/\d+\/edit/);

    return Number(page.url().match(/\/books\/(\d+)\/edit/)[1]);
}

async function pane(page, bookId, name) {
    await page.goto(`/books/${bookId}/edit?pane=${name}`);
}

async function researchAndSave(page, bookId, width, prefix) {
    await pane(page, bookId, 'research');
    await slow(page.locator('[data-a="research:ai"]')).toBeVisible();
    await page.click('[data-a="research:ai"]');
    await page.fill('[data-a="airesearch:question"]', 'Haitian immigration to Boston');
    await page.click('[data-a="airesearch:ask"]');
    await slow(page.locator('[data-a="airesearch:result"]')).toHaveCount(3);
    await expect(page.locator('[data-a="airesearch:sample"]')).toBeVisible();
    await shot(page, `${prefix}-research-results`);

    const boxes = page.locator('[data-a="airesearch:result"] input[type="checkbox"]');
    await boxes.nth(0).check();
    await boxes.nth(1).check();
    await page.click('[data-a="airesearch:save"]');
    await slow(page.locator('[data-a="research:notice"]')).toBeVisible();
    await noHorizontalScroll(page, width);
    await shot(page, `${prefix}-research-saved`);
}

async function coverTemplate(page, bookId, width, prefix) {
    await pane(page, bookId, 'cover');
    await slow(page.locator('[data-a="starter:panel"] summary')).toBeVisible();
    await page.click('[data-a="starter:panel"] summary');
    await slow(page.locator('[data-a="starter:template:quiet"]')).toBeVisible();
    await page.click('[data-a="starter:template:quiet"]');
    await page.selectOption('[data-a="starter:palette"]', 'forest');
    await page.click('[data-a="starter:suggest"]');
    await slow(page.locator('[data-a="starter:rationale"]')).toBeVisible();
    const applied = page.waitForResponse((r) => /\/cover\/starter$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST');
    await page.click('[data-a="starter:apply"]');
    expect((await applied).status()).toBe(200);
    // The studio reloads the design: the cover is now layered, so the
    // layer editor replaces "Customise text".
    await slow(page.locator('[data-a="cover:customise"]')).toHaveCount(0);
    await page.click('[data-a="starter:panel"] summary');
    await noHorizontalScroll(page, width);
    await shot(page, `${prefix}-cover-template`);
}

async function storeCopy(page, bookId, width, prefix) {
    await pane(page, bookId, 'publish');
    await slow(page.locator('[data-a="finish"]')).toBeVisible();
    await expect(page.locator('[data-a="finish:step:export"]')).toBeVisible();
    await shot(page, `${prefix}-finish`);
    await page.click('[data-a="finish:run:store_copy"]');
    await slow(page.locator('[data-a="storecopy:write"]')).toBeVisible();
    await page.click('[data-a="storecopy:write"]');
    await slow(page.locator('[data-a="storecopy:field:blurb"] textarea')).toBeVisible();
    await page.fill('[data-a="storecopy:field:blurb"] textarea', 'Four lives, one city: how Haitian families built a home in Boston.');
    const approved = page.waitForResponse((r) => r.url().includes('/store-copy/approve'));
    await page.click('[data-a="storecopy:approve:blurb"]');
    expect((await approved).status()).toBe(200);
    await expect(page.locator('[data-a="storecopy:field:blurb"]')).toContainText(/Approved/);
    await noHorizontalScroll(page, width);
    await shot(page, `${prefix}-store-copy`);
}

test('1440: research, save two, cite one; cover template; store copy', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchForErrors(page);

    const bookId = await quickStart(page, 'Here to Rise');
    await slow(page.locator('[data-a="editor:first-run"]')).toBeVisible();
    await page.click('[data-a="editor:open-studio"]');
    await page.click('.ProseMirror');
    await page.keyboard.type('In 2021, many Haitian families lived in Boston.');
    await slow(page.locator('[role="status"]').filter({ hasText: /saved|enregistr/i }).first()).toBeVisible();

    await researchAndSave(page, bookId, 1440, 'r1440');

    // Cite one of the saved sources from the chapter.
    await pane(page, bookId, 'manuscript');
    await slow(page.locator('.ProseMirror p').first()).toBeVisible();
    await page.locator('.ProseMirror p').first().click({ clickCount: 3 });
    await page.click('[data-a="ai:tab:Research"]');
    await slow(page.locator('[data-a="citations:add"]')).toBeVisible();
    await page.click('[data-a="citations:add"]');
    await page.selectOption('[data-a="citations:source"]', { index: 1 });
    const cited = page.waitForResponse((r) => r.url().includes('/citations') && r.request().method() === 'POST');
    await page.click('[data-a="citations:record"]');
    expect((await cited).status()).toBe(201);
    await shot(page, 'r1440-cited');

    await coverTemplate(page, bookId, 1440, 'r1440');
    await storeCopy(page, bookId, 1440, 'r1440');

    expect(errors).toEqual([]);
});

test('390: research and save; cover template; store copy', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const errors = watchForErrors(page);

    const bookId = await quickStart(page, 'Here to Rise');
    await researchAndSave(page, bookId, 390, 'r390');
    await coverTemplate(page, bookId, 390, 'r390');
    await storeCopy(page, bookId, 390, 'r390');

    expect(errors).toEqual([]);
});
