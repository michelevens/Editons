import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * A caption is renumbered from its place, and a cross-reference to it reads
 * its current number.
 */
test('captions renumber and cross-references follow them', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const editor = page.locator('.manuscript .ProseMirror');
    await editor.locator('p').first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');

    // A caption typed with a deliberately wrong number.
    await page.keyboard.press('Control+b');
    await page.keyboard.type('Table 9.9');
    await page.keyboard.press('Control+b');
    await page.keyboard.type(' A test caption.');

    const caption = editor.locator('p', { hasText: 'A test caption.' });
    // Renumbered from position, so no longer 9.9.
    await expect(caption.locator('strong')).not.toHaveText('Table 9.9');
    const number = await caption.locator('strong').textContent();
    expect(number).toMatch(/^Table (?:[A-Z]\.|\d+\.)?\d+$/);

    await page.keyboard.press('Enter');
    await page.keyboard.type('See ');
    await page.click('[data-a="toolbar:xref"]');
    await page.fill('[data-a="xref:search"]', number);
    await page.locator('[data-a^="xref:pick:tbl-"]').first().click();

    const reference = editor.locator('span.xref').last();
    await expect(reference).toHaveText(number);

    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.reload();
    await expect(page.locator('.manuscript .ProseMirror span.xref').last()).toHaveText(number);

    expect(errors).toEqual([]);
});
