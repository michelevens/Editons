import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Cover backgrounds and series templates, end to end (ADR — Cover
 * backgrounds and series templates): a light background under white type
 * raises a contrast warning; a picture across the whole wrap shows in the
 * full wrap; the cover is saved as a template, applied to three books from
 * the shelf after a preview, and each book shows its own title; a reload
 * keeps it all. At 1440px and at 390px.
 *
 * SHOTS, when set, is a directory the screenshots are written to.
 */

// A 240 × 120 PNG.
const PICTURE = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAPAAAAB4CAIAAABD1OhwAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAC7klEQVR4nO3dPVIbURBF4SeX10FM7CU48IqJHLAE1uTAFAhJjJ7m9/W554sJumdOtUoUVZxenp6bRPHj6AGkNRm0UAxaKAYtFIMWikELxaCFYtBCMWihGLRQDFooBi0UgxaKQQvl59EDMP15/dXzY39/v209SZqTfw+9is6Cp9n3cga9yCodX7Ps2Qx6po1SPmfWMxj0Y3bo+Jpl9zPoXoekfM6se/hruy6H1zzIDOPzQt8xYEae6gle6CkD1txGnWoQXujbSkTjqb7mhb6hRM2tzpx7MuhLtSqpNe0ODPqLin1UnHk7Bv2pbhl1J1+dQb+r3kT1+ddi0K1RamBssZBBozog7TJPetC8AngbPSQ6aOq7p+7VIzpo8eQGzT5j7O0mhAad8L4TdrwWGrSoEoPOOV05m36ICzrtHaftGxe02LKCTjtX/0VtnRW08AxaKEFBR33yXsjZPShoJUgJOudEfSfkCaQErRAGLZSIoEM+be9KeA4RQSuHQQvFoIVi0ELhB53wTagf/mnwg1YUgxaKQQvFoIVi0EIxaKEYtFAMWigGLRSDFopBC8WghcIP2v8ffA7/NPhBK4pBC8WghWLQQokIGv9NqFPCc4gIWjkMWigpQSd82k4LeQIpQStEUNAhJ+qmnN2DglYCgxZKVtA5n7znorbOClp4cUFHnauWt29c0C3pHeds+iExaIGFBp1wuhJ2vBYadKO/b/Z2E3KDFlJ00NQzRt2rR3TQjfjueRs9JD3oxiqAtMs8Bt0apQPGFgsZ9LvqNVSffy0G/aluE3UnX51Bf1GxjIozb8egL9Xqo9a0OzDoG6pUUmXOPZ1enp6PnmFcw/4jYVP+jhd6ypjdjDnVILzQXQY51aZ8lxe6ywgljTDD+LzQjznkVJtyP4OeaYey7XgGg15ko6xNeTaDXscqZdvxcga9ic6+LXh1Bi0Uf20nFIMWikELxaCFYtBCMWihGLRQDFooBi0UgxaKQQvFoIVi0EL5B/ABtYOl/KJ8AAAAAElFTkSuQmCC',
    'base64',
);

const NAME = 'E2E series cover';

