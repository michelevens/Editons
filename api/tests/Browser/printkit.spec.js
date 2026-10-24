import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * The print kit on a phone: the print checks per service, the price and
 * royalty calculator, and a print cover from the click to the PDF.
 *
 * The cover renders on the queue worker (ADR-0018); the test drains the queue
 * with `queue:work --stop-when-empty` in this checkout, so the server under test must
 * use the same database (as global-setup.js requires anyway).
 */
test.describe.configure({ timeout: 300_000 });
test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

test('checks, prices and a print cover on a phone', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);

    await page.goto(`/books/${id}/edit?pane=export`);
    const kit = page.locator('[data-a="kit"]');
    await expect(kit).toBeVisible({ timeout: 30_000 });

    // Nothing wider than the phone.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    // The checks, per service, by keyboard as well as touch.
    await expect(page.locator('[data-a="kit:check:kdp"] [data-a="kit:item:margins"]')).toBeVisible();
    await page.locator('[data-a="kit:service:ingram"]').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-a="kit:check:ingram"] [data-a="kit:item:isbn"]')).toBeVisible();

    // The calculator answers the price typed.
    await page.locator('[data-a="kit:price:print"]').fill('14.99');
    await page.locator('[data-a="kit:price:ebook"]').fill('4.99');
    await expect(page.locator('[data-a="kit:royalty:kdp_paperback"]')).toContainText('a copy', { timeout: 15_000 });
    await expect(page.locator('[data-a="kit:royalty:apple_books"]')).toContainText('$3.49 a copy');
    await expect(page.locator('[data-a="kit:royalty:amazon_kdp"]')).toContainText('70%');

    // The ebook stores' own needs are listed.
    await expect(page.locator('[data-a="kit:store:amazon_kdp"]')).toBeVisible();

    // The cover preview is a real picture of the wrap.
    const preview = page.locator('[data-a="kit:cover:preview"]');
    await preview.scrollIntoViewIfNeeded();
    await expect.poll(() => preview.evaluate((img) => img.complete && img.naturalWidth), { timeout: 30_000 }).toBeGreaterThan(300);

    // IngramSpark asks for its spine width rather than guessing it.
    await page.selectOption('[data-a="kit:cover:service"]', 'ingram');
    await expect(page.locator('[data-a="kit:cover:spine"]')).toBeVisible();
    await expect(page.locator('[data-a="kit:cover:make"]')).toBeDisabled();
    await page.selectOption('[data-a="kit:cover:service"]', 'kdp');

    // Make the KDP cover, let one worker job render it, download it.
    await page.click('[data-a="kit:cover:make"]');
    await expect(page.getByText('Print cover (full wrap) · Amazon KDP').first()).toBeVisible({ timeout: 20_000 });

    execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', 'queue:work', '--stop-when-empty', '--timeout=600'], {
        cwd: ROOT,
        stdio: 'inherit',
        shell: process.platform === 'win32',
        env: process.env,
        timeout: 280_000,
    });

    await page.reload();
    const row = page.locator('tr', { hasText: 'Print cover (full wrap) · Amazon KDP' }).first();
    const link = row.locator('[data-a^="publish:download:"]');
    await expect(link).toBeVisible({ timeout: 30_000 });

    const response = await page.request.get(await link.getAttribute('href'));
    expect(response.status()).toBe(200);
    expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-');

    expect(errors).toEqual([]);
});

test('ISBN source, rights declaration and AI answers on a phone', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);
    const id = await firstBookId(page);

    await page.goto(`/books/${id}/edit?pane=rights`);
    await expect(page.locator('[data-a="rights:isbn-plan"]')).toBeVisible({ timeout: 30_000 });

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    // A store-assigned paperback ISBN, chosen by keyboard.
    await page.locator('[data-a="rights:isbn-plan:paperback:store"]').focus();
    await page.keyboard.press('Space');
    await expect(page.locator('[data-a="rights:isbn-plan:paperback:store"]')).toBeChecked();

    // The declaration needs the author's own confirmation.
    await page.click('[data-a="rights:material:add"]');
    await page.fill('[data-a="rights:material:0:description"]', 'Map, page 12');
    await page.selectOption('[data-a="rights:material:0:permission"]', 'licence');
    await expect(page.locator('[data-a="rights:declaration:confirm"]')).toBeDisabled();
    await page.check('[data-a="rights:declaration:holds"]');
    await page.click('[data-a="rights:declaration:confirm"]');
    await expect(page.locator('[data-a="rights:declaration:status"]')).toContainText('Confirmed by');

    // AI answers start from the suggestion and are confirmed by the author.
    await expect(page.locator('[data-a="rights:ai:text"]')).toContainText('Suggested');
    await page.check('[data-a="rights:ai:sure"]');
    await page.click('[data-a="rights:ai:confirm"]');
    await expect(page.locator('[data-a="rights:ai:status"]')).toContainText('At KDP');

    await expect(page.locator('[data-a="rights:deposit"]')).toBeVisible();

    expect(errors).toEqual([]);
});
