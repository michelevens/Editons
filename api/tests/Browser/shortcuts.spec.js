import { expect, test } from '@playwright/test';
import { firstBookId, ignoring, signIn, watchForErrors } from './helpers.js';

/**
 * Configurable shortcuts and mobile tabs (ADR — Configurable shortcuts and
 * mobile tabs): the `?` sheet, remapping with conflicts and resets, keys
 * matched for non-QWERTY layouts, pinned phone tabs, review-deck swipes.
 *
 * Every test starts and ends with no preferences (DELETE /preferences/*),
 * so the rest of the suite sees the defaults.
 */
test.describe.configure({ timeout: 240_000 });

const SHOTS = process.env.KEYS_SHOTS ?? null;

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` });
}

const resetAll = (page) =>
    page.evaluate(async () => {
        for (const section of ['shortcuts', 'mobileTabs', 'reviewDeck']) {
            await window.axios.delete(`/preferences/${section}`);
        }
    });

const prefsSaved = (page) =>
    page.waitForResponse((r) => r.url().endsWith('/preferences') && r.request().method() === 'PUT' && r.ok());

async function openStudio(page) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await resetAll(page);
    await page.reload();
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    return id;
}

test.describe('desktop', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('? opens the sheet of every shortcut, searchable; the menu opens it too', async ({ page }) => {
        const errors = watchForErrors(page);
        await signIn(page);
        await page.goto('/books');
        await resetAll(page);

        await page.locator('body').click({ position: { x: 5, y: 5 } });
        await page.keyboard.press('Shift+Slash');
        const sheet = page.locator('[data-a="shortcuts:sheet"]');
        await expect(sheet).toBeVisible();

        for (const scope of ['global', 'editor', 'binder', 'deck', 'cover']) {
            await expect(sheet.locator(`[data-a="shortcuts:group:${scope}"]`)).toBeVisible();
        }
        await expect(sheet.locator('[data-a="shortcuts:keys:editor.save"]')).toHaveText('Ctrl+S');
        await expect(sheet.locator('[data-a="shortcuts:keys:view.outline"]')).toHaveText('Ctrl+\\');
        await expect(sheet.locator('[data-a="shortcuts:keys:deck.next"]')).toContainText('→');
        await shot(page, 'sheet-1440');

        // Typing "?" into the search box searches; it does not reopen anything.
        await sheet.locator('[data-a="shortcuts:search"]').fill('distraction');
        await expect(sheet.locator('[data-a^="shortcuts:row:"]')).toHaveCount(2);
        await sheet.locator('[data-a="shortcuts:search"]').fill('zzzz');
        await expect(sheet).toContainText('No shortcut matches');

        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();

        // From the account menu.
        await page.click('[data-a="user:menu"]');
        await page.click('[data-a="user:shortcuts"]');
        await expect(sheet).toBeVisible();
        await page.keyboard.press('Escape');

        // From search.
        await page.keyboard.press('Control+k');
        await page.keyboard.type('Keyboard shortcuts');
        await page.click('[data-a="palette:help:shortcuts"]');
        await expect(sheet).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('remapping Save: the new keys save, the old ones do not; conflicts warn; reset', async ({ page }) => {
        const errors = watchForErrors(page);
        await openStudio(page);

        const manualSaves = [];
        page.on('request', (r) => {
            if (/\/sections\/\d+\/content$/.test(r.url()) && r.method() === 'PUT' && (r.postData() ?? '').includes('"manual"')) {
                manualSaves.push(r.url());
            }
        });

        // Default: Ctrl+S saves.
        await page.locator('.manuscript .ProseMirror').click();
        await page.keyboard.press('Control+s');
        await expect.poll(() => manualSaves.length).toBe(1);

        await page.evaluate(() => window.dispatchEvent(new CustomEvent('shortcuts:open')));
        const sheet = page.locator('[data-a="shortcuts:sheet"]');
        await expect(sheet).toBeVisible();

        // A conflict: Ctrl+B is the editor's bold.
        await sheet.locator('[data-a="shortcuts:change:editor.save"]').click();
        await page.keyboard.press('Control+b');
        await expect(sheet.locator('[data-a="shortcuts:conflict"]')).toContainText('Bold');
        // The browser's own: Ctrl+W closes the tab.
        await page.keyboard.press('Control+w');
        await expect(sheet.locator('[data-a="shortcuts:conflict"]')).toContainText('closes the tab');
        await shot(page, 'conflict-1440');

        // Still recording: the next combination is taken.
        const saved = prefsSaved(page);
        await page.keyboard.press('Control+Alt+s');
        await saved;
        await expect(sheet.locator('[data-a="shortcuts:keys:editor.save"]')).toHaveText('Ctrl+Alt+S');
        await expect(sheet.locator('[data-a="shortcuts:reset:editor.save"]')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();

        await page.locator('.manuscript .ProseMirror').click();
        await page.keyboard.press('Control+s');
        await page.waitForTimeout(1200);
        expect(manualSaves.length, 'the old Ctrl+S no longer saves').toBe(1);
        await page.keyboard.press('Control+Alt+s');
        await expect.poll(() => manualSaves.length).toBe(2);

        // After a reload, still the author's.
        await page.reload();
        await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
        await page.locator('.manuscript .ProseMirror').click();
        await page.keyboard.press('Control+Alt+s');
        await expect.poll(() => manualSaves.length).toBe(3);

        // Reset one, then a second change and Reset all.
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('shortcuts:open')));
        await sheet.locator('[data-a="shortcuts:reset:editor.save"]').click();
        await expect(sheet.locator('[data-a="shortcuts:keys:editor.save"]')).toHaveText('Ctrl+S');
        await sheet.locator('[data-a="shortcuts:change:view.bottom"]').click();
        const again = prefsSaved(page);
        await page.keyboard.press('Control+Alt+j');
        await again;
        await expect(sheet.locator('[data-a="shortcuts:keys:view.bottom"]')).toHaveText('Ctrl+Alt+J');
        await sheet.locator('[data-a="shortcuts:reset-all"]').click();
        await expect(sheet.locator('[data-a="shortcuts:keys:view.bottom"]')).toHaveText('Ctrl+J');

        expect(await page.evaluate(async () => (await window.axios.get('/preferences')).data.shortcuts)).toEqual({});

        // Search (Ctrl+K) moved shows its new keys in the header.
        await sheet.locator('[data-a="shortcuts:change:app.search"]').click();
        const third = prefsSaved(page);
        await page.keyboard.press('Control+Shift+k');
        await third;
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-a="palette:open"]')).toHaveAttribute('aria-keyshortcuts', 'Control+Shift+K Meta+Shift+K');
        await page.keyboard.press('Control+Shift+k');
        await expect(page.locator('[data-a="palette:input"]')).toBeVisible();
        await page.keyboard.press('Escape');

        await resetAll(page);
        expect(ignoring(errors, '422')).toEqual([]);
    });

    test('keys are matched for the layout: letters by what they type, punctuation by position', async ({ page }) => {
        const errors = watchForErrors(page);
        await openStudio(page);

        const press = (init) =>
            page.evaluate((init) => {
                window.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }));
            }, init);
        const palette = page.locator('[data-a="palette:input"]');

        // Dvorak types K on the key QWERTY calls V: Ctrl+K still opens search.
        await press({ key: 'k', code: 'KeyV', ctrlKey: true });
        await expect(palette.first()).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(palette).toHaveCount(0);

        // ...and the key in QWERTY's K position, typing something else, does not.
        await press({ key: 't', code: 'KeyK', ctrlKey: true });
        await page.waitForTimeout(300);
        await expect(palette).toHaveCount(0);

        // AZERTY AltGr+8 types "\" and reports Ctrl+Alt on Digit8: it must not
        // toggle the AI panel (Ctrl+Alt+\ is the physical key).
        const right = page.locator('#ws-right');
        await expect(right).toBeVisible();
        await press({ key: '\\', code: 'Digit8', ctrlKey: true, altKey: true });
        await page.waitForTimeout(300);
        await expect(right).toBeVisible();

        // Ctrl+Shift+F, as AZERTY sends it (F is in the same place).
        await press({ key: 'F', code: 'KeyF', ctrlKey: true, shiftKey: true });
        await expect(right).toBeHidden();
        await press({ key: 'Escape', code: 'Escape' });

        expect(errors).toEqual([]);
    });
});

test.describe('phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('pin your own tabs: saved on the account, back after a reload, reset', async ({ page }) => {
        const errors = watchForErrors(page);
        await signIn(page);
        await page.goto('/books');
        await resetAll(page);
        await page.reload();

        const bar = page.locator('[data-a="mobile:tabs"]');
        await expect(bar.locator('[data-a="mobile:books"]')).toBeVisible();
        await expect(bar.locator('[data-a="mobile:inbox"]')).toBeVisible();

        await bar.locator('[data-a="mobile:more"]').click();
        await page.click('[data-a="sheet:edit-tabs"]');
        const picker = page.locator('[data-a="sheet:tab-picker"]');
        await expect(picker).toBeVisible();
        // Today's tabs come pinned (a module switched off is not offered).
        const before = await bar.locator('button').count();
        await expect(picker.locator('[data-a="sheet:tab-count"]')).toHaveText(`${before - 1} of 4 pinned`);

        // Unpin them all, then pin Books, Write, Help and Billing, in that order.
        const pressed = picker.locator('[aria-pressed="true"]');
        while ((await pressed.count()) > 0) await pressed.first().click();
        await expect(picker.locator('[data-a="sheet:tab-count"]')).toHaveText('0 of 4 pinned');
        await expect(picker.locator('[data-a="sheet:tabs-save"]')).toBeDisabled();
        for (const key of ['books', 'continue', 'support', 'billing']) {
            await picker.locator(`[data-a="sheet:pin:${key}"]`).click();
        }
        // Four is the most: the rest are disabled.
        await expect(picker.locator('[data-a="sheet:pin:purchases"]')).toBeDisabled();
        await shot(page, 'tabs-picker-390');
        const saved = prefsSaved(page);
        await picker.locator('[data-a="sheet:tabs-save"]').click();
        await saved;
        await page.keyboard.press('Escape');

        await expect(bar.locator('[data-a="mobile:support"]')).toBeVisible();
        await expect(bar.locator('[data-a="mobile:billing"]')).toBeVisible();
        await expect(bar.locator('[data-a="mobile:inbox"]')).toHaveCount(0);

        await page.reload();
        const order = await bar.locator('button').evaluateAll((els) => els.map((el) => el.dataset.a));
        expect(order).toEqual(['mobile:books', 'mobile:continue', 'mobile:support', 'mobile:billing', 'mobile:more']);
        await shot(page, 'tabs-pinned-390');

        await bar.locator('[data-a="mobile:billing"]').click();
        await page.waitForURL(/billing/);

        // Back to the usual tabs.
        await page.goto('/books');
        await bar.locator('[data-a="mobile:more"]').click();
        await page.click('[data-a="sheet:edit-tabs"]');
        const reset = prefsSaved(page);
        await page.click('[data-a="sheet:tabs-reset"]');
        await reset;
        await page.keyboard.press('Escape');
        await expect(bar.locator('[data-a="mobile:inbox"]')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        expect(errors).toEqual([]);
    });

    test('review deck: swipes swapped, then off (buttons only)', async ({ page }) => {
        const errors = watchForErrors(page);
        await openStudio(page);

        const prose = page.locator('.manuscript .ProseMirror');
        const marker = `keys${Date.now().toString(36)}`;
        await prose.click();
        await page.keyboard.press('Control+End');
        await page.keyboard.type(` The the ${marker} word.`);
        await page.waitForTimeout(2000);
        await page.locator('[data-a="toolbar:done"]').click().catch(() => {});

        await page.click('[data-a="mobile:ai"]');
        const drawer = page.getByRole('dialog', { name: 'AI Editor' });
        const reviewed = page.waitForResponse((r) => /\/sections\/\d+\/review$/.test(r.url()) && r.request().method() === 'POST');
        await drawer.locator('[data-a="ai:run:copy"]').click();
        const { findings } = await (await reviewed).json();
        const finding = findings.find((f) => f.title === 'Repeated word' && f.status === 'open' && f.suggestion);
        expect(finding).toBeTruthy();

        await drawer.locator('[data-a="ai:review-one-by-one"]').click();
        const card = drawer.locator(`[data-a="review:card:${finding.id}"]`);
        for (let i = 0; i < 60 && !(await card.isVisible()); i++) {
            await drawer.locator('[data-a="review:next"]').click();
        }
        await expect(card).toBeVisible();

        const swipe = (by) =>
            card.evaluate(async (el, by) => {
                // A frame between events, as a real finger gives React time
                // to render the drag before the release reads it.
                const frame = () => new Promise((resolve) => setTimeout(resolve, 30));
                const box = el.getBoundingClientRect();
                const y = box.top + 40;
                const x0 = box.left + box.width / 2;
                const touch = (x) => new Touch({ identifier: 1, target: el, clientX: x, clientY: y });
                el.dispatchEvent(new TouchEvent('touchstart', { bubbles: true, touches: [touch(x0)], changedTouches: [touch(x0)] }));
                await frame();
                for (let i = 1; i <= 6; i++) {
                    const t = touch(x0 + (by * i) / 6);
                    el.dispatchEvent(new TouchEvent('touchmove', { bubbles: true, touches: [t], changedTouches: [t] }));
                    await frame();
                }
                el.dispatchEvent(new TouchEvent('touchend', { bubbles: true, touches: [], changedTouches: [touch(x0 + by)] }));
            }, by);

        // Swapped: a swipe LEFT applies.
        const saved = prefsSaved(page);
        await drawer.locator('[data-a="review:swipe"]').selectOption('swapped');
        await saved;
        await expect(drawer.locator('[data-a="review:swipe-hint"]')).toContainText('left to apply');
        await shot(page, 'deck-swapped-390');

        await swipe(-160);
        await expect(drawer.locator('[data-a="review:undo-bar"]')).toContainText('Fix applied');
        const reopened = page.waitForResponse((r) => r.url().endsWith(`/findings/${finding.id}/reopen`) && r.ok());
        await drawer.locator('[data-a="review:undo"]').click();
        await reopened;
        await expect(card).toBeVisible({ timeout: 15_000 });

        // Off: a swipe does nothing; the buttons still work.
        const off = prefsSaved(page);
        await drawer.locator('[data-a="review:swipe"]').selectOption('off');
        await off;
        await expect(drawer.locator('[data-a="review:swipe-hint"]')).toHaveCount(0);
        await swipe(-160);
        await swipe(160);
        await page.waitForTimeout(800);
        await expect(drawer.locator('[data-a="review:undo-bar"]')).toHaveCount(0);
        await expect(card).toBeVisible();

        // Persisted.
        expect(await page.evaluate(async () => (await window.axios.get('/preferences')).data.reviewDeck.swipe)).toBe('off');

        await resetAll(page);
        expect(errors).toEqual([]);
    });
});
