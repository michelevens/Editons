import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/** A 40×40 PNG, so the test needs no fixture file. */
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAIAAAADnC86AAAAMElEQVR4nO3NQQkAAAgEsEtiMBMbyxKCn8H+S/W8iFgsFovFYrFYLBaLxWKxWHxnAYAu0Gr60SXBAAAAAElFTkSuQmCC',
    'base64',
);

test('a picture is inserted as a captioned figure and survives a reload', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const editor = page.locator('.manuscript .ProseMirror');
    await editor.locator('p, h1, h2, h3').first().click();
    await page.keyboard.press('End');

    await page.click('[data-a="toolbar:image"]');
    await page.setInputFiles('[data-a="image:file"]', { name: 'figure.png', mimeType: 'image/png', buffer: PNG });

    // Without a description the picture cannot be inserted.
    await expect(page.locator('[data-a="image:insert"]')).toBeDisabled();
    await page.fill('[data-a="image:alt"]', 'A test figure');
    await page.fill('[data-a="image:label"]', 'Figure 9.9');
    await page.fill('[data-a="image:caption"]', 'A caption under the picture.');
    await page.click('[data-a="image:insert"]');

    const picture = editor.locator('img[alt="A test figure"]');
    await expect(picture).toBeVisible();
    await expect(editor.getByText('A caption under the picture.')).toBeVisible();

    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    await page.reload();
    const saved = page.locator('.manuscript .ProseMirror img[alt="A test figure"]');
    await expect(saved).toBeVisible();
    // A real picture, not a figure saved with no source.
    await expect.poll(() => saved.evaluate((img) => img.complete && img.naturalWidth)).toBeGreaterThan(0);

    // Leave the demo book as it was.
    await page.locator('.manuscript .ProseMirror img[alt="A test figure"]').click();
    await page.keyboard.press('Delete');
    await page.locator('.manuscript .ProseMirror').getByText('A caption under the picture.').click();
    await page.keyboard.press('Home');
    await page.keyboard.press('Shift+End');
    await page.keyboard.press('Delete');
    await page.keyboard.press('Backspace');


    // Let the clean-up save land before the page closes.
    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
    expect(errors).toEqual([]);
});
