import { expect, test } from '@playwright/test';
import { deflateSync } from 'node:zlib';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Pictures and tables placed in the editor (ADR-0044 — Image and table
 * placement): a picture resized by its corner handle and wrapped right, a
 * table styled zebra with a column right-aligned and resized, each kept
 * after a reload, at desktop and phone widths. Every test leaves the demo
 * book as it found it.
 */
const SHOTS = process.env.PLACE_SHOTS ?? null;

/** A grey PNG of the given size, built here so the test needs no fixture file. */
function png(width, height) {
    const crcTable = Array.from({ length: 256 }, (_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        return c >>> 0;
    });
    const crc = (buffer) => {
        let c = 0xffffffff;
        for (const byte of buffer) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
    const chunk = (type, data) => {
        const length = Buffer.alloc(4);
        length.writeUInt32BE(data.length);
        const body = Buffer.concat([Buffer.from(type), data]);
        const sum = Buffer.alloc(4);
        sum.writeUInt32BE(crc(body));
        return Buffer.concat([length, body, sum]);
    };
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header[8] = 8; // bit depth
    header[9] = 0; // greyscale
    const rows = Buffer.alloc((width + 1) * height, 0x88);
    for (let y = 0; y < height; y++) rows[y * (width + 1)] = 0;

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', header),
        chunk('IDAT', deflateSync(rows)),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

async function openEditor(page) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    const editor = page.locator('.manuscript .ProseMirror');
    await expect(editor).toBeVisible({ timeout: 25_000 });

    return editor;
}

async function saved(page) {
    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 20_000 });
}

/** The picture's width as set on the canvas, percent. */
const widthOf = (locator) => locator.evaluate((element) => parseFloat(element.style.width));

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test.describe(`at ${viewport.width}px`, () => {
        test.use({ viewport });

        test('a picture is resized by its handle, wrapped right, and keeps both after a reload', async ({ page }) => {
            test.setTimeout(180_000);
            const errors = watchForErrors(page);
            const editor = await openEditor(page);

            await editor.locator('p').first().click();
            await page.keyboard.press('End');
            await page.click('[data-a="toolbar:image"]');
            await page.setInputFiles('[data-a="image:file"]', { name: 'placed.png', mimeType: 'image/png', buffer: png(400, 300) });
            await page.fill('[data-a="image:alt"]', 'A placed figure');
            await page.fill('[data-a="image:label"]', 'Figure 9.7');
            await page.fill('[data-a="image:caption"]', 'Placed by hand.');
            await page.click('[data-a="image:insert"]');

            const figure = editor.locator('.placed-image', { has: page.locator('img[alt="A placed figure"]') });
            await expect(figure).toBeVisible();

            try {
                // Select it: the picture's own controls appear.
                await figure.locator('img').click();
                await page.click('[data-a="image:width-50"]');
                await expect.poll(() => widthOf(figure)).toBe(50);
                // 400 px across half the measure: well below 300 dpi.
                await expect(page.locator('[data-a="image:dpi-warning"]')).toBeVisible();

                // The corner handle: dragged left, the picture narrows.
                await figure.locator('img').click();
                const handle = figure.locator('[data-a="image:resize"]');
                await expect(handle).toBeVisible();
                const box = await handle.boundingBox();
                await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
                await page.mouse.down();
                await page.mouse.move(box.x - 40, box.y + box.height / 2, { steps: 6 });
                await page.mouse.up();
                const size = await widthOf(figure);
                expect(size).toBeLessThan(50);
                expect(size).toBeGreaterThanOrEqual(10);

                // Wrap right, from the toolbar.
                await figure.locator('img').click();
                await page.selectOption('[data-a="image:wrap"]', 'right');
                await expect(figure).toHaveAttribute('data-wrap', 'right');

                await saved(page);
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/image-${viewport.width}.png` });

                await page.reload();
                const again = page.locator('.manuscript .ProseMirror .placed-image', { has: page.locator('img[alt="A placed figure"]') });
                await expect(again).toBeVisible({ timeout: 25_000 });
                await expect(again).toHaveAttribute('data-wrap', 'right');
                expect(await widthOf(again)).toBe(size);
            } finally {
                // Leave the demo book as it was: the picture and its caption.
                const picture = page.locator('.manuscript .ProseMirror .placed-image img[alt="A placed figure"]');
                if (await picture.count()) {
                    await picture.click();
                    await page.keyboard.press('Delete');
                }
                const caption = page.locator('.manuscript .ProseMirror p', { hasText: 'Placed by hand.' });
                if (await caption.count()) {
                    await caption.click();
                    await page.keyboard.press('End');
                    await page.keyboard.press('Shift+Home');
                    await page.keyboard.press('Delete');
                    await page.keyboard.press('Backspace');
                }
                await saved(page);
            }

            expect(errors).toEqual([]);
        });

        test('a table is styled zebra, a column right-aligned and resized, and keeps all after a reload', async ({ page }) => {
            test.setTimeout(180_000);
            const errors = watchForErrors(page);
            const editor = await openEditor(page);

            await editor.locator('p').first().click();
            await page.keyboard.press('End');
            await page.click('[data-a="toolbar:table"]');

            const table = editor.locator('table').last();
            await expect(table).toBeVisible();

            try {
                // Fill it, so the reload finds this table.
                const cells = table.locator('th, td');
                await cells.nth(0).click();
                await page.keyboard.type('Fruit');
                await cells.nth(1).click();
                await page.keyboard.type('Count');
                await cells.nth(4).click();
                await page.keyboard.type('12');

                await page.selectOption('[data-a="table:style"]', 'zebra');
                await expect(table).toHaveAttribute('data-style', 'zebra');

                // The cursor is in the second column: right-align it.
                await page.click('[data-a="table:align-right"]');
                await expect(cells.nth(1)).toHaveCSS('text-align', 'right');
                await expect(cells.nth(4)).toHaveCSS('text-align', 'right');

                // Drag the first column's right edge: every column then has a width.
                const first = await cells.nth(0).boundingBox();
                await page.mouse.move(first.x + first.width - 1, first.y + first.height / 2);
                await page.mouse.down();
                await page.mouse.move(first.x + first.width - 50, first.y + first.height / 2, { steps: 6 });
                await page.mouse.up();
                await expect(table).toHaveAttribute('data-widths', 'true');

                await saved(page);
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/table-${viewport.width}.png` });

                await page.reload();
                const again = page.locator('.manuscript .ProseMirror table', { has: page.getByText('Fruit', { exact: true }) });
                await expect(again).toBeVisible({ timeout: 25_000 });
                await expect(again).toHaveAttribute('data-style', 'zebra');
                await expect(again).toHaveAttribute('data-widths', 'true');
                await expect(again.locator('th, td').nth(4)).toHaveCSS('text-align', 'right');
            } finally {
                // Leave the demo book as it was.
                const left = page.locator('.manuscript .ProseMirror table', { has: page.getByText('Fruit', { exact: true }) });
                if (await left.count()) {
                    await left.locator('th, td').first().click();
                    await page.click('[data-a="toolbar:table:del"]');
                }
                await saved(page);
            }

            expect(errors).toEqual([]);
        });
    });
}
