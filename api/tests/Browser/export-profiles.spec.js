import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { flagshipBookId, signIn, watchForErrors } from './helpers.js';

/**
 * Export profiles on Export & Publish (ADR-00XX — Export profiles), on a
 * desktop and on a phone: the Sample chip, a file made with it appearing
 * labelled in the history, the Customise panel with a watermark and notes
 * at the end of the book saved as a profile of the book, and the saved
 * chip still there after a reload. Nothing wider than the screen and no
 * console errors.
 *
 * The EPUB renders on the queue worker; its row is labelled from the
 * moment it is queued, and the test drains the queue with `queue:work
 * --stop-when-empty` in this checkout (as printkit.spec.js does) to see it
 * ready, so the server under test must use the same database.
 */
test.describe.configure({ timeout: 300_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function drainQueue() {
    execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', 'queue:work', '--stop-when-empty', '--timeout=600'], {
        cwd: ROOT,
        stdio: 'inherit',
        shell: process.platform === 'win32',
        env: process.env,
        timeout: 280_000,
    });
}

const SHOTS = process.env.EXPORT_SHOTS;

async function shot(page, name) {
    if (!SHOTS) return;

    mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function profiles(page, size) {
    const errors = watchForErrors(page);
    // Nothing left rendering from an earlier run.
    drainQueue();
    await signIn(page);
    const id = await flagshipBookId(page);

    await page.goto(`/books/${id}/edit?pane=export`);
    const panel = page.locator('[data-a="export-profiles"]');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await panel.scrollIntoViewIfNeeded();

    // Full book is chosen until something else is.
    await expect(page.locator('[data-a="export-profile:full"]')).toHaveAttribute('aria-checked', 'true');

    // The Sample chip, by keyboard.
    await page.locator('[data-a="export-profile:sample"]').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-a="export-profile:sample"]')).toHaveAttribute('aria-checked', 'true');
    await expect(page.locator('[data-a="export-profile:summary"]')).toContainText('First 2 chapters');
    await expect(page.locator('[data-a="export-profile:summary"]')).toContainText('Not for stores');
    await shot(page, `${size}-sample-chip`);

    // Make the EPUB with it: labelled in the history at once.
    await page.click('[data-a="publish:generate:epub"]');
    const row = page.locator('[data-a^="publish:label:"]', { hasText: 'EPUB · Sample · ch. 1–2' }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await expect(row).toContainText('Not for stores');

    // Rendered by the worker, it is a download, still labelled.
    drainQueue();
    await page.reload();
    const ready = page.locator('tr', { has: page.locator('[data-a^="publish:label:"]', { hasText: 'EPUB · Sample · ch. 1–2' }) }).first();
    await expect(ready.locator('[data-a^="publish:download:"]')).toBeVisible({ timeout: 30_000 });
    await ready.scrollIntoViewIfNeeded();
    await shot(page, `${size}-sample-in-history`);
    await page.locator('[data-a="export-profile:sample"]').click();

    // Customise: a watermark and the notes at the end, saved as a profile.
    await panel.scrollIntoViewIfNeeded();
    await page.click('[data-a="export-profile:customise"]');
    await expect(page.locator('[data-a="export-profile:panel"]')).toBeVisible();
    await page.fill('[data-a="export-profile:watermark"]', 'For the jury only');
    await page.check('[data-a="export-profile:notes-book"]');
    await expect(page.locator('[data-a="export-profile:summary"]')).toContainText('watermark “For the jury only”');
    await expect(page.locator('[data-a="export-profile:summary"]')).toContainText('notes at the end of the book');

    // Page-foot notes are offered, disabled, with the reason.
    await expect(page.locator('[data-a="export-profile:panel"]')).toContainText('Notes at the foot of the page need a renderer');

    const name = `Jury ${size} ${Date.now() % 100000}`;
    await page.fill('[data-a="export-profile:name"]', name);
    await shot(page, `${size}-customise`);
    await page.click('[data-a="export-profile:save"]');

    const saved = page.locator('[data-a^="export-profile:saved:"]', { hasText: name });
    await expect(saved).toHaveAttribute('aria-checked', 'true');

    // Still there after a reload.
    await page.reload();
    await expect(page.locator('[data-a="export-profiles"]')).toBeVisible({ timeout: 30_000 });
    const again = page.locator('[data-a^="export-profile:saved:"]', { hasText: name });
    await expect(again).toBeVisible();
    await again.click();
    await expect(page.locator('[data-a="export-profile:summary"]')).toContainText('watermark “For the jury only”');
    await again.scrollIntoViewIfNeeded();
    await shot(page, `${size}-saved-after-reload`);

    // Nothing wider than the screen.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    // Tidy up: delete the profile.
    await page.click('[data-a="export-profile:customise"]');
    page.once('dialog', (dialog) => dialog.accept());
    await page.click('[data-a="export-profile:delete"]');
    await expect(page.locator('[data-a^="export-profile:saved:"]', { hasText: name })).toHaveCount(0);

    expect(errors).toEqual([]);
}

test.describe('desktop', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('export profiles at 1440 px', async ({ page }) => {
        await profiles(page, '1440');
    });
});

test.describe('phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('export profiles at 390 px', async ({ page }) => {
        await profiles(page, '390');
    });
});
