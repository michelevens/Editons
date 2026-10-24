import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { watchForErrors } from './helpers.js';

/**
 * The console's Business pages (billing, plans, book sales, growth,
 * onboarding) on a desktop and at 390px.
 *
 * Like admin-ops.spec.js, the staff account is a throwaway made with
 * `php artisan platform:admin` in the suite's throwaway database; its
 * address contains "e2e". Nothing here calls Stripe or sends real mail:
 * the server under test runs on the fake gateway and the log or array
 * mailer, and the only email sent is a test broadcast to this throwaway.
 */
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ADMIN = { email: `e2e-biz-${Date.now()}@editons.test`, password: null, secret: null, lastStep: 0 };
const PAGES = ['billing', 'plans', 'sales', 'growth', 'onboarding'];

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

test('every Business page renders from the navigation, with honest empty states', async ({ page }) => {
    const errors = watchForErrors(page);
    await signInAdmin(page);

    await page.click('[data-a="admin:nav:billing"]');
    await expect(page.locator('[data-a="admin:billing:kpis"]')).toBeVisible();
    await expect(page.locator('[data-a="admin:billing:mrr"]')).toContainText(/No paid subscriptions yet|\$/);
    for (const tab of ['subscriptions', 'trials', 'past-due', 'cancellations', 'packs']) {
        await page.click(`[data-a="admin:tab:${tab}"]`);
        await expect(page.locator(`[data-a="admin:tab:${tab}"]`)).toHaveAttribute('aria-current', 'page');
    }

    await page.click('[data-a="admin:nav:plans"]');
    await expect(page.locator('[data-a="admin:plan:author"]')).toBeVisible();
    await page.click('[data-a="admin:plan:author:edit"]');
    await expect(page.locator('[data-a="plan-author:price"]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-a="plan-author:price"]')).toHaveCount(0);

    await page.click('[data-a="admin:nav:sales"]');
    await expect(page.locator('[data-a="admin:sales:tiles"]').first()).toBeVisible();
    await expect(page.locator('[data-a="admin:sales:royalties"]')).toContainText('Stripe Connect is not built');

    await page.click('[data-a="admin:nav:onboarding"]');
    await expect(page.locator('[data-a="admin:onboarding:steps"]')).toContainText('First book');

    await page.click('[data-a="admin:nav:growth"]');
    await expect(page.locator('[data-a="admin:growth:funnel"]')).toBeVisible();
    for (const tab of ['audiences', 'broadcasts', 'discounts', 'lifecycle']) {
        await page.click(`[data-a="admin:tab:${tab}"]`);
        await expect(page.locator(`[data-a="admin:tab:${tab}"]`)).toHaveAttribute('aria-current', 'page');
    }
    await expect(page.locator('[data-a="admin:lifecycle:no_book_3d"]')).toContainText('Off');

    expect(errors).toEqual([]);
});

test('an audience counts live, a broadcast previews and test-sends to its author only', async ({ page }) => {
    const errors = watchForErrors(page);
    await signInAdmin(page);

    await page.goto('/admin/growth/audiences');
    await page.click('[data-a="admin:audience:new"]');
    await page.fill('[data-a="admin:audience:name"]', `E2E free plan ${Date.now()}`);
    await page.click('[data-a="admin:audience:plan:free"]');
    await expect(page.locator('[data-a="admin:audience:count"]')).toContainText('accounts match');
    await page.click('[data-a="admin:audience:save"]');
    await expect(page.locator('[data-a="platform:notice"]')).toContainText('Audience saved');

    await page.goto('/admin/growth/broadcasts');
    await page.click('[data-a="admin:broadcast:new"]');
    await page.fill('[data-a="admin:broadcast:subject"]', 'E2E draft — never sent');
    await page.fill('[data-a="admin:broadcast:body"]', 'First paragraph.\n\nSecond paragraph.');
    await page.click('[data-a="admin:broadcast:save"]');
    await expect(page.locator('[data-a="platform:notice"]')).toContainText('Draft saved');

    await page.click('[data-a="admin:broadcast:preview"]');
    await expect(page.locator('[data-a="admin:broadcast:preview-frame"]')).toBeVisible();

    await page.click('[data-a="admin:broadcast:test"]');
    await expect(page.locator('[data-a="platform:notice"]')).toContainText(ADMIN.email);

    await page.goto('/admin/growth/discounts');
    await page.click('[data-a="admin:discount:new"]');
    await page.fill('[data-a="admin:discount:code"]', `E2E${Date.now() % 100000}`);
    await page.fill('[data-a="admin:discount:new:reason"]', 'Browser test code');
    await page.click('[data-a="admin:discount:new:confirm"]');
    await expect(page.locator('[data-a="platform:notice"]')).toContainText('created');

    expect(errors).toEqual([]);
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('every Business page opens from the drawer and fits 390px', async ({ page }) => {
        const errors = watchForErrors(page);
        await signInAdmin(page);
        await page.goto('/admin');

        for (const key of PAGES) {
            await page.click('[data-a="admin:menu"]');
            await page.locator(`[data-a="admin:nav:${key}"]:visible`).click();
            await expect(page.locator('main h1').first()).toBeVisible();
            await noSideScroll(page, 390);
        }

        for (const path of ['/admin/billing?tab=subscriptions', '/admin/growth/audiences', '/admin/growth/broadcasts/new', '/admin/growth/discounts', '/admin/growth/lifecycle']) {
            await page.goto(path);
            await expect(page.locator('main h1').first()).toBeVisible();
            await noSideScroll(page, 390);
        }

        expect(errors).toEqual([]);
    });
});
