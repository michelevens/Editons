import { mkdirSync, readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { signIn, watchForErrors } from './helpers.js';

/**
 * Shelf views and bulk actions (ADR-00XX), on a desktop and on a phone:
 * three books put in one series in the order ticked, found again by a
 * search without the accent, grouped by series, shown as a table with
 * chosen columns that survives a reload; stale files regenerated with
 * progress; the metadata CSV downloaded. Nothing wider than the screen
 * and no console errors. Everything is put back at the end.
 */
test.describe.configure({ timeout: 240_000 });

const SHOTS = process.env.SHELF_SHOTS;
const SERIES = 'Série E2E Shelf';

async function shot(page, name) {
    if (!SHOTS) return;
    mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

/** Forget the remembered view, so the run starts on today's shelf. */
async function resetView(page) {
    await page.evaluate(() => window.axios.put('/shelf/books/current', { state: {} }));
}

async function noHorizontalScroll(page) {
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wide).toBeLessThanOrEqual(1);
}

async function bulk(page, body) {
    return page.evaluate((data) => window.axios.post('/shelf/bulk', data).then((r) => r.data), body);
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test(`shelf views and bulk actions at ${viewport.width}px`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = watchForErrors(page);
        const phone = viewport.width < 640;

        await signIn(page);
        await page.goto('/books');
        await resetView(page);

        // Today's shelf: cards, last updated first.
        await page.goto('/books?filter=all');
        await expect(page.locator('[data-a="shelf:toolbar"]')).toBeVisible();
        await expect(page.locator('[data-a="shelf:view:cards"]')).toHaveAttribute('aria-pressed', 'true');

        // The table, by keyboard.
        await page.locator('[data-a="shelf:view:table"]').focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('[data-a="shelf:view:table"]')).toHaveAttribute('aria-pressed', 'true');

        // Three books, ticked in an order that is not the shelf's.
        const select = phone ? 'shelf:compact-select' : 'books:select';
        const boxes = page.locator(`[data-a^="${select}:"]`);
        await expect(boxes.nth(2)).toBeVisible();
        const ids = [];
        for (const n of [2, 0, 1]) {
            const box = boxes.nth(n);
            ids.push(Number((await box.getAttribute('data-a')).split(':').pop()));
            await box.check();
        }
        await expect(page.locator('[data-a="shelf:selected"]')).toHaveText('3 selected');

        // Set series: numbered 1–3 in the order ticked.
        await page.click('[data-a="shelf:bulk:series"]');
        await page.fill('[data-a="shelf:series:name"]', SERIES);
        await shot(page, `shelf-${viewport.width}-bulk-series`);
        await page.click('[data-a="shelf:series:run"]');
        await expect(page.locator('[data-a="shelf:result:summary"]')).toHaveText('3 done · 0 skipped');

        // Found without the accent, grouped by series.
        await page.fill('[data-a="shelf:search"]', 'serie e2e shelf');
        await expect(page).toHaveURL(/q=serie/);
        await page.check('[data-a="shelf:group"]');
        await expect(page.locator(`[data-a="shelf:group:${SERIES}"]:visible`).first()).toBeVisible();

        if (!phone) {
            await expect(page.locator('[data-a^="shelf:row:"]')).toHaveCount(3);
            // Columns: series and the last export.
            await page.click('[data-a="shelf:columns"] summary');
            if (!(await page.locator('[data-a="shelf:column:series"]').isChecked())) await page.check('[data-a="shelf:column:series"]');
            await expect(page).toHaveURL(/columns=[^&]*series/);
            await page.check('[data-a="shelf:column:exports"]');
            await expect(page.locator('[data-a="shelf:th:exports"]')).toBeVisible();
            for (const [n, id] of ids.entries()) {
                await expect(page.locator(`[data-a="shelf:row:${id}"]`)).toContainText(`${SERIES} · ${n + 1}`);
            }
        } else {
            await expect(page.locator('[data-a^="shelf:compact-select:"]')).toHaveCount(3);
            for (const [n, id] of ids.entries()) {
                await expect(page.locator(`[data-a="shelf:compact-select:${id}"]`).locator('xpath=..')).toContainText(`${SERIES} · ${n + 1}`);
            }
        }
        await shot(page, `shelf-${viewport.width}-table-grouped`);

        // The view comes back on return, with nothing in the address bar.
        await page.waitForTimeout(500);
        await page.goto('/books');
        await expect(page.locator('[data-a="shelf:view:table"]')).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('[data-a="shelf:search"]')).toHaveValue('serie e2e shelf');
        await expect(page.locator('[data-a="shelf:group"]')).toBeChecked();
        if (!phone) await expect(page.locator('[data-a="shelf:th:exports"]')).toBeVisible();

        // Select all in this view, then regenerate stale files with progress.
        await page.click('[data-a="shelf:select-all"]');
        await expect(page.locator('[data-a="shelf:selected"]')).toHaveText('3 selected');
        await page.click('[data-a="shelf:bulk:regenerate"]');
        await page.check('[data-a="shelf:regenerate:epub"]');
        await page.uncheck('[data-a="shelf:regenerate:pdf_interior"]');
        await page.check('[data-a="shelf:regenerate:missing"]');
        await page.click('[data-a="shelf:regenerate:run"]');
        await expect(page.locator('[data-a="shelf:result:summary"]')).toContainText('done');
        await expect(page.locator('[data-a="shelf:progress:text"]')).toBeVisible();
        // The bar stays on screen while the books are selected.
        const bar = await page.locator('[data-a="books:prepare"]').boundingBox();
        expect(bar.y + bar.height).toBeLessThanOrEqual(viewport.height + 1);
        await shot(page, `shelf-${viewport.width}-regenerate`);

        // The metadata CSV.
        const [download] = await Promise.all([page.waitForEvent('download'), page.click('[data-a="shelf:bulk:csv"]')]);
        const csv = readFileSync(await download.path(), 'utf8');
        expect(csv.split('\n')[0]).toContain('isbn_ebook');
        expect(csv).toContain(SERIES);
        expect(csv.trim().split('\n')).toHaveLength(4);

        await noHorizontalScroll(page);

        // Saved views: name this one, and it is offered.
        await page.click('[data-a="shelf:views:new"]');
        await page.fill('[data-a="shelf:views:name"]', `E2E ${viewport.width}`);
        await page.click('[data-a="shelf:views:save"]');
        await expect(page.locator('[data-a="shelf:views"]')).toContainText(`E2E ${viewport.width}`);
        page.once('dialog', (d) => d.accept());
        await page.click('[data-a="shelf:views:delete"]');
        await expect(page.locator('[data-a="shelf:views:delete"]')).toHaveCount(0);

        // Put everything back.
        await bulk(page, { action: 'series', books: ids, series_name: '' });
        await resetView(page);

        expect(errors).toEqual([]);
    });
}
