import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * Design & Layout's interior controls (ADR-0040 — Interior design controls):
 * every setting changes the page preview, which is PrintBook's own markup
 * and stylesheet, and the new render replaces the old only once it is laid
 * out. Then a quotation is made a box of a chosen kind in the editor.
 *
 * Every test puts the layout back to the defaults when it ends.
 */
const SHOTS = process.env.DESIGN_SHOTS ?? null;

/** The markup of a page of the preview on screen (inside its shadow root). */
async function shown(page, index = 0) {
    return page.evaluate((at) => {
        const host = document.querySelector(`[data-a="layout:print-preview"] [data-slot="shown"] [data-a="layout:page-host-${at}"]`);

        return host?.shadowRoot?.innerHTML ?? '';
    }, index);
}

/** Wait until the preview on screen is the render of the last change. */
async function settled(page) {
    await expect(page.locator('[data-a="layout:preview-state"]')).toHaveText('As the PDF sets it', { timeout: 30_000 });
    await expect(page.locator('[data-a="layout:print-preview"]')).toHaveAttribute('data-busy', 'false', { timeout: 30_000 });
}

async function openDesign(page) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit?pane=design`);
    await expect(page.locator('[data-a="layout:print-preview"] [data-slot="shown"]')).toBeVisible({ timeout: 30_000 });
    // From the defaults, whatever an earlier run left.
    await page.click('[data-a="layout:reset"]');
    await settled(page);

    return id;
}

async function resetLayout(page, id) {
    await page.goto(`/books/${id}/edit?pane=design`);
    await page.click('[data-a="layout:reset"]');
    await settled(page);
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test.describe(`at ${viewport.width}px`, () => {
        test.use({ viewport });

        test('each interior setting changes the page preview', async ({ page }) => {
            test.setTimeout(300_000);
            const errors = watchForErrors(page);
            const id = await openDesign(page);

            try {
                // The defaults: the classic opener, small-capped opening words.
                expect(await shown(page, 0)).toContain('class="opener"');
                expect(await shown(page, 0)).toContain('class="lead"');
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/design-${viewport.width}-defaults.png`, fullPage: false });

                // Opener style.
                await page.selectOption('[data-a="layout:opener-style"]', 'numeral');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toContain('opener-numeral');

                // Chapter label.
                await page.selectOption('[data-a="layout:opener-label"]', 'custom');
                await page.fill('[data-a="layout:opener-custom"]', 'Lesson');
                await page.selectOption('[data-a="layout:opener-style"]', 'classic');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toMatch(/opener-label">Lesson \d+</);

                // First paragraph: small capitals off, drop cap on.
                await page.click('[data-a="layout:opener-lead"]');
                await page.click('[data-a="layout:dropcap"]');
                await settled(page);
                await expect.poll(() => shown(page, 0)).not.toContain('class="lead"');
                await expect.poll(() => shown(page, 0)).toContain('class="dropcap"');

                // Right-hand starts are saved (the preview's opener is a recto already).
                await page.click('[data-a="layout:opener-chapters-recto"]');
                await expect(page.locator('[data-a="layout:opener-chapters-recto"]')).toHaveAttribute('aria-checked', 'true');
                await settled(page);

                // Running heads: the verso shows the author, the recto nothing.
                const before = await page.locator('[data-a="layout:print-preview"] [data-slot="shown"] [data-a="layout:head-1"]').textContent();
                await page.selectOption('[data-a="layout:heads-verso"]', 'author');
                await page.selectOption('[data-a="layout:heads-recto"]', 'none');
                await settled(page);
                const shownSlot = page.locator('[data-a="layout:print-preview"] [data-slot="shown"]');
                await expect(shownSlot.locator('[data-a="layout:head-2"]')).toHaveCount(0);
                await expect.poll(() => shownSlot.locator('[data-a="layout:head-1"]').textContent()).not.toBe(before);

                // Page numbers at the top outside: the verso's folio moves up and left.
                await page.selectOption('[data-a="layout:folio"]', 'top-outside');
                await settled(page);
                const folio = shownSlot.locator('[data-a="layout:folio-1"]');
                await expect(folio).toHaveCSS('text-align', 'left');
                expect(parseFloat(await folio.evaluate((el) => el.style.top))).toBeLessThan(100);

                // Contents depth is saved for print and the EPUB.
                await page.selectOption('[data-a="layout:toc-depth"]', '3');
                await page.selectOption('[data-a="layout:toc-epub"]', '1');
                await settled(page);
                const saved = await (await page.request.get(`/books/${id}/design`)).json();
                expect(saved.layout.toc).toEqual({ depth: 3, epub: 1 });
                expect(saved.layout.opener.custom).toBe('Lesson');

                // A box style: the page's stylesheet carries it.
                await page.selectOption('[data-a="layout:callout:tip:border"]', 'rules');
                await page.selectOption('[data-a="layout:callout:tip:tint"]', '10');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toContain('.callout.callout-tip { border: 0; border-top: .5pt solid #1a1a1a;');

                // Type changes reach the preview too, from the PDF's own stylesheet.
                await page.selectOption('[data-a="layout:font"]', 'sans');
                await settled(page);
                await expect.poll(() => shown(page, 0)).toContain("font-family: 'Source Sans 3'");

                // The render kept for the next change never shows through.
                const pending = page.locator('[data-a="layout:print-preview"] [data-slot="pending"]');
                if (await pending.count()) await expect(pending).toHaveCSS('opacity', '0');

                if (SHOTS) {
                    await page.locator('[data-a="layout:print-preview"]').scrollIntoViewIfNeeded();
                    await page.screenshot({ path: `${SHOTS}/design-${viewport.width}-changed.png`, fullPage: false });
                }

                // The page never scrolls sideways on a phone.
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                expect(overflow).toBeLessThanOrEqual(1);
            } finally {
                await resetLayout(page, id);
            }

            expect(errors).toEqual([]);
        });
    });
}

test('a quotation is made a box of the kind the author picks', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = watchForErrors(page);

    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);

    const editor = page.locator('.manuscript .ProseMirror');
    await expect(editor).toBeVisible();
    await expect(page.locator('[data-a="editor:callout-css"]')).toHaveCount(1, { timeout: 15_000 });

    // A new quotation at the end of the first paragraph.
    await editor.locator('p').first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    const marker = `BOXED${Date.now()}`;
    await page.keyboard.type(marker);
    await page.selectOption('[data-a="toolbar:block"]', 'quote');

    const picker = page.locator('[data-a="toolbar:callout"]');
    await expect(picker).toBeVisible();
    await expect(picker).toHaveValue('');
    await picker.selectOption('tip');

    const box = editor.locator('blockquote[data-callout="tip"]', { hasText: marker });
    await expect(box).toHaveCount(1);
    // Styled from the book's table: the classic tip is a dashed box.
    await expect(box).toHaveCSS('border-top-style', 'dashed');
    if (process.env.DESIGN_SHOTS) await page.screenshot({ path: `${process.env.DESIGN_SHOTS}/editor-callout-picker.png` });

    await expect(page.getByText('Saved', { exact: true })).toBeVisible({ timeout: 20_000 });
    await page.reload();
    const kept = page.locator('.manuscript .ProseMirror blockquote[data-callout="tip"]', { hasText: marker });
    await expect(kept).toHaveCount(1);

    // The suite's account is reseeded before every run (global-setup.js),
    // so the demo chapter is put back then.
    expect(errors).toEqual([]);
});
