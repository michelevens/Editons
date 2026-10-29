import { execFileSync } from 'node:child_process';
import { mkdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { DEMO, signIn, watchForErrors } from './helpers.js';

/**
 * Style guides and review options (ADR — Style guides and review options),
 * on a desktop and on a phone: a style guide created for a book and given
 * a preferred term, a chapter-scope review run on the sample driver (no
 * AI, no credits), its findings filtered by rule and chapter, a filler-word
 * rule's findings dismissed in bulk after confirming, and "never flag this
 * again" applied and undone. Nothing wider than the screen, no console
 * errors.
 *
 * The book is the suite's own (titled "E2E Style guide", rebuilt on every
 * run in the e2e account), so nothing else on the shelf is touched.
 */
test.describe.configure({ timeout: 240_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SHOTS = process.env.STYLE_SHOTS;
let book;

async function shot(page, name) {
    if (!SHOTS) return;
    mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
}

async function noHorizontalScroll(page) {
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wide).toBeLessThanOrEqual(1);
}

test.beforeEach(() => {
    const script = join(tmpdir(), `e2e-style-${Date.now()}.php`);
    writeFileSync(
        script,
        String.raw`<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where('email', getenv('E2E_EMAIL'))->firstOrFail();
foreach (App\Models\Book::withTrashed()->where('user_id', $u->id)->where('title', 'E2E Style guide')->get() as $old) { $old->forceDelete(); }
App\Models\StyleGuide::where('user_id', $u->id)->where('name', 'like', 'E2E House%')->delete();
$book = new App\Models\Book(['title' => 'E2E Style guide', 'language' => 'en']);
$book->user_id = $u->id; $book->save();
$doc = fn ($t) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]]];
$ids = [];
foreach (['Un' => 'Send it by e-mail. It was very good. It was really long.', 'Deux' => 'Reply by e-mail today. It was quite fine.', 'Trois' => 'Plain prose and nothing more.'] as $i => $text) {
    $s = $book->sections()->create(['type' => 'chapter', 'title' => $i, 'position' => (count($ids) + 1) * 10, 'content' => $doc($text), 'word_count' => str_word_count($text)]);
    $ids[] = $s->id;
}
echo 'BOOK='.$book->id.';SECTION='.$ids[0];
`,
    );

    try {
        const out = execFileSync(process.env.E2E_PHP ?? 'php', [script], { cwd: ROOT, encoding: 'utf8', env: { ...process.env, E2E_EMAIL: DEMO.email }, shell: process.platform === 'win32' });
        const m = out.match(/BOOK=(\d+);SECTION=(\d+)/);
        if (!m) throw new Error(`Could not make the book: ${out}`);
        book = { id: Number(m[1]), section: Number(m[2]) };
    } finally {
        unlinkSync(script);
    }
});

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test(`style guide, chapter review, filters, bulk dismiss and never flag at ${viewport.width}px`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto(`/books/${book.id}/edit?pane=ai&section=${book.section}`);
        await expect(page.locator('[data-a="style-guide:status"]')).toContainText('No style guide');

        // A guide for the book, then a preferred term saved as version 2.
        await page.click('[data-a="style-guide:toggle"]');
        await page.fill('[data-a="style-guide:name"]', `E2E House ${viewport.width}`);
        await page.click('[data-a="style-guide:create"]');
        await expect(page.locator('[data-a="style-guide:status"]')).toContainText(`Follows “E2E House ${viewport.width}”, version 1`);
        await page.click('[data-a="style-guide:edit"]');
        await page.click('[data-a="style-guide:preferred:add"]');
        await page.fill('[data-a="style-guide:preferred:0:use"]', 'email');
        await page.fill('[data-a="style-guide:preferred:0:avoid"]', 'e-mail');
        await page.selectOption('[data-a="style-guide:quotes"]', 'double');
        await page.click('[data-a="style-guide:save"]');
        await expect(page.locator('[data-a="style-guide:status"]')).toContainText('version 2');
        await expect(page.locator('[data-a="style-guide:summary"]')).toContainText('email (not e-mail)');
        await noHorizontalScroll(page);
        await shot(page, `${viewport.width}-1-style-guide`);

        // A Copy review of this chapter: the estimate first, then the run.
        await page.selectOption('[data-a="review-options:agent"]', 'copy');
        await expect(page.locator('[data-a="review-options:scope:chapter"]')).toHaveAttribute('aria-checked', 'true');
        await expect(page.locator('[data-a="review-options:estimate"]')).toContainText('1 of 1 chapter');
        await expect(page.locator('[data-a="review-options:estimate"]')).toContainText('sample output, no credits');
        await expect(page.locator('[data-a="review-options:estimate"]')).toContainText('with the style guide');
        await page.click('[data-a="review-options:sensitivity:thorough"]');
        await page.fill('[data-a="review-options:focus"]', 'Check the house spelling.');
        await page.click('[data-a="review-options:run"]');
        const list = page.locator('[data-a="ai-screen:list"]');
        await expect(list).toContainText('Style guide: “email”, not “e-mail”');
        await expect(list).toContainText('Filler word');
        // Only the chapter in scope was reviewed.
        await expect(list).not.toContainText('Deux');
        await shot(page, `${viewport.width}-2-reviewed`);

        // Filter by rule, then by chapter.
        await page.selectOption('[data-a="ai-screen:rule"]', 'style:preferred:e-mail');
        await expect(list.locator('li')).toHaveCount(1);
        await expect(list.locator('li')).toContainText('Style guide check');
        await page.selectOption('[data-a="ai-screen:rule"]', '');
        await page.click('[data-a="ai-screen:severity:suggestion"]');
        await expect(list.locator('li').first()).toBeVisible();
        await page.click('[data-a="ai-screen:severity:all"]');
        await page.selectOption('[data-a="ai-screen:section"]', String(book.section));
        await expect(list).toContainText('Un');

        // Never flag the house term's word, then undo.
        const style = list.locator('li', { hasText: 'Style guide: “email”' });
        await style.locator('[data-a^="ai-screen:never:"]').first().click();
        await style.locator('[data-a^="ai-screen:never:word:"]').click();
        await expect(page.locator('[data-a="ai-screen:undo"]')).toContainText('“e-mail” won’t be flagged again');
        await expect(list).not.toContainText('Style guide: “email”');
        await shot(page, `${viewport.width}-3-never-flag`);
        await page.click('[data-a="ai-screen:undo:button"]');
        await expect(page.locator('[data-a="ai-screen:undo"]')).toHaveCount(0);
        await expect(list).toContainText('Style guide: “email”');

        // Bulk dismiss every filler word, after confirming.
        await page.selectOption('[data-a="ai-screen:rule"]', 'copy:filler-word');
        await expect(list.locator('li', { hasNotText: 'Filler word' })).toHaveCount(0);
        const fillers = await list.locator('li').count();
        expect(fillers).toBeGreaterThan(1);
        await page.check('[data-a="ai-screen:pick-all"]');
        await page.click('[data-a="ai-screen:bulk:dismiss"]');
        await expect(page.locator('[data-a="ai-screen:confirm"]')).toContainText(`Dismiss ${fillers} findings?`);
        await shot(page, `${viewport.width}-4-confirm`);
        await page.click('[data-a="ai-screen:confirm:yes"]');
        await expect(page.locator('[data-a="ai-screen:list"]')).toHaveCount(0);
        await page.click('[data-a="ai-screen:status:dismissed"]');
        await expect(page.locator('[data-a="ai-screen:list"] li')).toHaveCount(fillers);
        await noHorizontalScroll(page);
        await shot(page, `${viewport.width}-5-dismissed`);

        expect(errors).toEqual([]);
    });
}
