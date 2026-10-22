import { expect, test } from '@playwright/test';
import { ignoring, signIn, watchForErrors } from './helpers';

/**
 * Import from the shelf (a new book) and from the binder (more chapters),
 * with the report shown before anything else happens.
 *
 * The audit found Markdown import reachable only by API and no control for
 * it anywhere in the app; this is the path an author actually takes.
 */
const MANUSCRIPT = Buffer.from(
    '# Premier\n\nTexte avec **gras** et une image.\n\n![Carte](carte.png)\n\n# Second\n\n---\n\nFin.\n',
);

test('a manuscript imported from the shelf becomes a book, and more chapters come in from the binder', async ({ page }) => {
    test.setTimeout(300_000);
    const errors = watchForErrors(page);

    await signIn(page);
    await page.goto('/books');

    await page.click('[data-a="books:import"]');
    const dialog = page.getByRole('dialog', { name: 'Import a manuscript' });
    await expect(dialog).toBeVisible();

    // A file that only claims to be Markdown is refused, with the reason.
    await page.setInputFiles('[data-a="import:file"]', {
        name: 'faux.md',
        mimeType: 'text/markdown',
        buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x00, 0x00, 0x01]),
    });
    await page.click('[data-a="import:upload"]');
    await expect(dialog.getByRole('alert')).toContainText('not Markdown text');

    await page.setInputFiles('[data-a="import:file"]', {
        name: 'Essai_import.md',
        mimeType: 'text/markdown',
        buffer: MANUSCRIPT,
    });
    await page.click('[data-a="import:upload"]');

    const report = page.locator('[data-a="import:report"]');
    await expect(report).toContainText('Essai import');
    await expect(report).toContainText('2 chapters imported');
    await expect(report).toContainText('Premier');
    // The picture could not come with a Markdown file, and the report says so.
    await expect(report).toContainText('image: 1');

    await page.click('[data-a="import:done"]');
    await page.waitForURL(/\/books\/\d+\/edit/);
    const id = page.url().match(/\/books\/(\d+)\/edit/)[1];

    await page.click('[data-a="outline:import"]');
    await page.setInputFiles('[data-a="import:file"]', {
        name: 'suite.md',
        mimeType: 'text/markdown',
        buffer: Buffer.from('# Troisième\n\nEncore un chapitre.\n'),
    });
    await page.click('[data-a="import:upload"]');
    await expect(page.locator('[data-a="import:report"]')).toContainText('Everything in the file was carried over');
    await page.click('[data-a="import:done"]');

    await expect(page.getByRole('navigation').getByText('Troisième')).toBeVisible();

    // Leave the demo account as it was.
    const token = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    await page.request.delete(`/books/${id}`, { headers: { 'X-XSRF-TOKEN': token } });

    expect(ignoring(errors, '422')).toEqual([]);
});
