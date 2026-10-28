import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Cover Studio's text layers, end to end: customise the layout's text, drag
 * the title, nudge it, restyle it, hide the subtitle, add a line of your
 * own, reload — and everything is where it was left. At desktop size and
 * at 390px, where the inspector is a bottom sheet.
 */

async function design(page, id) {
    const response = await page.request.get(`/books/${id}/design`);

    return (await response.json()).cover;
}

const layerOf = (cover, id) => cover.layers.items.find((l) => l.id === id);

/** Open Cover Studio with the layout's text turned into layers, fresh. */
async function openLayers(page, id, { phone = false } = {}) {
    page.on('dialog', (dialog) => dialog.accept());

    // Start from the layout every run: the suite shares one account.
    await page.request.delete(`/books/${id}/cover/layers`, {
        headers: { 'X-XSRF-TOKEN': await xsrf(page) },
    });

    await page.goto(`/books/${id}/edit?pane=cover`);

    if (!phone) {
        const side = page.locator('[data-a="side:cover"]');
        if (await side.isVisible()) await side.click();
    }

    await page.click('[data-a="cover:customise"]');
    await expect(page.locator('[data-a="cover:canvas"]')).toBeVisible();
    // The faces and their metrics arrive, and the title is drawn.
    await expect(page.locator('[data-a="cover:canvas"] g[data-layer="title"] tspan').first()).toBeAttached({ timeout: 60_000 });
}

async function xsrf(page) {
    const cookies = await page.context().cookies();

    return decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

/** The autosave is debounced; wait until the server has what the canvas has. */
async function settled(page, id, check) {
    await expect.poll(async () => check(await design(page, id)), { timeout: 30_000 }).toBe(true);
}

for (const size of [
    { name: 'desktop', viewport: { width: 1440, height: 960 }, phone: false },
    { name: 'phone', viewport: { width: 390, height: 844 }, phone: true },
]) {
    test.describe(`cover text layers (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('place, style, hide and add text, and it all persists', async ({ page }) => {
            // Many saves, each redrawing a 1600 × 2560 cover.
            test.setTimeout(240_000);
            const errors = watchForErrors(page);

            await signIn(page);
            const id = await firstBookId(page);
            await openLayers(page, id, { phone: size.phone });

            const start = await design(page, id);
            const before = layerOf(start, 'title');

            // Drag the title down and right.
            const box = await page.locator('[data-a="cover:box:title"]').boundingBox();
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
            await page.mouse.down();
            await page.mouse.move(box.x + box.width / 2 + 15, box.y + box.height / 2 + 40, { steps: 8 });
            await page.mouse.up();

            await settled(page, id, (cover) => layerOf(cover, 'title').y > before.y + 0.02);
            const dragged = layerOf(await design(page, id), 'title');

            // Nudge: three arrow presses on the canvas (desktop), the touch
            // buttons on a phone.
            if (size.phone) {
                // Left: a drag to the right can leave the box against the edge.
                await page.click('[data-a="cover:nudge:left"]');
                await settled(page, id, (cover) => Math.abs(layerOf(cover, 'title').x - (dragged.x - 10 / 1600)) < 0.0002);
            } else {
                await page.locator('[data-a="cover:canvas"]').focus();
                await page.keyboard.press('ArrowLeft');
                await page.keyboard.press('ArrowLeft');
                await page.keyboard.press('ArrowLeft');
                await page.keyboard.press('Shift+ArrowDown');
                await settled(page, id, (cover) => {
                    const t = layerOf(cover, 'title');

                    return Math.abs(t.x - (dragged.x - 3 / 1600)) < 0.0002 && Math.abs(t.y - (dragged.y + 10 / 2560)) < 0.0002;
                });
            }

            // Colour, size and typeface.
            await page.fill('[data-a="cover:color-hex"]', '#ff0000');
            await page.locator('[data-a="cover:inspector"] input[aria-label="Size (px)"]').fill('200');
            await page.click('[data-a="cover:font:montserrat"]');

            await settled(page, id, (cover) => {
                const t = layerOf(cover, 'title');

                return t.color === '#ff0000' && Math.abs(t.size * 2560 - 200) < 1 && t.font === 'montserrat';
            });

            // Undo takes the typeface back; redo restores it.
            await page.click('[data-a="cover:undo"]');
            await settled(page, id, (cover) => layerOf(cover, 'title').font !== 'montserrat');
            await page.click('[data-a="cover:redo"]');
            await settled(page, id, (cover) => layerOf(cover, 'title').font === 'montserrat');

            // Hide the subtitle.
            await page.click('[data-a="cover:layer:eye:subtitle"]');
            await settled(page, id, (cover) => layerOf(cover, 'subtitle').visible === false);
            await expect(page.locator('[data-a="cover:canvas"] g[data-layer="subtitle"]')).toHaveCount(0);

            // A line of the author's own.
            await page.click('[data-a="cover:add-text"]');
            await page.fill('[data-a="cover:text"]', 'Édition 2026 — Kreyòl');
            await settled(page, id, (cover) => cover.layers.items.some((l) => l.source === 'custom' && l.text === 'Édition 2026 — Kreyòl'));

            // The box the title was typed into stays put while its text changes.
            await page.click('[data-a="cover:layer:title"]');
            const titleBox = await page.locator('[data-a="cover:box:title"]').boundingBox();
            await page.locator('[data-a="cover:inspector"] [data-a="cover:title"]').fill('A New Title, Typed Here');
            const afterTyping = await page.locator('[data-a="cover:box:title"]').boundingBox();
            expect(Math.abs(afterTyping.x - titleBox.x)).toBeLessThan(1);
            expect(Math.abs(afterTyping.y - titleBox.y)).toBeLessThan(1);
            await settled(page, id, (cover) => cover.texts.title === 'A New Title, Typed Here');

            // Reload: all of it is still there.
            await page.reload();

            if (!size.phone) {
                const side = page.locator('[data-a="side:cover"]');
                if (await side.isVisible()) await side.click();
            }

            await expect(page.locator('[data-a="cover:canvas"]')).toBeVisible();
            await expect(page.locator('[data-a="cover:layers"]')).toContainText('Édition 2026 — Kreyòl');
            await expect(page.locator('[data-a="cover:layer:eye:subtitle"]')).toHaveAttribute('aria-pressed', 'false');

            const saved = layerOf(await design(page, id), 'title');
            expect(saved.color).toBe('#ff0000');
            expect(saved.font).toBe('montserrat');

            // The server's file follows the canvas.
            const image = await page.request.get((await design(page, id)).image);
            expect(image.status()).toBe(200);

            await page.screenshot({ path: `test-results/cover-editor-${size.name}.png` });

            // Leave the book on its layout, with its title, for the next test.
            await page.click('[data-a="cover:reset-layout"]');
            await expect(page.locator('[data-a="cover:customise"]')).toBeVisible();
            await page.request.patch(`/books/${id}/cover`, {
                data: { title: start.title },
                headers: { 'X-XSRF-TOKEN': await xsrf(page) },
            });

            expect(errors).toEqual([]);
        });
    });
}
