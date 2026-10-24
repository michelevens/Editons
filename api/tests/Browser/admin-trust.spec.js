import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * Trust & operations: the public status page and "Report this book" for a
 * signed-out reader, the report reaching the moderation queue, and every
 * new console page on a desktop and at 390px.
 *
 * Runs against a server with direct sales on over the FAKE gateway
 * (COMMERCE_ENABLED=true, COMMERCE_GATEWAY=fake, QUEUE_CONNECTION=sync), as
 * storefront.spec.js does. The staff account is a throwaway made with
 * `php artisan platform:admin` in the suite's throwaway database; its
 * address contains "e2e". The listing taken down here is restored before
 * the test ends.
 */
test.describe.configure({ mode: 'serial', timeout: 600_000 });
test.use({ actionTimeout: 60_000 });
// The machine running this may be busy and php -S serves one request at a time.
const expectSlow = expect.configure({ timeout: 60_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ADMIN = { email: `e2e-trust-${Date.now()}@editons.test`, password: null, secret: null, lastStep: 0 };
const PAGES = ['catalog', 'moderation', 'security', 'logs', 'data-health', 'status', 'offboarding', 'master-data', 'releases'];
let productPath = null;

function artisan(args) {
    return execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', shell: process.platform === 'win32', env: process.env });
}

/** RFC 6238, as the authenticator app would compute it. */
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    const bytes = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hmac = createHmac('sha1', bytes).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;

    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

const step = () => Math.floor(Date.now() / 30000);

async function signInAdmin(page) {
    while (step() <= ADMIN.lastStep) await page.waitForTimeout(1000);
    await page.goto('/login');
    await page.fill('#email', ADMIN.email);
    await page.fill('#password', ADMIN.password);
    await page.click('button[type="submit"]');

    if (!ADMIN.secret) {
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await page.goto('/admin');
        await page.fill('[data-a="2fa:password"]', ADMIN.password);
        await page.click('[data-a="2fa:enable"]');
        ADMIN.secret = (await page.locator('[data-a="2fa:secret"]').innerText()).trim();
        ADMIN.lastStep = step();
        await page.fill('[data-a="2fa:code"]', totp(ADMIN.secret));
        await page.click('[data-a="2fa:confirm"]');
        await page.click('[data-a="admin:2fa:continue"]');

        return;
    }

    await page.waitForURL(/two-factor-challenge/);
    ADMIN.lastStep = step();
    await page.fill('#code', totp(ADMIN.secret));
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes('two-factor'));
}

async function noSideScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

test.beforeAll(() => {
    const out = artisan(['platform:admin', ADMIN.email, '--role=owner', '--no-interaction']);
    ADMIN.password = out.match(/shown once[^\n]*\n\s*(\S+)/)?.[1];
    if (!ADMIN.password) throw new Error(`platform:admin printed no password:\n${out}`);
});

test('the public status page and its JSON need no account', async ({ page, request }) => {
    const errors = watchForErrors(page);

    await page.goto('/status');
    await expectSlow(page.locator('[data-a="status:headline"]')).toBeVisible();
    await expectSlow(page.locator('[data-a="status:components"] li')).toHaveCount(5);
    await expectSlow(page.locator('[data-a="status:component:app"]')).toContainText('Operational');

    const json = await (await request.get('/status.json')).json();
    expect(json.status).toBe('ok');
    expect(json.components.map((c) => c.key)).toEqual(['app', 'api', 'exports', 'email', 'storefront']);

    expect(errors).toEqual([]);
});

test('a signed-out reader reports a book from its store page', async ({ page, browser }, testInfo) => {
    await signIn(page);
    const id = await firstBookId(page);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const exported = await page.request.post(`/books/${id}/exports`, { data: { format: 'epub' }, headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' } });
    expect([200, 202]).toContain(exported.status());

    await page.goto(`/books/${id}/edit?pane=distribution`);
    await expectSlow(page.locator('[data-a="store:product"]')).toBeVisible();
    await page.fill('[data-a="store:price"]', '6.00');
    await page.check('[data-a="store:format:epub"]');
    await page.uncheck('[data-a="store:format:pdf"]');
    await page.click('[data-a="store:publish"]');
    const link = page.locator('[data-a="store:link"]');
    await expectSlow(link).toBeVisible();
    productPath = new URL(await link.getAttribute('href')).pathname;

    const reader = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await reader.newPage();
    const errors = watchForErrors(shop);

    await shop.goto(productPath);
    await shop.click('[data-a="store:report"]');
    await shop.check('[data-a="store:report:copyright"]');
    await shop.fill('[data-a="store:report:details"]', 'E2E report: chapter two is copied.');
    await shop.click('[data-a="store:report:send"]');
    await expectSlow(shop.locator('[data-a="store:report:sent"]')).toBeVisible();

    expect(errors).toEqual([]);
    await reader.close();
});

test('staff see the report, take the listing down and put it back, and every page renders', async ({ page, browser }, testInfo) => {
    const errors = watchForErrors(page);
    await signInAdmin(page);

    await page.click('[data-a="admin:nav:moderation"]');
    const report = page.locator('[data-a^="admin:report:"]', { hasText: 'E2E report: chapter two is copied.' }).first();
    await expectSlow(report).toBeVisible();
    const reportId = (await report.getAttribute('data-a')).split(':')[2];

    await page.click(`[data-a="admin:report:${reportId}:take-down"]`);
    await page.fill(`[data-a="admin:report:${reportId}:take-down:reason"]`, 'E2E: confirmed copy');
    await page.click(`[data-a="admin:report:${reportId}:take-down:confirm"]`);
    await expectSlow(page.locator('[data-a="platform:notice"]')).toContainText('taken down');

    const reader = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    expect((await (await reader.newPage()).goto(productPath)).status()).toBe(404);
    await reader.close();

    await page.goto('/admin/catalog/listings?state=taken_down');
    const listing = page.locator('[data-a^="admin:listing:"][data-a$=":restore"]').first();
    const restoreKey = await listing.getAttribute('data-a');
    await listing.click();
    await page.fill(`[data-a="${restoreKey}:reason"]`, 'E2E: restoring after the test');
    await page.click(`[data-a="${restoreKey}:confirm"]`);
    await expectSlow(page.locator('[data-a="platform:notice"]')).toContainText('restored');

    for (const key of PAGES) {
        await page.click(`[data-a="admin:nav:${key}"]`);
        await expectSlow(page.locator('h1:visible').first()).toBeVisible();
    }

    await page.goto('/admin/data-health');
    await page.click('[data-a="admin:health:run"]');
    await expectSlow(page.locator('[data-a="admin:health:checks"] > li')).toHaveCount(11);

    expect(errors).toEqual([]);
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('the status page fits 390px', async ({ page }) => {
        await page.goto('/status');
        await expectSlow(page.locator('[data-a="status:components"]')).toBeVisible();
        await noSideScroll(page, 390);
    });

    test('every trust page opens from the drawer and fits 390px', async ({ page }) => {
        const errors = watchForErrors(page);
        await signInAdmin(page);
        await page.goto('/admin');

        for (const key of PAGES) {
            await page.click('[data-a="admin:menu"]');
            await page.locator(`[data-a="admin:nav:${key}"]:visible`).click();
            await expectSlow(page.locator('h1:visible').first()).toBeVisible();
            await noSideScroll(page, 390);
        }

        expect(errors).toEqual([]);
    });
});
