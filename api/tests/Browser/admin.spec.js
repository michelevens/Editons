import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEMO, signIn, watchForErrors } from './helpers.js';

/**
 * The admin console: setting up two-factor, the dashboard, an account,
 * "View as" with its banner (desktop and 390px, above the tab bar), and the
 * maintenance page.
 *
 * The staff account is a throwaway made with `php artisan platform:admin`
 * in the database the server under test uses — which, like the rest of the
 * suite, must be a throwaway database (see global-setup.js). Its address
 * contains "e2e" for the same reason the writer's does.
 */
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ADMIN = { email: `e2e-admin-${Date.now()}@editons.test`, password: null, secret: null, lastStep: 0 };

function artisan(args) {
    return execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', ...args], {
        cwd: ROOT,
        encoding: 'utf8',
        shell: process.platform === 'win32',
        env: process.env,
    });
}

/** RFC 6238, as the authenticator app would compute it. */
function totp(secret, offsetSteps = 0) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const bytes = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offsetSteps));
    const hmac = createHmac('sha1', bytes).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return String(code).padStart(6, '0');
}

const step = () => Math.floor(Date.now() / 30000);

/**
 * Sign the staff account in, through the two-factor challenge. A code is
 * refused for a step already used (replay protection), so wait for a fresh
 * step when the last code was from this one.
 */
async function signInAdmin(page) {
    while (step() <= ADMIN.lastStep) await page.waitForTimeout(1000);
    await page.goto('/login');
    await page.fill('#email', ADMIN.email);
    await page.fill('#password', ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForURL(/two-factor-challenge/);
    ADMIN.lastStep = step();
    await page.fill('#code', totp(ADMIN.secret));
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes('two-factor'));
}

test.beforeAll(() => {
    const out = artisan(['platform:admin', ADMIN.email, '--role=owner', '--no-interaction']);
    ADMIN.password = out.match(/shown once[^\n]*\n\s*(\S+)/)?.[1];
    if (!ADMIN.password) throw new Error(`platform:admin printed no password:\n${out}`);
});

