import { expect, test } from '@playwright/test';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * Safe apply (ADR — Safe apply, honest prices, citation fixes), at 1440 and
 * 390 px:
 *
 *  - the bulk-accept confirmation lists the agents, ticks Copy and Line
 *    only, and "Undo this bulk accept" puts the findings back open;
 *  - the citation source picker shows title, author, year and site, and
 *    its search narrows the list.
 *
 * Every account is a throwaway registered through the form; the review is
 * the sample driver's (free). No console errors, no horizontal scroll.
 */
test.describe.configure({ mode: 'serial', timeout: 600_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 120_000 });
const slow = expect.configure({ timeout: 60_000 });

const SHOTS = process.env.SAFE_SHOTS ?? null;

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function noHorizontalScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

/** A fresh author, a book with one written chapter, and its ids. */
async function bookWithFindings(page) {
    await registerFreshAuthor(page);
    await page.goto('/start');
    await page.fill('[data-a="quick:title"]', 'Here to Rise');
    await page.click('[data-a="quick:start"]');
    await page.waitForURL(/\/books\/\d+\/edit/);
    const bookId = Number(page.url().match(/\/books\/(\d+)\/edit/)[1]);

    return page.evaluate(async (id) => {
        const { data } = await window.axios.get(`/books/${id}/findings`);
        const sectionId = data.sections[0].id;
        await window.axios.put(`/sections/${sectionId}/content`, {
            content: { type: 'doc', content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'The the river was wide when they crossed. She was really tired by noon, and the boat was slow.' }] },
            ] },
            reason: 'manual',
        });
        await window.axios.post(`/sections/${sectionId}/review`, { agent: 'copy', explicit: true });
        await window.axios.post(`/books/${id}/sources`, { kind: 'website', title: 'Ardem Patapoutian: Biographical', authors: 'The Kavli Prize', published: '2020', container: 'kavliprize.org', url: 'https://kavliprize.org/a' });
        await window.axios.post(`/books/${id}/sources`, { kind: 'website', title: 'Nobel Lecture Notes', authors: 'The Kavli Prize', published: '2020', container: 'kavliprize.org', url: 'https://kavliprize.org/b' });

        return { bookId: id, sectionId };
    }, bookId);
}

async function bulkAcceptAndUndo(page, { bookId }, width, prefix) {
    await page.goto(`/books/${bookId}/edit?pane=ai`);
    const list = page.locator('[data-a="ai-screen:list"]');
    await slow(list).toBeVisible();
    const open = await list.locator('li').count();
    expect(open).toBeGreaterThan(0);

    await page.check('[data-a="ai-screen:pick-all"]');
    await page.click('[data-a="ai-screen:bulk:accept"]');
    const breakdown = page.locator('[data-a="ai-screen:confirm:breakdown"]');
    await expect(breakdown).toBeVisible();
    await expect(page.locator('[data-a="ai-screen:confirm:agent:copy"]')).toBeChecked();
    await noHorizontalScroll(page, width);
    await shot(page, `${prefix}-bulk-confirm`);

    await page.click('[data-a="ai-screen:confirm:yes"]');
    await slow(page.locator('[data-a="ai-screen:bulk-undo"]')).toBeVisible();
    await shot(page, `${prefix}-bulk-done`);
    const accepted = await page.evaluate(async (id) => (await window.axios.get(`/books/${id}/findings`)).data.counts.accepted, bookId);
    expect(accepted).toBeGreaterThan(0);

    await page.click('[data-a="ai-screen:bulk-undo:button"]');
    await slow(page.locator('[data-a="ai-screen:bulk-undo"]')).toHaveCount(0);
    const counts = await page.evaluate(async (id) => (await window.axios.get(`/books/${id}/findings`)).data.counts, bookId);
    expect(counts.accepted).toBe(0);
    expect(counts.open).toBe(open);
    await shot(page, `${prefix}-bulk-undone`);
}

async function searchSources(scope, page, width, prefix) {
    await scope.locator('[data-a="citations:add"]').click();
    const options = scope.locator('[data-a="citations:source"] option');
    await expect(options.filter({ hasText: 'Ardem Patapoutian: Biographical — The Kavli Prize (2020) · kavliprize.org' })).toHaveCount(1);
    await expect(options).toHaveCount(3);

    await scope.locator('[data-a="citations:source-search"]').fill('nobel');
    await expect(options).toHaveCount(2);
    await expect(options.nth(1)).toContainText('Nobel Lecture Notes');
    await noHorizontalScroll(page, width);
    await shot(page, `${prefix}-source-search`);
}

test('1440: bulk accept with its breakdown and undo; citation picker search', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchForErrors(page);
    const book = await bookWithFindings(page);

    await bulkAcceptAndUndo(page, book, 1440, 's1440');

    await page.goto(`/books/${book.bookId}/edit`);
    const studio = page.locator('[data-a="editor:open-studio"]');
    if (await studio.isVisible().catch(() => false)) await studio.click();
    await page.click('[data-a="ai:tab:Research"]');
    await searchSources(page, page, 1440, 's1440');

    expect(errors).toEqual([]);
});

test('390: bulk accept with its breakdown and undo; citation picker search', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const errors = watchForErrors(page);
    const book = await bookWithFindings(page);

    await bulkAcceptAndUndo(page, book, 390, 's390');

    await page.goto(`/books/${book.bookId}/edit`);
    const studio = page.locator('[data-a="editor:open-studio"]');
    if (await studio.isVisible().catch(() => false)) await studio.click();
    await page.click('[data-a="mobile:ai"]');
    const drawer = page.getByRole('dialog', { name: 'AI Editor' });
    await expect(drawer).toBeVisible();
    await drawer.locator('[data-a="ai:tab:Research"]').click();
    await searchSources(drawer, page, 390, 's390');

    expect(errors).toEqual([]);
});
