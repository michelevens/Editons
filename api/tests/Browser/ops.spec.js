import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * Operations the owner used to run by shell (ADR — moving books between any
 * accounts; AI-written imports), on a desktop and at 390px:
 *
 *  - the owner moves a throwaway author's book to another throwaway author
 *    from Admin › Accounts › the account (tick, search the target, preview,
 *    reason, confirm);
 *  - an author imports a Markdown manuscript marked as written with an AI
 *    tool, giving its sections types in "Review sections" first.
 *
 * Every account here is a throwaway made in the suite's database; the staff
 * account's address contains "e2e".
 */
test.describe.configure({ mode: 'serial', timeout: 1_200_000 });
test.use({ actionTimeout: 120_000, navigationTimeout: 180_000 });
const expectSlow = expect.configure({ timeout: 120_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SHOTS = process.env.OPS_SHOTS ?? null;
const STAMP = Date.now();
const ADMIN = { email: `e2e-ops-${STAMP}@editons.test`, password: null, secret: null, lastStep: 0 };

const MANUSCRIPT = Buffer.from(
    '# Introduction\n\nWhy this book.\n\n# Part One: Roots\n\n## Chapter 1: Soil\n\nDirt.\n\n## Chapter 2: Water\n\nRain.\n\n# Appendix\n\nTables.\n\n# Glossary\n\nWords.\n',
);

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

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

/** A fresh author with one book; returns the email and the book id. */
async function authorWithBook(browser, baseURL, title) {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();
    const email = await registerFreshAuthor(page);
    const xsrf = decodeURIComponent((await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const created = await page.request.post('/books', { data: { title }, headers: { 'X-XSRF-TOKEN': xsrf }, maxRedirects: 0 });
    const id = Number(created.headers().location?.match(/books\/(\d+)/)?.[1]);
    expect(id).toBeGreaterThan(0);

    return { email, id, page };
}

test.beforeAll(() => {
    const out = artisan(['platform:admin', ADMIN.email, '--role=owner', '--no-interaction']);
    ADMIN.password = out.match(/shown once[^\n]*\n\s*(\S+)/)?.[1];
    if (!ADMIN.password) throw new Error(`platform:admin printed no password:\n${out}`);
});

async function moveFlow(page, browser, baseURL, width, tag) {
    const from = await authorWithBook(browser, baseURL, `E2E Ops Book ${tag} ${STAMP}`);
    const to = await authorWithBook(browser, baseURL, `E2E Ops Target ${tag} ${STAMP}`);

    const errors = watchForErrors(page);
    await signInAdmin(page);

    await page.goto(`/admin/accounts?q=${encodeURIComponent(from.email)}`);
    await page.locator('[data-a="admin:accounts:list"] a').first().click();
    const row = page.locator(`[data-a="admin:account:book:${from.id}"]`);
    await expectSlow(row).toBeVisible();
    await noSideScroll(page, width);

    await row.locator('[data-a="admin:account:book:choose"]').check();
    await page.click('[data-a="admin:account:books:move"]');
    await page.fill('[data-a="admin:account:move:search"]', to.email);
    await page.locator('[data-a="admin:account:move:pick"]').first().click();
    await expect(page.locator('[data-a="admin:account:move:target"]')).toContainText(to.email);
    // A person's account: keeping them as an editor is on by default.
    await expect(page.locator('[data-a="admin:account:move:keep"]')).toBeChecked();
    await page.click('[data-a="admin:account:move:preview"]');
    const plan = page.locator('[data-a="admin:account:move:plan"]');
    await expectSlow(plan).toContainText('ready');
    await expect(plan).toContainText('becomes an editor of the book');
    await noSideScroll(page, width);
    await shot(page, `move-preview-${width}`);

    await page.fill('[data-a="admin:account:move:reason"]', 'E2E: the author handed the book over');
    await page.click('[data-a="admin:account:move:confirm"]');
    await expectSlow(page.locator('[data-a="platform:notice"]')).toContainText('moved');
    await expect(row).toHaveCount(0);
    await shot(page, `move-done-${width}`);

    expect(errors).toEqual([]);

    // The author who gave the book away has room on a free plan again.
    return from;
}

async function importFlow(page, width) {
    const errors = watchForErrors(page);
    await page.goto('/books');
    await page.click('[data-a="books:import"]');

    await page.setInputFiles('[data-a="import:file"]', { name: 'Field_guide.md', mimeType: 'text/markdown', buffer: MANUSCRIPT });
    await page.check('[data-a="import:ai-written"]');
    await page.click('[data-a="import:review"]');

    const types = page.locator('[data-a="import:section-type"]');
    await expectSlow(types).toHaveCount(6);
    await types.nth(0).selectOption('front_matter');
    await types.nth(1).selectOption('part');
    await types.nth(4).selectOption('back_matter');
    await types.nth(5).selectOption('back_matter');
    await noSideScroll(page, width);
    await shot(page, `import-review-${width}`);

    await page.click('[data-a="import:upload"]');
    const report = page.locator('[data-a="import:report"]');
    await expectSlow(report).toContainText('Field guide');
    await expect(report).toContainText('6 chapters imported');
    await shot(page, `import-report-${width}`);

    await page.click('[data-a="import:done"]');
    await page.waitForURL(/\/books\/\d+\/edit/);
    await noSideScroll(page, width);

    expect(errors).toEqual([]);
}

test('desktop: the owner moves a book to another account; an author imports AI-written text with section types', async ({ page, browser }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const from = await moveFlow(page, browser, testInfo.project.use.baseURL, 1440, 'desk');
    await from.page.setViewportSize({ width: 1440, height: 900 });
    await importFlow(from.page, 1440);
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('both flows fit 390px', async ({ page, browser }, testInfo) => {
        const from = await moveFlow(page, browser, testInfo.project.use.baseURL, 390, 'phone');
        await from.page.setViewportSize({ width: 390, height: 844 });
        await importFlow(from.page, 390);
    });
});