test('staff set up two-factor, then the console opens; View as shows the banner and stops cleanly', async ({ page }) => {
    const errors = watchForErrors(page);

    await page.goto('/login');
    await page.fill('#email', ADMIN.email);
    await page.fill('#password', ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));

    // The account menu links to the console only for staff.
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/two-factor$/);
    await page.fill('[data-a="2fa:password"]', ADMIN.password);
    await page.click('[data-a="2fa:enable"]');
    await expect(page.locator('[data-a="2fa:qr"] svg')).toBeVisible();
    ADMIN.secret = (await page.locator('[data-a="2fa:secret"]').innerText()).trim();
    ADMIN.lastStep = step();
    await page.fill('[data-a="2fa:code"]', totp(ADMIN.secret));
    await page.click('[data-a="2fa:confirm"]');
    await expect(page.locator('[data-a="2fa:recovery-codes"] li')).toHaveCount(8);
    await page.click('[data-a="admin:2fa:continue"]');

    await expect(page.locator('[data-a="admin:tiles"]')).toBeVisible();
    await expect(page.locator('[data-a="admin:mrr-none"]')).toBeVisible();

    // Find the suite's writer and view as them.
    await page.click('[data-a="admin:nav:accounts"]');
    await page.fill('[data-a="admin:accounts:search"]', DEMO.email);
    await page.keyboard.press('Enter');
    await page.waitForURL(/q=/);
    await page.locator('[data-a^="admin:account:"]', { hasText: DEMO.email }).first().click();
    await expect(page.locator('[data-a="admin:account:email"]')).toContainText(DEMO.email);

    await page.click('[data-a="admin:action:impersonate"]');
    await page.fill('[data-a="admin:action:impersonate:reason"]', 'E2E: checking the banner');
    await page.click('[data-a="admin:action:impersonate:confirm"]');

    const banner = page.locator('[data-a="platform:impersonation-banner"]');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Viewing as');
    await expect(page.locator('[data-a="platform:impersonation-time"]')).toContainText(/\d+:\d\d left/);

    // The banner pushes the page down rather than covering its header.
    const bannerBox = await banner.boundingBox();
    const header = await page.locator('header').first().boundingBox();
    expect(header.y).toBeGreaterThanOrEqual(bannerBox.y + bannerBox.height - 1);

    // The console is closed while viewing (refused by the View as guard).
    const response = await page.goto('/admin');
    expect(response.status()).toBe(403);
    await page.goto('/books');

    await page.click('[data-a="platform:stop-viewing"]');
    await expect(page).toHaveURL(/\/admin\/accounts\/\d+$/);
    await expect(banner).toHaveCount(0);
    await expect(page.locator('[data-a="platform:notice"]')).toContainText('Stopped viewing');

    // Both ends are on the audit log.
    await page.goto('/admin/audit?action=impersonation.started');
    await expect(page.locator('[data-a="admin:audit:list"]')).toContainText('impersonation.started');

    expect(errors.filter((e) => !e.includes('403'))).toEqual([]);
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('the console works in a drawer, and the View as banner sits above the tab bar', async ({ page }) => {
        const errors = watchForErrors(page);
        await signInAdmin(page);

        await page.goto('/admin');
        await page.click('[data-a="admin:menu"]');
        await page.locator('[data-a="admin:nav:accounts"]:visible').click();
        await expect(page).toHaveURL(/\/admin\/accounts/);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

        await page.fill('[data-a="admin:accounts:search"]', DEMO.email);
        await page.keyboard.press('Enter');
        await page.waitForURL(/q=/);
        await page.locator('[data-a^="admin:account:"]', { hasText: DEMO.email }).first().click();
        await page.click('[data-a="admin:action:impersonate"]');
        await page.fill('[data-a="admin:action:impersonate:reason"]', 'E2E: phone banner');
        await page.click('[data-a="admin:action:impersonate:confirm"]');

        const banner = page.locator('[data-a="platform:impersonation-banner"]');
        const tabs = page.locator('[data-a="mobile:tabs"]');
        await expect(banner).toBeVisible();
        await expect(tabs).toBeVisible();

        // Both on screen at once: the tab bar has not been pushed off the bottom.
        const tabBox = await tabs.boundingBox();
        expect(tabBox.y + tabBox.height).toBeLessThanOrEqual(844 + 1);
        expect((await banner.boundingBox()).y).toBeLessThan(tabBox.y);
        await expect(page.locator('[data-a="platform:stop-viewing"]')).toBeInViewport();

        await page.click('[data-a="platform:stop-viewing"]');
        await expect(page).toHaveURL(/\/admin\/accounts\/\d+$/);

        expect(errors).toEqual([]);
    });
});

test('maintenance mode: writers get the maintenance page, staff carry on', async ({ page, browser }) => {
    await signInAdmin(page);

    await page.goto('/admin/settings');
    await page.check('[data-a="admin:settings:maintenance_mode"]');
    await page.fill('[data-a="admin:settings:maintenance_message"]', 'E2E maintenance — back soon');
    await page.click('[data-a="admin:settings:save"]');
    await expect(page.locator('[data-a="platform:maintenance-on"]')).toBeVisible();

    const writer = await browser.newContext();
    const other = await writer.newPage();

    try {
        await signIn(other);
        const response = await other.goto('/books');
        expect(response.status()).toBe(503);
        await expect(other.locator('[data-a="maintenance:message"]')).toHaveText('E2E maintenance — back soon');

        // The status endpoint answers during maintenance.
        const status = await other.request.get('/status');
        expect((await status.json()).status).toBe('maintenance');
    } finally {
        await page.goto('/admin/settings');
        await page.uncheck('[data-a="admin:settings:maintenance_mode"]');
        await page.click('[data-a="admin:settings:save"]');
        await expect(page.locator('[data-a="platform:maintenance-on"]')).toHaveCount(0);
        await writer.close();
    }

    const after = await (await browser.newContext()).newPage();
    await signIn(after);
    expect((await after.goto('/books')).status()).toBe(200);
});