async function xsrf(page) {
    const cookies = await page.context().cookies();

    return decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

const design = async (page, id) => (await page.request.get(`/books/${id}/design`)).json();
const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png`, fullPage: true }) : null);

for (const size of [
    { name: 'desktop', viewport: { width: 1440, height: 960 }, phone: false },
    { name: 'phone', viewport: { width: 390, height: 844 }, phone: true },
]) {
    test.describe(`cover backgrounds and templates (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('gradient and contrast, a wrap picture, a template applied to three books', async ({ page }) => {
            test.setTimeout(420_000);
            const errors = watchForErrors(page);
            page.on('dialog', (dialog) => dialog.accept());

            await signIn(page);
            const id = await firstBookId(page);
            const headers = { 'X-XSRF-TOKEN': await xsrf(page) };

            // A known start: every demo book detached and without our template.
            for (const t of (await (await page.request.get('/cover-templates')).json()).templates) {
                if (t.name === NAME) await page.request.delete(`/cover-templates/${t.id}`, { headers });
            }
            await page.request.post(`/books/${id}/cover-template/detach`, { headers });
            await page.request.patch(`/books/${id}/cover`, { data: { backgrounds: null }, headers });
            // White type in layers, so a light background fails contrast.
            await page.request.delete(`/books/${id}/cover/layers`, { headers });
            await page.request.patch(`/books/${id}/cover`, { data: { palette: 0 }, headers });
            await page.request.post(`/books/${id}/cover/layers`, { headers });

            await page.goto(`/books/${id}/edit?pane=cover`);
            if (!size.phone) {
                const side = page.locator('[data-a="side:cover"]');
                if (await side.isVisible()) await side.click();
            }
            await expect(page.locator('[data-a="cover:bg"]')).toBeVisible({ timeout: 30_000 });

            // --- A light gradient under white type: a contrast warning. ----
            await page.click('[data-a="cover:bg:kind:linear"]');
            await page.fill('[data-a="cover:bg:stop:0"]', '#ffffff');
            await page.fill('[data-a="cover:bg:stop:1"]', '#e8e8e8');
            await expect.poll(async () => (await design(page, id)).cover.backgrounds?.front?.stops?.[1]?.color, { timeout: 20_000 }).toBe('#e8e8e8');
            await expect(page.locator('[data-a="cover:contrast"] [data-ok="no"]').first()).toBeAttached({ timeout: 30_000 });
            await expect(page.locator('[data-a="cover:contrast:summary"]')).toContainText('below the minimum');
            await shot(page, `${size.name}-1-contrast`);

            // --- One picture across the whole wrap. ------------------------
            await page.click('[data-a="cover:view:wrap"]');
            await expect(page.locator('[data-a="cover:wrap"]')).toBeVisible({ timeout: 60_000 });
            await page.click('[data-a="cover:bg-wrap:kind:image"]');
            await page.setInputFiles('[data-a="cover:bg-wrap:picture:upload"]', { name: 'wrap.png', mimeType: 'image/png', buffer: PICTURE });
            await expect.poll(async () => (await design(page, id)).cover.backgrounds?.wrap?.kind, { timeout: 30_000 }).toBe('image');
            await expect(page.locator('[data-a="cover:wrap-image"]')).toBeAttached();
            // A 240-pixel picture over a whole wrap is far under 300 dpi.
            await expect(page.locator('[data-a="cover:warnings"] [data-kind="dpi"]')).toBeVisible({ timeout: 30_000 });
            await shot(page, `${size.name}-2-wrap`);

            // --- Save the cover as a template. -----------------------------
            await page.click('[data-a="cover:view:front"]');
            await page.click('[data-a="cover:template:toggle"]');
            await page.fill('[data-a="cover:template:name"]', NAME);
            await page.click('[data-a="cover:template:save-run"]');
            await expect(page.locator('[data-a="cover:template:following"]')).toBeVisible({ timeout: 30_000 });
            await expect(page.locator('[data-a="cover:editing"]')).toHaveAttribute('aria-disabled', 'true');

            // --- Apply it to three books from the shelf. -------------------
            await page.goto('/books');
            const boxes = page.locator(size.phone ? '[data-a^="shelf:compact-select:"], [data-a^="books:select:"]' : '[data-a^="books:select:"]');
            await expect(boxes.nth(2)).toBeAttached();
            const ids = [];
            for (const n of [0, 1, 2]) {
                const box = boxes.nth(n);
                ids.push(Number((await box.getAttribute('data-a')).split(':').pop()));
                await box.check();
            }
            await expect(page.locator('[data-a="shelf:selected"]')).toHaveText('3 selected');
            await page.click('[data-a="shelf:bulk:cover_template"]');
            const pick = page.locator('[data-a="shelf:cover-template:pick"]');
            await pick.selectOption({ label: new RegExp(NAME) });
            await page.click('[data-a="shelf:cover-template:preview"]');
            await expect(page.locator('[data-a^="shelf:cover-template:preview:"] img')).toHaveCount(3, { timeout: 60_000 });
            await shot(page, `${size.name}-3-preview`);
            await page.click('[data-a="shelf:cover-template:apply"]');
            await expect(page.locator('[data-a="shelf:result:summary"]')).toContainText('3 done', { timeout: 60_000 });

            // Each book follows it and shows its own title.
            const check = async () => {
                for (const book of ids) {
                    const d = await design(page, book);
                    const title = (await (await page.request.get(`/books/${book}/design`)).json()).cover.title;
                    expect(d.cover.template.following?.name).toBe(NAME);
                    expect(d.cover.texts.title).toBe(title);
                    expect(d.cover.layers.items.length).toBeGreaterThan(0);
                }
                const titles = await Promise.all(ids.map(async (book) => (await design(page, book)).cover.texts.title));
                expect(new Set(titles).size).toBe(3);
            };
            await check();

            // Reload: it persisted.
            await page.reload();
            await check();
            await shot(page, `${size.name}-4-shelf`);

            // Leave the demo books as their own covers.
            for (const book of ids) await page.request.post(`/books/${book}/cover-template/restore`, { headers });

            expect(errors).toEqual([]);
        });
    });
}
