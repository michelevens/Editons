import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/** A footnote is written, numbered, saved, edited and removed. */
test('a footnote is inserted, numbered and edited', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const editor = page.locator('.manuscript .ProseMirror');
    await editor.locator('p').first().click();
    await page.keyboard.press('End');

    const notes = editor.locator('sup.footnote-ref');
    const before = await notes.count();

    await page.click('[data-a="toolbar:footnote"]');
    await page.fill('[data-a="footnote:text"]', 'Hobsbawm, *The Age of Empire*, 41.');
    await page.click('[data-a="footnote:save"]');

    await expect(notes).toHaveCount(before + 1);
    // Numbered by its place in the chapter, by the editor's counter.
    const counter = await notes.nth(before).evaluate((el) => getComputedStyle(el, '::after').content);
    expect(counter).not.toBe('none');
    await expect(notes.nth(before)).toHaveAttribute('title', 'Hobsbawm, *The Age of Empire*, 41.');

    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.reload();
    await expect(page.locator('.manuscript .ProseMirror sup.footnote-ref')).toHaveCount(before + 1);

    await page.locator('.manuscript .ProseMirror sup.footnote-ref').nth(before).click();
    await expect(page.locator('[data-a="footnote:text"]')).toHaveValue('Hobsbawm, *The Age of Empire*, 41.');
    await page.click('[data-a="footnote:delete"]');
    await expect(page.locator('.manuscript .ProseMirror sup.footnote-ref')).toHaveCount(before);

    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    expect(errors).toEqual([]);
});
