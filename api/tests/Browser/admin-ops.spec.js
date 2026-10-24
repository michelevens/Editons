import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { signIn, watchForErrors } from './helpers.js';

/**
 * The console's phase 2 pages (support, AI usage, jobs & health, email log,
 * errors, feature flags) on a desktop and at 390px, and the author's Help
 * flow from the account menu and the More sheet to a reply from support.
 *
 * Like admin.spec.js, the staff account is a throwaway made with
 * `php artisan platform:admin` in the suite's throwaway database; its
 * address contains "e2e". The AI draft runs on the fake drafter: no
 * provider is called.
 */
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ADMIN = { email: `e2e-ops-${Date.now()}@editons.test`, password: null, secret: null, lastStep: 0 };
const SUBJECT = `E2E help ${Date.now()}`;

function artisan(args) {
    return execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', ...args], {
        cwd: ROOT,
        encoding: 'utf8',
        shell: process.platform === 'win32',
        env: process.env,
    });
}

/** RFC 6238, as the authenticator app would compute it. */
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
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
        // First time: set up two-factor, which the console requires.
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

test('an author asks for help from the account menu', async ({ page }) => {
    const errors = watchForErrors(page);
    await signIn(page);

    await page.goto('/books');
    await page.click('[data-a="user:menu"]');
    await page.click('[data-a="user:support"]');
    await expect(page).toHaveURL(/\/support$/);

    await page.fill('[data-a="support:subject"]', SUBJECT);
    await page.fill('[data-a="support:body"]', 'My PDF export stops at chapter twelve every time.');
    await page.click('[data-a="support:send"]');

    await expect(page).toHaveURL(/\/support\/\d+$/);
    await expect(page.locator('[data-a="support:messages"] li')).toHaveCount(1);
    await expect(page.locator('h1')).toContainText(SUBJECT);

    expect(errors).toEqual([]);
});

test('staff answer it, with an AI draft, and every new console page renders', async ({ page }) => {
    const errors = watchForErrors(page);
    await signInAdmin(page);

    await page.click('[data-a="admin:nav:support"]');
    await page.locator('[data-a^="admin:support:ticket:"]', { hasText: SUBJECT }).click();
    await expect(page.locator('[data-a="admin:support:context"]')).toContainText('Plan');

    await page.click('[data-a="admin:support:draft"]');
    await expect(page.locator('[data-a="admin:support:body"]')).toHaveValue(/Thank you for writing/);
    await page.fill('[data-a="admin:support:body"]', 'Narrow the wide table in chapter twelve and export again.');
    await page.click('[data-a="admin:support:send"]');
    await expect(page.locator('[data-a="platform:notice"]')).toContainText('Reply sent');
    await expect(page.locator('[data-a="admin:support:messages"] li')).toHaveCount(2);

    await page.click('[data-a="admin:nav:ai"]');
    await expect(page.locator('[data-a="admin:ai:windows"]')).toBeVisible();
    await expect(page.locator('[data-a="admin:ai:switches"]')).toContainText('Editorial reviews');

    await page.click('[data-a="admin:nav:operations"]');
    await expect(page.locator('[data-a="admin:ops:health:database"]')).toContainText('ok');
    await expect(page.locator('[data-a="admin:ops:jobs"]')).toContainText('exports:expire');

    await page.click('[data-a="admin:nav:mail"]');
    await expect(page.locator('[data-a="admin:mail:no-tracking"]')).toBeVisible();

    await page.click('[data-a="admin:nav:errors"]');
    await expect(page.locator('[data-a="admin:errors:list"]')).toBeVisible();

    await page.click('[data-a="admin:nav:features"]');
    await expect(page.locator('[data-a="admin:feature:storefront"]')).toBeVisible();

    expect(errors).toEqual([]);
});

test('the author sees the reply', async ({ page }) => {
    await signIn(page);
    await page.goto('/support');
    await page.locator('[data-a="support:tickets"] a', { hasText: SUBJECT }).click();
    await expect(page.locator('[data-a="support:messages"] li')).toHaveCount(2);
    await expect(page.locator('[data-a="support:messages"]')).toContainText('Narrow the wide table');
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('Help is in the More sheet', async ({ page }) => {
        const errors = watchForErrors(page);
        await signIn(page);

        await page.goto('/books');
        await page.click('[data-a="mobile:more"]');
        await page.click('[data-a="sheet:account:support"]');
        await expect(page).toHaveURL(/\/support$/);
        await expect(page.locator('[data-a="support:form"]')).toBeVisible();
        await noSideScroll(page, 390);

        expect(errors).toEqual([]);
    });

    test('every new console page fits 390px and opens from the drawer', async ({ page }) => {
        const errors = watchForErrors(page);
        await signInAdmin(page);
        await page.goto('/admin');

        for (const key of ['support', 'ai', 'operations', 'mail', 'errors', 'features']) {
            await page.click('[data-a="admin:menu"]');
            await page.locator(`[data-a="admin:nav:${key}"]:visible`).click();
            await expect(page.locator('main h1').first()).toBeVisible();
            await noSideScroll(page, 390);
        }

        expect(errors).toEqual([]);
    });
});
