import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * A chart from pasted data: drawn in the dialog, inserted, saved, edited.
 */
test('a chart is made from data, saved, and edited', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const editor = page.locator('.manuscript .ProseMirror');
    await editor.locator('p, h1, h2, h3').first().click();
    await page.keyboard.press('End');

    await page.click('[data-a="toolbar:chart"]');
    await page.fill('[data-a="chart:title"]', 'Test chart');
    // Tab-separated, as a spreadsheet copies.
    await page.fill('[data-a="chart:data"]', 'Week\tResponses\n1\t12\n2\t15\n3\t19');
    await page.click('[data-a="chart:type:line"]');

    await expect(page.locator('[role="dialog"] svg polyline')).toBeVisible();
    await page.click('[data-a="chart:save"]');

    const chart = editor.locator('figure[data-type="chart"]').first();
    await expect(chart.locator('svg')).toBeVisible();

    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.reload();

    const saved = page.locator('.manuscript .ProseMirror figure[data-type="chart"]').first();
    await expect(saved.locator('svg')).toBeVisible();
    await expect(saved.locator('svg[role="img"]')).toHaveAttribute('aria-label', /Test chart\. Line chart of Responses by Week/);

    // Edit opens with the data, and delete leaves the book as it was.
    const charts = page.locator('.manuscript .ProseMirror figure[data-type="chart"]');
    const before = await charts.count();
    await saved.click();
    await expect(page.locator('[data-a="chart:data"]')).toHaveValue(/Responses/);
    await page.click('[data-a="chart:delete"]');
    await expect(charts).toHaveCount(before - 1);


    // Let the clean-up save land before the page closes.
    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    expect(errors).toEqual([]);
});
