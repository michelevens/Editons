import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ignoring, registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * Écrivain Partners end to end, on a desktop and at 390px: somebody applies,
 * staff approve, the partner sets up their page, a signed-out reader opens
 * it, buys a book through the fake gateway, and the commission is pending in
 * the partner's dashboard and in the console.
 *
 * Runs against a server with direct sales on over the FAKE gateway
 * (COMMERCE_ENABLED=true, COMMERCE_GATEWAY=fake). The seller, their book and
 * the staff account are throwaways made with artisan in the suite's own
 * database; nothing talks to Stripe and no real mail is sent.
 */
test.describe.configure({ mode: 'serial', timeout: 300_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ADMIN = { email: `e2e-partners-${Date.now()}@editons.test`, password: null, secret: null, lastStep: 0 };
let SLUG = null;

function artisan(args) {
    return execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', ...args], {
        cwd: ROOT,
        encoding: 'utf8',
        shell: process.platform === 'win32',
        env: process.env,
    });
}

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

async function noSideScroll(page) {
    const { scroll, width } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, width: window.innerWidth }));
    expect(scroll).toBeLessThanOrEqual(width);
}

test.beforeAll(() => {
    const out = artisan(['platform:admin', ADMIN.email, '--role=owner', '--no-interaction']);
    ADMIN.password = out.match(/shown once[^\n]*\n\s*(\S+)/)?.[1];
    if (!ADMIN.password) throw new Error(`platform:admin printed no password:\n${out}`);

    // A seller with one book on sale, open to partners at 25 %, made by a
    // throwaway script that boots the app (tinker's quoting does not
    // survive a Windows shell).
    const script = join(tmpdir(), `e2e-partner-seller-${Date.now()}.php`);
    writeFileSync(script, String.raw`<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::factory()->create(['email' => 'e2e-seller-'.uniqid().'@editons.test']);
$b = App\Models\Book::factory()->for($u)->create(['title' => 'Philo Terminale E2E', 'author_name' => 'E2E Author']);
$p = new App\Models\Product;
$p->forceFill(['user_id' => $u->id, 'book_id' => $b->id, 'slug' => App\Models\Product::newSlug($b->title), 'price_cents' => 1200, 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published_at' => now()->subMinute(), 'partner_enabled' => true, 'partner_rate_bps' => 2500])->save();
echo 'SLUG='.$p->slug;
`);
    try {
        SLUG = execFileSync(process.env.E2E_PHP ?? 'php', [script], { cwd: ROOT, encoding: 'utf8', env: process.env, shell: process.platform === 'win32' }).match(/SLUG=(\S+)/)?.[1];
    } finally {
        unlinkSync(script);
    }
    if (!SLUG) throw new Error('Could not make the seller’s book.');
});

for (const viewport of [{ name: 'desktop', width: 1440, height: 960 }, { name: 'phone', width: 390, height: 844 }]) {
    test(`apply, approve, set up the page, a reader buys, the commission is pending (${viewport.name})`, async ({ browser }, testInfo) => {
        const baseURL = testInfo.project.use.baseURL;
        const partnerContext = await browser.newContext({ baseURL, viewport });
        const partner = await partnerContext.newPage();
        const errors = watchForErrors(partner);

        // 1. Somebody with a free account applies.
        await registerFreshAuthor(partner);
        await partner.goto('/partners/apply');
        const name = `E2E Lekòl ${viewport.name} ${Date.now()}`;
        await partner.fill('[data-a="partners:apply:name"]', name);
        await partner.click('[data-a="partners:apply:type:school"]');
        await partner.check('[data-a="partners:apply:accept"]');
        await noSideScroll(partner);
        await partner.click('[data-a="partners:apply:send"]');
        await partner.waitForURL(/\/partner(\?|$)/);
        await expect(partner.locator('[data-a="partner:status:applied"]')).toBeVisible();

        // 2. Staff approve it from the queue.
        const adminContext = await browser.newContext({ baseURL, viewport });
        const admin = await adminContext.newPage();
        const adminErrors = watchForErrors(admin);
        await signInAdmin(admin);
        await admin.goto('/admin/partners');
        const card = admin.locator('section', { hasText: name }).first();
        await card.locator('[data-a^="admin:partners:approve:"]').click();
        await admin.click('[data-a$=":confirm"]');
        await expect(admin.locator('[data-a="platform:notice"]')).toContainText('is approved');
        await noSideScroll(admin);

        // 3. The partner puts the book on their page, with a note.
        await partner.goto('/partner?tab=page');
        await partner.fill('[data-a="partner:editor:tagline"]', 'Liv pou klas filo a');
        await partner.selectOption('[data-a="partner:shelf:add"]', SLUG);
        await partner.locator('[data-a="partner:shelf:item"] textarea').fill('Perfect for the bac <3');
        await partner.click('[data-a="partner:editor:save"]');
        await expect(partner.locator('[data-a="platform:notice"]')).toContainText('Your page is saved');
        await noSideScroll(partner);
        const pageUrl = await partner.locator('[data-a="partner:open-page"]').getAttribute('href');

        // 4. A signed-out reader opens the page and buys through it.
        const readerContext = await browser.newContext({ baseURL, viewport });
        const reader = await readerContext.newPage();
        const readerErrors = watchForErrors(reader);
        await reader.goto(new URL(pageUrl).pathname);
        await expect(reader.locator('[data-a="partner:name"]')).toHaveText(name);
        await expect(reader.locator('[data-a="partner:disclosure"]')).toContainText('earns a commission on purchases made through this page');
        await expect(reader.locator('[data-a="partner:note"]')).toContainText('Perfect for the bac <3');
        await noSideScroll(reader);

        await reader.click('[data-a="partner:buy"]');
        await reader.waitForURL(new RegExp(`/store/${SLUG}$`));
        await expect(reader.locator('[data-a="store:partner"]')).toContainText(name);
        await reader.click('[data-a="store:buy"]');
        await reader.waitForURL(/\/store\/checkout\/test\/cs_fake_/);
        await reader.fill('#test-email', `reader-${viewport.name}@example.com`);
        await reader.click('[data-a="store:test-pay"]');
        await reader.waitForURL(/\/store\/checkout\/success/);
        await expect(reader.locator('[data-a="store:thanks"]')).toBeVisible();

        // 5. The commission is pending for the partner…
        await partner.goto('/partner?tab=commissions');
        const row = partner.locator('[data-a="partner:commission"]').first();
        await expect(row).toContainText('Philo Terminale E2E');
        await expect(row).toContainText('$2.84');
        await expect(row.locator('[data-a="partner:status-badge:pending"]')).toBeVisible();
        await expect(partner.locator('body')).not.toContainText(`reader-${viewport.name}@example.com`);
        await noSideScroll(partner);

        // …and in the console's ledger.
        await admin.goto('/admin/partners?tab=commissions&status=pending');
        await expect(admin.locator('table')).toContainText(name);
        await noSideScroll(admin);

        // The fake gateway leaves for checkout with a 409 + X-Inertia-Location.
        expect(errors).toEqual([]);
        expect(adminErrors).toEqual([]);
        expect(ignoring(readerErrors, '409 (Conflict)')).toEqual([]);

        await Promise.all([partnerContext.close(), adminContext.close(), readerContext.close()]);
    });
}
