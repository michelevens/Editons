import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Equations in the editor: insert one, see it typeset, find it again after
 * a reload. The PHP tests cover the exports; this covers the part a student
 * actually touches.
 */
test('an equation is written, typeset, saved and edited', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const editor = page.locator('.manuscript .ProseMirror');
    // Into text, not onto an equation, which would open it for editing.
    await editor.locator('p, h1, h2, h3').first().click();
    await page.keyboard.press('End');

    await page.click('[data-a="toolbar:math-block"]');
    await page.fill('[data-a="math:latex"]', '\\bar{x} = \\frac{1}{n}\\sum_{i=1}^{n} x_i');
    await page.fill('[data-a="math:number"]', '3.1');

    // The preview typesets before anything is inserted.
    await expect(page.locator('[role="dialog"] .katex')).toBeVisible();
    await page.click('[data-a="math:save"]');

    const equation = editor.locator('[data-type="block-math"] .katex').first();
    await expect(equation).toBeVisible();

    // Autosave, then the equation must survive a reload.
    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.reload();
    await expect(editor.locator('[data-type="block-math"] .katex').first()).toBeVisible();

    // Clicking it opens it for editing, number and all.
    await editor.locator('[data-type="block-math"]').first().click();
    await expect(page.locator('[data-a="math:number"]')).toHaveValue('3.1');
    await page.click('[data-a="math:delete"]');
    await expect(page.locator('[role="dialog"]')).toHaveCount(0);


    // Let the clean-up save land before the page closes.
    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    expect(errors).toEqual([]);
});
