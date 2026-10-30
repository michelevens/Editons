import { execFileSync } from 'node:child_process';
import { mkdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { DEMO, signIn, watchForErrors } from './helpers.js';

/**
 * Book language and typography (ADR — Book language and typography), on a
 * desktop and on a phone: a French book with "Correct as you type" on
 * writes « guillemets » with their spaces and a thin space before ! as
 * you type, the editor is in French for the spell-checker, and Ctrl+Z puts
 * back the dots an ellipsis replaced; then Book Settings changes a rule,
 * counts what "Fix typography" would correct and applies it. Nothing wider
 * than the screen, no console errors.
 *
 * The book is the suite's own ("E2E Typography", rebuilt on every run in
 * the e2e account), so nothing else on the shelf is touched.
 */
test.describe.configure({ timeout: 480_000 });

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SHOTS = process.env.TYPO_SHOTS;
const NBSP = ' ';
const NNBSP = ' ';
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
    const script = join(tmpdir(), `e2e-typo-${Date.now()}.php`);
    writeFileSync(
        script,
        String.raw`<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where('email', getenv('E2E_EMAIL'))->firstOrFail();
foreach (App\Models\Book::withTrashed()->where('user_id', $u->id)->where('title', 'E2E Typography')->get() as $old) { $old->forceDelete(); }
$book = new App\Models\Book(['title' => 'E2E Typography', 'language' => 'French', 'settings' => ['typography' => ['as_you_type' => true]]]);
$book->user_id = $u->id; $book->save();
$doc = fn ($t) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]]];
$one = $book->sections()->create(['type' => 'chapter', 'title' => 'Un', 'position' => 10, 'content' => $doc('Premier jet.'), 'word_count' => 2]);
$book->sections()->create(['type' => 'chapter', 'title' => 'Deux', 'position' => 20, 'content' => $doc('Il a dit "non"! Quoi? Fin...'), 'word_count' => 5]);
echo 'BOOK='.$book->id.';SECTION='.$one->id;
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
    test(`typography as you type, settings and fix pass at ${viewport.width}px`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = watchForErrors(page);
        const key = viewport.width;

        await signIn(page);
        await page.goto(`/books/${book.id}/edit?section=${book.section}`);

        const editor = page.locator('#manuscript .ProseMirror');
        await expect(editor).toContainText('Premier jet.');
        // The browser's spell-check, in the book's language.
        await expect(editor).toHaveAttribute('lang', 'fr');
        await expect(editor).toHaveAttribute('spellcheck', 'true');

        await editor.click();
        await page.keyboard.press('Control+End');
        await page.keyboard.type(' Il dit "oui"! Bref:');
        await expect(editor).toContainText(`Il dit «${NBSP}oui${NBSP}»${NNBSP}! Bref:`);

        // Typed without a space, a colon stays as typed ("10:30"); after one
        // it gets its no-break space.
        await page.keyboard.type(' Voici :');
        await expect(editor).toContainText(`Voici${NBSP}:`);

        // An ellipsis, then Ctrl+Z: the three dots come back, and stay.
        await page.keyboard.type(' Fin...');
        await expect(editor).toContainText('Fin…');
        await page.keyboard.press('Control+z');
        await expect(editor).toContainText('Fin...');
        await expect(editor).not.toContainText('Fin…');

        await page.keyboard.type(' a -- b');
        await expect(editor).toContainText('a — b');
        await noHorizontalScroll(page);
        await shot(page, `editor-${key}`);

        // Saved before leaving: the footer says so.
        await page.keyboard.press('Control+s');
        await expect(page.getByText('Saved', { exact: true }).first()).toBeVisible({ timeout: 15_000 });

        await page.goto(`/books/${book.id}/settings`);
        const panel = page.locator('[data-a="settings:typography"]');
        await panel.scrollIntoViewIfNeeded();
        await expect(panel).toContainText('Language and typography');
        await expect(panel.locator('[data-a="settings:typography:quotes"]')).toHaveValue('');
        await expect(panel.locator('[data-a="settings:typography:quotes"] option').first()).toHaveText('Language default (« Guillemets »)');
        await expect(panel.locator('[data-a="settings:toggle:typography:as_you_type"]')).toHaveAttribute('aria-checked', 'true');
        await expect(panel.locator('[data-a="settings:interior"]')).toContainText('First-line indent');

        // A rule changed and saved: dashes off.
        const saved = page.waitForResponse((r) => r.url().includes(`/books/${book.id}`) && r.request().method() === 'PATCH');
        await panel.locator('[data-a="settings:typography:dashes"]').selectOption('off');
        await saved;
        await noHorizontalScroll(page);
        await shot(page, `settings-${key}`);

        // Count, then fix. Deux: "non" (2), ! ? and ... (3). Un: what the
        // editor left as typed, "Bref:" and the restored dots (2).
        await panel.locator('[data-a="settings:fix-typography:preview"]').click();
        const count = panel.locator('[data-a="settings:fix-typography:count"]');
        await expect(count).toContainText('7 corrections in 2 chapters');
        await expect(count).toContainText('Deux');
        await shot(page, `fix-count-${key}`);

        await panel.locator('[data-a="settings:fix-typography:apply"]').click();
        await expect(panel.locator('[data-a="settings:fix-typography:done"]')).toContainText('Fixed 7 corrections in 2 chapters');
        await noHorizontalScroll(page);
        await shot(page, `fix-done-${key}`);

        // Nothing left to fix.
        await panel.locator('[data-a="settings:fix-typography:preview"]').click();
        await expect(panel).toContainText('Nothing to correct');

        expect(errors).toEqual([]);
    });
}
