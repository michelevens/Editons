import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers';

/**
 * Cover Studio's back, spine and full wrap, end to end: customise the
 * back, type into its copy box (which must not re-fit or move), add a
 * logo, drop a box on the barcode (it is moved clear), set the spine to
 * read bottom to top, look at the full wrap, reload — and everything is
 * where it was left. At 1440px and at 390px.
 *
 * SHOTS, when set, is a directory the screenshots are written to.
 */

// A 240 × 120 PNG: a red ground with a yellow disc.
const LOGO = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAPAAAAB4CAIAAABD1OhwAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAC7klEQVR4nO3dPVIbURBF4SeX10FM7CU48IqJHLAE1uTAFAhJjJ7m9/W554sJumdOtUoUVZxenp6bRPHj6AGkNRm0UAxaKAYtFIMWikELxaCFYtBCMWihGLRQDFooBi0UgxaKQQvl59EDMP15/dXzY39/v209SZqTfw+9is6Cp9n3cga9yCodX7Ps2Qx6po1SPmfWMxj0Y3bo+Jpl9zPoXoekfM6se/hruy6H1zzIDOPzQt8xYEae6gle6CkD1txGnWoQXujbSkTjqb7mhb6hRM2tzpx7MuhLtSqpNe0ODPqLin1UnHk7Bv2pbhl1J1+dQb+r3kT1+ddi0K1RamBssZBBozog7TJPetC8AngbPSQ6aOq7p+7VIzpo8eQGzT5j7O0mhAad8L4TdrwWGrSoEoPOOV05m36ICzrtHaftGxe02LKCTjtX/0VtnRW08AxaKEFBR33yXsjZPShoJUgJOudEfSfkCaQErRAGLZSIoEM+be9KeA4RQSuHQQvFoIVi0ELhB53wTagf/mnwg1YUgxaKQQvFoIVi0EIxaKEYtFAMWigGLRSDFopBC8WghcIP2v8ffA7/NPhBK4pBC8WghWLQQokIGv9NqFPCc4gIWjkMWigpQSd82k4LeQIpQStEUNAhJ+qmnN2DglYCgxZKVtA5n7znorbOClp4cUFHnauWt29c0C3pHeds+iExaIGFBp1wuhJ2vBYadKO/b/Z2E3KDFlJ00NQzRt2rR3TQjfjueRs9JD3oxiqAtMs8Bt0apQPGFgsZ9LvqNVSffy0G/aluE3UnX51Bf1GxjIozb8egL9Xqo9a0OzDoG6pUUmXOPZ1enp6PnmFcw/4jYVP+jhd6ypjdjDnVILzQXQY51aZ8lxe6ywgljTDD+LzQjznkVJtyP4OeaYey7XgGg15ko6xNeTaDXscqZdvxcga9ic6+LXh1Bi0Uf20nFIMWikELxaCFYtBCMWihGLRQDFooBi0UgxaKQQvFoIVi0EL5B/ABtYOl/KJ8AAAAAElFTkSuQmCC',
    'base64',
);

