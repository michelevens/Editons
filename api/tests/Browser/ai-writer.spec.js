import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * AI writes the book (ADR — AI writes the book), at 1440 and 390 px, with
 * the sample writer (no provider is ever called):
 *
 *   Quick Start, one sentence → "Draft it with AI" → the plan → Accept
 *   plan → Write my book (the one approval) → wait → book drafted → open a
 *   chapter ("AI draft, not yet reviewed", Accept) → Export → EPUB.
 *
 * Counts the clicks from the sentence to a drafted book, and to an
 * exported file. A fresh account is moved to the Author plan (Free drafts
 * one chapter only); the server must run with QUEUE_CONNECTION=sync or a
 * worker, and the same database as this checkout's artisan (DB_DATABASE).
 * No console errors, no horizontal scroll.
 */
test.describe.configure({ mode: 'serial', timeout: 900_000 });
test.use({ actionTimeout: 180_000, navigationTimeout: 300_000 });
const slow = expect.configure({ timeout: 300_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SHOTS = process.env.DRAFT_SHOTS ?? null;
const IDEA = 'A practical guide to growing vegetables on a balcony in Port-au-Prince';

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function noHorizontalScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

/**
 * A throwaway account on the Author plan, through this checkout's app (a
 * small script file: no shell quoting to go wrong on Windows).
 */
function upgrade(email) {
    const script = join(tmpdir(), `e2e-upgrade-${Date.now()}.php`);
    writeFileSync(
        script,
        `<?php
require ${JSON.stringify(join(ROOT, 'vendor', 'autoload.php'))};
$app = require ${JSON.stringify(join(ROOT, 'bootstrap', 'app.php'))};
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
App\\Models\\User::where('email', ${JSON.stringify(email)})->update(['plan' => 'author', 'credits_granted_at' => null]);
`,
    );
    execFileSync(process.env.E2E_PHP ?? 'php', [script], { cwd: ROOT, stdio: 'inherit', env: process.env, shell: process.platform === 'win32' });
}

async function journey(page, width, prefix) {
    const errors = watchForErrors(page);
    let clicks = 0;
    const click = async (selector) => {
        clicks++;
        await page.locator(selector).first().click();
    };

    const email = await registerFreshAuthor(page);
    upgrade(email);

    await page.goto('/start');
    await page.fill('[data-a="quick:title"]', IDEA);
    await shot(page, `${prefix}-01-quickstart`);

    // 1. The sentence goes to the planner.
    await click('[data-a="quick:draft"]');
    await page.waitForURL(/\/books\/\d+\/edit\?.*pane=draft/);
    await slow(page.locator('[data-a="draft:plan"]')).toBeVisible();
    await expect(page.locator('[data-a="draft:sample"]')).toBeVisible();
    await shot(page, `${prefix}-02-plan`);
    await noHorizontalScroll(page, width);

    // 2. Accept the plan: the binder is built.
    await click('[data-a="draft:accept"]');
    await slow(page.locator('[data-a="draft:approve"]')).toBeVisible();
    await expect(page.locator('[data-a="draft:estimate"]')).toContainText('sections');
    await shot(page, `${prefix}-03-approve`);

    // 3. The one approval.
    await click('[data-a="draft:write"]');
    await slow(page.locator('[data-a="draft:done"]')).toBeVisible();
    const drafted = clicks;
    await expect(page.locator('[data-a^="draft:status:"]').first()).toContainText('Done');
    await shot(page, `${prefix}-04-drafted`);
    await noHorizontalScroll(page, width);

    // A written chapter says it is an unreviewed AI draft until accepted.
    await page.locator('[data-a="draft:run"] ol li button').first().click();
    await slow(page.locator('[data-a="editor:ai-draft"]')).toBeVisible();
    await shot(page, `${prefix}-05-chapter`);
    await page.click('[data-a="editor:ai-draft-accept"]');
    await expect(page.locator('[data-a="editor:ai-draft"]')).toHaveCount(0);

    // Back to Write my book, then on to the file.
    const bookId = Number(page.url().match(/\/books\/(\d+)\/edit/)[1]);
    await page.goto(`/books/${bookId}/edit?pane=draft`);
    await slow(page.locator('[data-a="draft:done"]')).toBeVisible();
    let toExport = drafted;
    toExport++;
    await page.click('[data-a="draft:export"]');
    toExport++;
    await slow(page.locator('[data-a="publish:generate:epub"]')).toBeVisible();
    await page.click('[data-a="publish:generate:epub"]');
    await slow(page.locator('[data-a^="publish:download:"]').first()).toBeVisible();
    await shot(page, `${prefix}-06-exported`);
    await noHorizontalScroll(page, width);

    expect(errors).toEqual([]);

    // Reported by the run: the clicks from the sentence.
    console.log(`[${width}px] clicks to a drafted book: ${drafted}; to an exported EPUB: ${toExport}`);
    expect(drafted).toBe(3);
}

test('1440: one sentence to a drafted book, then an EPUB', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await journey(page, 1440, 'd');
});

test('390: the same journey on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await journey(page, 390, 'm');
});
