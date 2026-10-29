import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { DEMO, signIn, watchForErrors } from './helpers.js';

/**
 * Sales reports and royalty statements (ADR-00XX), on a desktop and on a
 * phone: the e2e account's storefront sales over two quarters, then last
 * quarter chosen, compared with the previous one, filtered to one book;
 * the CSV of those sales downloaded and read; a quarterly statement
 * downloaded as a PDF. Nothing wider than the screen, no console errors.
 *
 * The sales are written straight into the suite's own database (orders
 * numbered EC-E2ES…, replaced on every run), as fulfilment would write
 * them: there is no card payment to make in a browser test.
 */
test.describe.configure({ timeout: 240_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SHOTS = process.env.SALES_SHOTS;

async function shot(page, name) {
    if (!SHOTS) return;
    mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
}

async function noHorizontalScroll(page) {
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wide).toBeLessThanOrEqual(1);
}

test.beforeAll(() => {
    const script = join(tmpdir(), `e2e-sales-${Date.now()}.php`);
    writeFileSync(
        script,
        String.raw`<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where('email', getenv('E2E_EMAIL'))->firstOrFail();
App\Models\Order::where('number', 'like', 'EC-E2ES%')->delete();
$books = App\Models\Book::where('user_id', $u->id)->orderBy('id')->take(2)->get();
$tz = $u->reportTimezone();
$start = Carbon\CarbonImmutable::now($tz)->firstOfQuarter()->subMonths(6);
$n = 0;
foreach (range(0, 175) as $day) {
    if ($day % 3 !== 0) continue;
    foreach ($books as $i => $book) {
        $price = $i === 0 ? 1200 : 900;
        $fee = App\Commerce\Revenue::fee($price);
        $o = new App\Models\Order;
        $o->forceFill(['number' => 'EC-E2ES'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT), 'status' => 'paid', 'currency' => 'usd', 'total_cents' => $price, 'fee_cents' => $fee, 'buyer_country' => 'HT', 'paid_at' => $start->addDays($day)->setTime(14, 0)->utc()])->save();
        $o->items()->make()->forceFill(['book_id' => $book->id, 'seller_id' => $u->id, 'title' => $book->title, 'formats' => $i === 0 ? ['epub', 'pdf'] : ['pdf'], 'license_type' => 'personal', 'price_cents' => $price, 'fee_cents' => $fee, 'royalty_cents' => App\Commerce\Revenue::royalty($price, $fee)])->save();
    }
}
echo 'SALES='.$n;
`,
    );

    try {
        const out = execFileSync(process.env.E2E_PHP ?? 'php', [script], { cwd: ROOT, encoding: 'utf8', env: { ...process.env, E2E_EMAIL: DEMO.email }, shell: process.platform === 'win32' });
        if (!/SALES=\d+/.test(out)) throw new Error(`Could not write the sales: ${out}`);
    } finally {
        unlinkSync(script);
    }
});

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test(`sales report: last quarter, compared, one book, CSV and a statement at ${viewport.width}px`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/sales');
        await expect(page.locator('[data-a="sales:range"]')).toHaveValue('last_30');
        await noHorizontalScroll(page);

        // Last quarter, then the previous one beside it.
        await page.selectOption('[data-a="sales:range"]', 'last_quarter');
        await page.waitForURL(/range=last_quarter/);
        await expect(page.locator('[data-a="sales:span"]')).toContainText('–');
        await page.selectOption('[data-a="sales:compare"]', 'previous');
        await page.waitForURL(/compare=previous/);
        await expect(page.locator('[data-a="sales:delta"]').first()).toBeVisible();
        await expect(page.locator('[data-a="sales:span"]')).toContainText('compared with');

        // One book.
        const book = await page.locator('[data-a="sales:book"] option').nth(1).getAttribute('value');
        await page.selectOption('[data-a="sales:book"]', book);
        await page.waitForURL(new RegExp(`book=${book}`));
        await expect(page.locator('[data-a="sales:table:book"] [data-a="sales:row"]')).toHaveCount(1);

        // The period breakdown is weekly for a quarter.
        await page.click('[data-a="sales:by:period"]');
        await expect(page.locator('[data-a="sales:table:period"] [data-a="sales:row"]').first()).toBeVisible();
        await noHorizontalScroll(page);
        await shot(page, `sales-${viewport.width}-quarter`);

        // The URL is the view: a reload keeps it.
        await page.reload();
        await expect(page.locator('[data-a="sales:range"]')).toHaveValue('last_quarter');
        await expect(page.locator('[data-a="sales:compare"]')).toHaveValue('previous');

        // The CSV of exactly these sales.
        const [download] = await Promise.all([page.waitForEvent('download'), page.click('[data-a="sales:csv"]')]);
        const csv = readFileSync(await download.path(), 'utf8');
        expect(csv.charCodeAt(0)).toBe(0xfeff);
        const lines = csv.slice(1).trim().split(/\r?\n/);
        expect(lines[0]).toContain('order,paid_at,date,book_id,title');
        expect(lines.length).toBeGreaterThan(10);
        expect(lines.slice(1).every((l) => l.split(',')[3] === book)).toBe(true);

        // A quarterly royalty statement, as a PDF.
        await page.click('[data-a="sales:statements:quarter"]');
        const [pdf] = await Promise.all([page.waitForEvent('download'), page.locator('[data-a="sales:statement"]').first().click()]);
        expect(pdf.suggestedFilename()).toMatch(/^royalty-statement-.*-\d{4}-Q[1-4]-usd\.pdf$/);
        expect(readFileSync(await pdf.path()).subarray(0, 4).toString()).toBe('%PDF');

        await page.locator('[data-a="sales:statements"]').scrollIntoViewIfNeeded();
        await shot(page, `sales-${viewport.width}-statements`);
        await noHorizontalScroll(page);

        expect(errors).toEqual([]);
    });
}