async function xsrf(page) {
    const cookies = await page.context().cookies();

    return decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

async function wrap(page, id) {
    return (await page.request.get(`/books/${id}/cover/wrap`)).json();
}

async function settled(page, id, check) {
    await expect.poll(async () => check(await wrap(page, id)), { timeout: 30_000 }).toBe(true);
}

const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png` }) : null);

async function openCover(page, id, phone) {
    await page.goto(`/books/${id}/edit?pane=cover`);

    if (!phone) {
        const side = page.locator('[data-a="side:cover"]');
        if (await side.isVisible()) await side.click();
    }

    await expect(page.locator('[data-a="cover:view:back"]')).toBeVisible();
}

for (const size of [
    { name: 'desktop', viewport: { width: 1440, height: 960 }, phone: false },
    { name: 'phone', viewport: { width: 390, height: 844 }, phone: true },
]) {
    test.describe(`cover back, spine and full wrap (${size.name})`, () => {
        test.use({ viewport: size.viewport });

        test('edit the back and the spine, see the wrap, and it persists', async ({ page }) => {
            test.setTimeout(300_000);
            const errors = watchForErrors(page);
            page.on('dialog', (dialog) => dialog.accept());

            await signIn(page);
            const id = await firstBookId(page);
            const headers = { 'X-XSRF-TOKEN': await xsrf(page) };

            // Start from the generated back and spine every run.
            await page.request.delete(`/books/${id}/cover/back-layers`, { headers });
            await page.request.delete(`/books/${id}/cover/spine-layers`, { headers });
            await page.request.patch(`/books/${id}/cover`, { data: { spine_direction: null }, headers });
            const original = (await wrap(page, id)).texts.description;

            await openCover(page, id, size.phone);

            // --- The back -------------------------------------------------
            await page.click('[data-a="cover:view:back"]');
            await page.click('[data-a="cover:customise-back"]');
            await expect(page.locator('[data-a="cover:canvas"]')).toBeVisible();
            await expect(page.locator('[data-a="cover:canvas"] g[data-layer="title"] tspan').first()).toBeAttached({ timeout: 60_000 });
            await settled(page, id, (w) => w.layers.back?.items?.some((l) => l.source === 'description'));

            // The copy box stays put while the copy grows: no re-fitting.
            await page.click('[data-a="cover:layer:description"]');
            const box = page.locator('[data-a="cover:box:description"]');
            const before = await box.boundingBox();
            const sizeInput = page.locator('[data-a="cover:inspector"] input[aria-label="Size (pt)"]');
            const sizeBefore = await sizeInput.inputValue();
            const copy = page.locator('[data-a="cover:description"]');
            const longer = `${original || 'Back-cover copy.'} ${'A further sentence typed into the box, word by word. '.repeat(4)}`.trim();

            await copy.fill('');
            await copy.pressSequentially(longer.slice(0, 120), { delay: 5 });
            await copy.fill(longer);

            const after = await box.boundingBox();
            expect(Math.abs(after.x - before.x)).toBeLessThan(1);
            expect(Math.abs(after.y - before.y)).toBeLessThan(1);
            expect(Math.abs(after.width - before.width)).toBeLessThan(1);
            expect(after.height).toBeGreaterThan(before.height);
            expect(await sizeInput.inputValue()).toBe(sizeBefore);
            await settled(page, id, (w) => w.texts.description === longer);

            // A logo, uploaded and placed.
            await page.locator('[data-a="cover:picture-upload"]').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: LOGO });
            await expect(page.locator('[data-a="cover:canvas"] svg[data-layer^="image-"]')).toBeAttached({ timeout: 30_000 });
            await settled(page, id, (w) => w.layers.back?.items?.some((l) => l.source === 'image'));
            await expect(page.locator('[data-a="cover:picture-inspector"]')).toBeVisible();

            // Dropped on the barcode box, a layer is moved clear of it.
            const barcode = page.locator('[data-a="cover:nogo:barcode"] rect');
            const target = await barcode.boundingBox();
            const logo = await page.locator('[data-a="cover:canvas"] svg[data-layer^="image-"]').boundingBox();
            await page.mouse.move(logo.x + logo.width / 2, logo.y + logo.height / 2);
            await page.mouse.down();
            await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 10 });
            await page.mouse.up();
            await expect(page.locator('[data-a="cover:notice"]')).toContainText('barcode');
            await settled(page, id, (w) => !w.warnings.some((x) => x.kind === 'barcode'));
            await shot(page, `back-editor-${size.name}`);

            // --- The spine ------------------------------------------------
            await page.click('[data-a="cover:view:spine"]');
            await page.click('[data-a="cover:spine-direction:btt"]');
            await settled(page, id, (w) => w.spine.direction === 'btt');
            await expect(page.locator('[data-a="cover:spine-direction:btt"]')).toHaveAttribute('aria-checked', 'true');
            await page.click('[data-a="cover:customise-spine"]');
            await expect(page.locator('[data-a="cover:canvas"]')).toBeVisible();
            await settled(page, id, (w) => w.layers.spine?.items?.some((l) => l.source === 'title'));
            await expect(page.locator('[data-a="cover:canvas"] g[data-layer="title"] tspan').first()).toBeAttached({ timeout: 60_000 });
            await shot(page, `spine-editor-${size.name}`);

            // --- The full wrap ------------------------------------------------
            await page.click('[data-a="cover:view:wrap"]');
            await expect(page.locator('[data-a="cover:wrap"]')).toBeVisible();
            await expect(page.locator('[data-a="cover:wrap-barcode"]')).toBeAttached();
            await expect(page.locator('[data-a="cover:wrap-fold"]')).toHaveCount(2);
            const preview = await page.locator('[data-a="cover:wrap-image"]').getAttribute('href');
            expect((await page.request.get(preview)).status()).toBe(200);
            await page.waitForTimeout(1500);
            await shot(page, `full-wrap-${size.name}`);

            // --- Reload: all of it is still there -----------------------------
            await page.reload();
            await openCover(page, id, size.phone);
            await page.click('[data-a="cover:view:back"]');
            await expect(page.locator('[data-a="cover:layers"]')).toContainText('logo.png');
            await expect(page.locator('[data-a="cover:layers"]')).toContainText('Back-cover copy');
            await page.click('[data-a="cover:view:spine"]');
            await expect(page.locator('[data-a="cover:spine-direction:btt"]')).toHaveAttribute('aria-checked', 'true');
            await expect(page.locator('[data-a="cover:canvas"]')).toBeVisible();

            // Leave the book as it was for the next test.
            await page.request.delete(`/books/${id}/cover/back-layers`, { headers });
            await page.request.delete(`/books/${id}/cover/spine-layers`, { headers });
            await page.request.patch(`/books/${id}/cover`, { data: { spine_direction: null, description: original || null }, headers });

            expect(errors).toEqual([]);
        });
    });
}
