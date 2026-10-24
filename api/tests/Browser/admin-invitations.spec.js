import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { watchForErrors } from './helpers.js';

/**
 * Inviting an author from the console, then joining from the emailed link,
 * on a desktop and at 390px.
 *
 * The server under test must run with the log mailer and a sync queue
 * (MAIL_MAILER=log, QUEUE_CONNECTION=sync, LOG_CHANNEL=single): the
 * invitation is read back from storage/logs/laravel.log, the only place the
 * link exists. Every address is a throwaway on the reserved .test domain,
 * and the staff account is made with `php artisan platform:admin` in the
 * suite's throwaway database, as admin-business.spec.js does.
 */
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const LOG = resolve(ROOT, 'storage', 'logs', 'laravel.log');
const RUN = Date.now();
const ADMIN = { email: `e2e-invites-${RUN}@editons.test`, password: null, secret: null, lastStep: 0 };

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

/**
 * The newest /join/ link mailed to this address, from the log mailer's
 * output. The message is MIME: undo quoted-printable soft breaks first.
 */
async function joinLinkFor(email) {
    for (let attempt = 0; attempt < 20; attempt++) {
        if (existsSync(LOG)) {
            const raw = readFileSync(LOG, 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
            const messages = raw.split(/(?=^\[\d{4}-\d{2}-\d{2})/m).filter((m) => m.includes(`To: ${email}`) || m.includes(`<${email}>`));
            const last = messages[messages.length - 1];
            const match = last?.match(/https?:\/\/[^\s"<>]+\/join\/[A-Za-z0-9_-]{48}/g);
            if (match) return new URL(match[match.length - 1]).pathname;
        }
        await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error(`No invitation for ${email} in ${LOG}`);
}

async function noSideScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

/** Open the link in a fresh browser (no staff session), register, and check what arrived. */
async function join(browser, path, { email, viewport, credits }) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = watchForErrors(page);

    await page.goto(path);
    await expect(page.locator('[data-a="join:form"]')).toBeVisible();
    await expect(page.locator('[data-a="join:email"]')).toHaveValue(email);
    await expect(page.locator('[data-a="join:email"]')).toHaveAttribute('readonly', '');
    await expect(page.locator('[data-a="join:perks"]')).toContainText(`${credits} AI credits`);
    if (viewport.width <= 390) await noSideScroll(page, viewport.width);

    await page.fill('#password', 'a-long-passphrase-for-e2e');
    await page.fill('#password_confirmation', 'a-long-passphrase-for-e2e');
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/join'));

    // The perks are on the account: the welcome in the inbox says what came.
    await page.goto('/inbox');
    await expect(page.getByText(/your invitation is accepted/i)).toBeVisible();
    await expect(page.getByText(new RegExp(`${credits} AI credits are in your account`))).toBeVisible();
    if (viewport.width <= 390) await noSideScroll(page, viewport.width);

    // The link is spent.
    await page.goto(path);
    await expect(page.locator('[data-a="join:closed:accepted"]')).toBeVisible();

    expect(errors).toEqual([]);
    await context.close();
}

test.beforeAll(() => {
    const out = artisan(['platform:admin', ADMIN.email, '--role=owner', '--no-interaction']);
    ADMIN.password = out.match(/shown once[^\n]*\n\s*(\S+)/)?.[1];
    if (!ADMIN.password) throw new Error(`platform:admin printed no password:\n${out}`);
});

test('desktop: invite one author with perks, join from the emailed link, the console shows it accepted', async ({ page, browser }) => {
    const errors = watchForErrors(page);
    const invitee = `e2e-invitee-${RUN}@editons.test`;
    await signInAdmin(page);

    await page.click('[data-a="admin:nav:invitations"]');
    await expect(page.locator('[data-a="admin:invitations:counts"]')).toBeVisible();

    await page.click('[data-a="admin:invite"]');
    await page.fill('[data-a="admin:invite:email"]', invitee);
    await page.fill('[data-a="admin:invite:name"]', 'Nadège E2E');
    await page.fill('[data-a="admin:invite:note"]', 'We met at the <b>book fair</b>.');
    await page.selectOption('[data-a="admin:invite:plan"]', 'author');
    await page.fill('[data-a="admin:invite:months"]', '3');
    await page.fill('[data-a="admin:invite:credits"]', '250');
    await page.click('[data-a="admin:invite:confirm"]');

    await expect(page.locator('[data-a="admin:invitation"]')).toContainText(invitee);
    await expect(page.locator('[data-a="admin:invitation"]')).toContainText('pending');
    await expect(page.locator('[data-a="admin:invitation:timeline"]')).toContainText('Invited');

    // Inviting the same address again is refused, with the way to resend.
    await page.click('[data-a="admin:nav:invitations"]');
    await page.click('[data-a="admin:invite"]');
    await page.fill('[data-a="admin:invite:email"]', invitee);
    await expect(page.locator('[data-a="admin:invite:check"]')).toContainText('Already invited');
    await page.keyboard.press('Escape');

    const path = await joinLinkFor(invitee);
    await join(browser, path, { email: invitee, viewport: { width: 1280, height: 900 }, credits: 250 });

    await page.goto('/admin/invitations?status=accepted');
    await page.getByRole('link', { name: new RegExp(invitee) }).click();
    await expect(page.locator('[data-a="admin:invitation"]')).toContainText('accepted');
    await expect(page.locator('[data-a="admin:invitation:applied"]')).toContainText('250 credits');
    await expect(page.locator('[data-a="admin:invitation:account"]')).toBeVisible();
    await expect(page.locator('[data-a="admin:invitation:timeline"]')).toContainText('Accepted');

    expect(errors).toEqual([]);
});

test('390px: invite a pasted list with a preview, join from one link, no sideways scroll', async ({ page, browser }) => {
    const errors = watchForErrors(page);
    await page.setViewportSize({ width: 390, height: 844 });
    const first = `e2e-list-a-${RUN}@editons.test`;
    const second = `e2e-list-b-${RUN}@editons.test`;
    await signInAdmin(page);

    await page.goto('/admin');
    await page.click('[data-a="admin:menu"]');
    await page.locator('[data-a="admin:nav:invitations"]:visible').click();
    await expect(page.locator('[data-a="admin:invitations:counts"]')).toBeVisible();
    await noSideScroll(page, 390);

    await page.click('[data-a="admin:invite-list"]');
    await page.fill('[data-a="admin:invite-list:text"]', `email,name,language\n${first},List A,fr\n${second},List B\n${first}\nnot-an-email`);
    await page.click('[data-a="admin:invite-list:preview"]');
    await expect(page.locator('[data-a="admin:invite-list:summary"]')).toContainText('2 of 4 can be invited');
    await expect(page.locator('[data-a="admin:invite-list:rows"]')).toContainText('Duplicate');
    await expect(page.locator('[data-a="admin:invite-list:rows"]')).toContainText('Invalid');
    await page.fill('[data-a="admin:invite-list:credits"]', '75');
    await page.click('[data-a="admin:invite-list:send"]');

    // Two emails go out with the request (sync queue): give it time.
    await expect(page.locator('[data-a="admin:invite-list:send"]')).toHaveCount(0, { timeout: 90_000 });
    await expect(page.getByRole('link', { name: new RegExp(first) })).toBeVisible();
    await expect(page.getByRole('link', { name: new RegExp(second) })).toBeVisible();
    await noSideScroll(page, 390);

    const path = await joinLinkFor(second);
    await join(browser, path, { email: second, viewport: { width: 390, height: 844 }, credits: 75 });

    await page.goto('/admin/invitations?status=accepted');
    await expect(page.getByRole('link', { name: new RegExp(second) })).toBeVisible();
    await noSideScroll(page, 390);

    expect(errors).toEqual([]);
});
