import { expect, test } from '@playwright/test';
import { firstBookId, signIn } from './helpers';

/**
 * What the author types reaches the database, whatever they do next, and
 * nothing they did not type does.
 *
 * Each test writes a marker and reads it back from the server through the
 * Markdown export, the manuscript as stored rather than as shown.
 */

const editor = (page) => page.locator('.manuscript .ProseMirror');
const status = (page) => page.locator('.manuscript [role="status"]');

async function stored(page, id) {
    const response = await page.request.get(`/books/${id}/export.md`);

    return response.text();
}

async function openFirstChapter(page) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(editor(page)).toBeVisible();

    return id;
}

/** Type at the end of the chapter's first paragraph. */
async function typeAtEnd(page, text) {
    await editor(page).locator('p').first().click();
    await page.keyboard.press('End');
    await page.keyboard.type(text);
}

test('an edit is kept when the author switches chapter at once', async ({ page }) => {
    test.setTimeout(120_000);
    const id = await openFirstChapter(page);
    const chapters = page.locator('[data-a^="outline:open:"]');
    expect(await chapters.count()).toBeGreaterThan(1);

    // Opened by address and settled, so the typing lands in this chapter
    // and not in the one it replaces.
    const first = (await chapters.nth(0).getAttribute('data-a')).split(':').pop();
    await page.goto(`/books/${id}/edit?section=${first}`);
    await page.waitForLoadState('networkidle');

    const marker = `FASTSWITCH${Date.now()}`;
    await typeAtEnd(page, ` ${marker}`);
    await expect(editor(page)).toContainText(marker);
    // Well inside the 1.5 s autosave wait.
    await chapters.nth(1).click();

    // Let the switch finish before reading back: the local PHP server
    // answers one request at a time.
    await page.waitForLoadState('networkidle');
    await expect.poll(() => stored(page, id), { timeout: 15_000 }).toContain(marker);
});

test('an edit is kept when the tab is closed at once, after a warning', async ({ page, context }) => {
    test.setTimeout(120_000);
    const id = await openFirstChapter(page);

    const second = await context.newPage();
    await second.goto(`/books/${id}/edit`);
    await expect(editor(second)).toBeVisible();

    const marker = `TABCLOSE${Date.now()}`;
    await typeAtEnd(second, ` ${marker}`);

    let warned = false;
    second.on('dialog', async (dialog) => {
        warned = dialog.type() === 'beforeunload';
        await dialog.accept();
    });
    await second.close({ runBeforeUnload: true });

    await expect.poll(() => stored(page, id), { timeout: 15_000 }).toContain(marker);
    expect(warned).toBe(true);
});

test('opening a chapter neither marks it unsaved nor saves it', async ({ page }) => {
    test.setTimeout(120_000);
    const id = await openFirstChapter(page);

    // A caption, saved by a real edit.
    await editor(page).locator('p').first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Control+b');
    await page.keyboard.type('Table 1');
    await page.keyboard.press('Control+b');
    await page.keyboard.type(' Renumbered on opening.');
    await expect(status(page)).toHaveText('Saved', { timeout: 15_000 });

    // Opened again in a place that renumbers it: the screen follows, the
    // database does not, and nothing says the chapter has changed.
    await page.route('**/cross-references?*', (route) => route.fulfill({
        json: {
            scheme: 'chapter',
            scheme_elsewhere: 'chapter',
            section: { prefix: '97.', offsets: { table: 0, figure: 0, equation: 0 }, running: { table: 0, figure: 0, equation: 0 } },
            targets: [],
        },
    }));

    const writes = [];
    page.on('request', (request) => {
        if (request.method() !== 'GET' && request.url().includes('/content')) writes.push(request.url());
    });

    await page.reload();
    const caption = editor(page).locator('p', { hasText: 'Renumbered on opening.' });
    await expect(caption.locator('strong')).toHaveText('Table 97.1');
    await page.waitForTimeout(3000);

    expect(writes).toEqual([]);
    await expect(status(page)).toHaveText('Ready');
    expect(await stored(page, id)).not.toContain('Table 97.1');

    // Leave the book as it was.
    await page.unroute('**/cross-references?*');
    await page.reload();
    await caption.click({ clickCount: 3 });
    await page.keyboard.press('Delete');
    await page.keyboard.press('Backspace');
    await expect(status(page)).toHaveText('Saved', { timeout: 15_000 });
});

test('a save the server did not confirm is shown, kept and sent again', async ({ page }) => {
    test.setTimeout(120_000);
    const id = await openFirstChapter(page);
    const errors = [];
    page.on('pageerror', (error) => errors.push(String(error)));

    // What a PHP notice printed ahead of the JSON looks like to the browser.
    await page.route('**/sections/*/content', (route) => route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<br />\n<b>Notice</b>: file created in the system\'s temporary directory<br />\n{"saved_at":"now","word_count":3}',
    }));

    const marker = `NOTICE${Date.now()}`;
    await typeAtEnd(page, ` ${marker}`);

    await expect(status(page)).toHaveText(/Not saved/, { timeout: 15_000 });
    await expect(editor(page)).toBeVisible();
    await expect(editor(page)).toContainText(marker);
    expect(errors).toEqual([]);

    // The connection recovers: the next edit sends everything.
    await page.unroute('**/sections/*/content');
    await page.keyboard.type('.');
    await expect(status(page)).toHaveText('Saved', { timeout: 15_000 });
    expect(await stored(page, id)).toContain(marker);
});

test('an upload the server did not confirm inserts no picture', async ({ page }) => {
    test.setTimeout(120_000);
    await openFirstChapter(page);

    await page.route('**/books/*/images', (route) => route.fulfill({
        status: 201,
        contentType: 'text/html',
        body: '<br />\n<b>Notice</b>: PHP Request Startup<br />\n{"src":"/books/1/images/x.png"}',
    }));

    await editor(page).locator('p').first().click();
    await page.click('[data-a="toolbar:image"]');
    await page.setInputFiles('[data-a="image:file"]', {
        name: 'figure.png',
        mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==', 'base64'),
    });
    await page.fill('[data-a="image:alt"]', 'An unconfirmed figure');
    await page.click('[data-a="image:insert"]');

    await expect(page.getByRole('dialog', { name: 'Insert picture' })).toContainText('could not be uploaded');
    await expect(editor(page).locator('img[alt="An unconfirmed figure"]')).toHaveCount(0);
});
