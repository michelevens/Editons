import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * The House catalog (ADR — house catalog): a throwaway author writes a book,
 * the owner moves it into a throwaway house account from the console
 * (preview, then apply), runs a bulk action on it, and opens it in the
 * studio as themselves — banner included — on a desktop and at 390px.
 *
 * The staff account and the house account are throwaways made with
 * `php artisan platform:admin` / `house:create` in the suite's throwaway
 * database; their names contain "e2e". Nothing here touches the shared e2e
 * account's books.
 */
test.describe.configure({ mode: 'serial', timeout: 1_200_000 });
test.use({ actionTimeout: 120_000, navigationTimeout: 180_000 });
const expectSlow = expect.configure({ timeout: 120_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const STAMP = Date.now();
const ADMIN = { email: `e2e-house-${STAMP}@editons.test`, password: null, secret: null, lastStep: 0 };
const HOUSE = `e2e-house-${STAMP}`;
let bookId = null;

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

    artisan(['house:create', HOUSE, '--name=E2E-House-Imprint', '--reason=E2E-house-catalog-test', '--no-interaction']);
});

test('an author writes a book that the owner moves into the house account', async ({ page, browser }, testInfo) => {
    // The author: a fresh throwaway account with one book of its own.
    const author = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const writer = await author.newPage();
    await registerFreshAuthor(writer);
    const xsrf = decodeURIComponent((await author.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const created = await writer.request.post('/books', { data: { title: `E2E House Book ${STAMP}` }, headers: { 'X-XSRF-TOKEN': xsrf }, maxRedirects: 0 });
    bookId = Number(created.headers().location?.match(/books\/(\d+)/)?.[1]);
    expect(bookId).toBeGreaterThan(0);
    // The author's context is left to the browser's teardown: closing it
    // waits on requests a slow php -S may still be serving.

    const errors = watchForErrors(page);
    await signInAdmin(page);

    await page.goto('/admin');
    await page.click('[data-a="admin:nav:house"]');
    await expectSlow(page.locator('[data-a="admin:house:accounts"]')).toContainText('E2E-House-Imprint');

    await page.click('[data-a="admin:house:transfer"]');
    await page.fill('[data-a="admin:house:transfer:ids"]', String(bookId));
    await page.selectOption('#house-to', HOUSE);
    await page.click('[data-a="admin:house:transfer:preview"]');
    await expectSlow(page.locator('[data-a="admin:house:transfer:plan"]')).toContainText('ready');
    await page.fill('[data-a="admin:house:transfer:reason"]', 'E2E: move to the imprint');
    await page.click('[data-a="admin:house:transfer:apply"]');
    await expectSlow(page.locator('[data-a="platform:notice"]')).toContainText('moved');
    await expectSlow(page.locator(`[data-a="admin:house:book:${bookId}"]`)).toBeVisible();

    expect(errors).toEqual([]);
});

test('a bulk action prices the selected house book, and the studio opens with the house banner', async ({ page }) => {
    const errors = watchForErrors(page);
    await signInAdmin(page);
    await page.goto('/admin/house');

    await page.check(`[data-a="admin:house:select:${bookId}"]`);
    await page.selectOption('[data-a="admin:house:bulk:action"]', 'price');
    await page.fill('[data-a="admin:house:bulk:price"]', '4.99');
    await page.fill('[data-a="admin:house:bulk:reason"]', 'E2E: launch price');
    await page.click('[data-a="admin:house:bulk:run"]');
    await expectSlow(page.locator(`[data-a="admin:house:result:${bookId}"]`)).toContainText('Price set');
    await expectSlow(page.locator(`[data-a="admin:house:book:${bookId}"]`)).toContainText('4.99');

    await page.click(`[data-a="admin:house:open:${bookId}"]`);
    await expectSlow(page.locator('[data-a="editor:house-banner"]')).toContainText('House book — edits are saved as you');

    expect(errors).toEqual([]);
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('the House catalog and a house book in the studio fit 390px', async ({ page }) => {
        const errors = watchForErrors(page);
        await signInAdmin(page);
        await page.goto('/admin');

        await page.click('[data-a="admin:menu"]');
        await page.locator('[data-a="admin:nav:house"]:visible').click();
        await expectSlow(page.locator(`[data-a="admin:house:book:${bookId}"]`)).toBeVisible();
        await noSideScroll(page, 390);

        await page.click(`[data-a="admin:house:open:${bookId}"]`);
        await expectSlow(page.locator('[data-a="editor:house-banner"]')).toBeVisible();
        await noSideScroll(page, 390);

        expect(errors).toEqual([]);
    });
});
