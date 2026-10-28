import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * The customisable writing workspace (ADR — customisable writing
 * workspace): every region of the studio is a panel the author can hide,
 * resize, collapse to icons and swap; layouts and named presets; canvas
 * options; all saved to the account and back after a reload.
 *
 * Every test starts from the default layout (DELETE /workspace) and leaves
 * the account on it, so the rest of the suite sees the studio it expects.
 */
test.describe.configure({ timeout: 240_000 });
test.use({ viewport: { width: 1440, height: 900 } });

const SHOTS = process.env.WS_SHOTS ?? null;

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` });
}

/** Back to the default layout, for the account and this book. */
const resetLayout = (page) =>
    page.evaluate(() => window.axios.delete('/workspace', { params: { book: window.location.pathname.match(/books\/(\d+)/)?.[1] } }));

async function openStudio(page, { fresh = true } = {}) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });

    if (fresh) {
        await resetLayout(page);
        await page.reload();
        await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    }

    return id;
}

async function openView(page) {
    if ((await page.locator('[data-a="view:menu"]').count()) === 0) {
        await page.click('[data-a="view:open"]');
    }

    await expect(page.locator('[data-a="view:menu"]')).toBeVisible();
}

/** A save of the layout that the server accepted. */
const saved = (page) =>
    page.waitForResponse((r) => r.url().endsWith('/workspace') && r.request().method() === 'PUT' && r.ok());

const REGIONS = {
    topNav: '[data-a="nav:writing"]',
    sidebar: '[data-ws-panel="sidebar"]',
    credits: '[data-ws-panel="credits"]',
    outline: '#ws-outline',
    right: '#ws-right',
    bottom: '#ws-bottom',
    toolbar: '[data-a="editor:toolbar-dock"]',
    statusBar: '[data-a="editor:status-bar"]',
};

async function expectShown(page, key, on) {
    const region = page.locator(REGIONS[key]);

    if (key === 'topNav' && !on) {
        await expect(region).toHaveCount(0);
    } else if (on) {
        await expect(region.first()).toBeVisible();
    } else {
        await expect(region.first()).toBeHidden();
    }
}

const widthOf = async (page, selector) => (await page.locator(selector).boundingBox()).width;

test('every panel hides and shows from View, labelled and pressed', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);
    await shot(page, '01-default');

    await openView(page);
    await shot(page, '02-view-menu');

    for (const key of Object.keys(REGIONS)) {
        const toggle = page.locator(`[data-a="view:panel:${key}"]`);

        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expectShown(page, key, false);

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        await expectShown(page, key, true);
    }

    // The trigger says whether the menu is open, and Escape returns to it.
    await expect(page.locator('[data-a="view:open"]')).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-a="view:menu"]')).toHaveCount(0);
    await expect(page.locator('[data-a="view:open"]')).toBeFocused();

    expect(errors).toEqual([]);
});

test('a hidden panel stays hidden after a reload, and reset brings everything back', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);
    await openView(page);

    let save = saved(page);
    await page.click('[data-a="view:panel:outline"]');
    await page.click('[data-a="view:panel:bottom"]');
    await page.click('[data-a="view:panel:sidebar"]');
    await save;
    // Debounced: wait for the last of them.
    await page.waitForTimeout(800);

    await page.reload();
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    await expectShown(page, 'outline', false);
    await expectShown(page, 'bottom', false);
    await expectShown(page, 'sidebar', false);
    await expectShown(page, 'right', true);

    await openView(page);
    await page.click('[data-a="view:reset"]');
    for (const key of ['outline', 'bottom', 'sidebar', 'right']) await expectShown(page, key, true);

    expect(errors).toEqual([]);
});

test('the panel shortcuts toggle, and focus never drops when a panel closes', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);

    // Focus inside the outline, then hide it from the keyboard.
    await page.locator('[data-a="outline:search"]').first().focus();
    await page.keyboard.press('Control+Backslash');
    await expectShown(page, 'outline', false);
    await expect(page.locator('.manuscript .ProseMirror')).toBeFocused();
    await page.keyboard.press('Control+Backslash');
    await expectShown(page, 'outline', true);

    await page.keyboard.press('Control+Alt+Backslash');
    await expectShown(page, 'right', false);
    await page.keyboard.press('Control+Alt+Backslash');
    await expectShown(page, 'right', true);

    await page.keyboard.press('Control+j');
    await expectShown(page, 'bottom', false);
    await page.keyboard.press('Control+j');
    await expectShown(page, 'bottom', true);

    // The shortcuts are listed in search, too.
    await page.keyboard.press('Control+k');
    await page.fill('[data-a="palette:input"]', 'chapter outline');
    await expect(page.locator('[data-a="palette:view:outline"]')).toContainText('Ctrl+\\');
    await page.click('[data-a="palette:view:outline"]');
    await expectShown(page, 'outline', false);
    await page.keyboard.press('Control+Backslash');

    // Distraction-free keeps its shortcut and its Escape.
    await page.keyboard.press('Control+Shift+F');
    await expect(page.locator('header [data-a="nav:writing"]')).toHaveCount(0);
    await expectShown(page, 'outline', false);
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-a="nav:writing"]')).toBeVisible();
    await expectShown(page, 'outline', true);

    expect(errors).toEqual([]);
});

test('splitters resize with the mouse and the keyboard, and the size is kept', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);

    const outline = page.locator('[data-a="splitter:outline"]');
    await expect(outline).toHaveAttribute('role', 'separator');
    await expect(outline).toHaveAttribute('aria-valuenow', '268');
    expect(Math.round(await widthOf(page, '#ws-outline'))).toBe(268);

    // Mouse: drag 80px to the right.
    const box = await outline.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 40, box.y + box.height / 2, { steps: 4 });
    await page.mouse.move(box.x + box.width / 2 + 80, box.y + box.height / 2, { steps: 4 });
    const save = saved(page);
    await page.mouse.up();
    await expect(outline).toHaveAttribute('aria-valuenow', '348');
    expect(Math.round(await widthOf(page, '#ws-outline'))).toBe(348);
    await save;

    // Keyboard: arrows step 16, Shift 64, Home to the minimum.
    await outline.focus();
    await page.keyboard.press('ArrowLeft');
    await expect(outline).toHaveAttribute('aria-valuenow', '332');
    await page.keyboard.press('Shift+ArrowRight');
    await expect(outline).toHaveAttribute('aria-valuenow', '396');
    await page.keyboard.press('Home');
    await expect(outline).toHaveAttribute('aria-valuenow', '200');

    // The right panel's handle is on its left: ArrowLeft grows it.
    const right = page.locator('[data-a="splitter:right"]');
    await right.focus();
    await page.keyboard.press('ArrowLeft');
    await expect(right).toHaveAttribute('aria-valuenow', '408');

    // The bottom row: ArrowUp grows it.
    const bottom = page.locator('[data-a="splitter:bottom"]');
    await expect(bottom).toHaveAttribute('aria-orientation', 'horizontal');
    await bottom.focus();
    await page.keyboard.press('ArrowUp');
    await expect(bottom).toHaveAttribute('aria-valuenow', '252');
    await page.waitForTimeout(900);

    await page.reload();
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    await expect(page.locator('[data-a="splitter:outline"]')).toHaveAttribute('aria-valuenow', '200');
    await expect(page.locator('[data-a="splitter:right"]')).toHaveAttribute('aria-valuenow', '408');
    await expect(page.locator('[data-a="splitter:bottom"]')).toHaveAttribute('aria-valuenow', '252');

    // Double-click: back to the default.
    await page.locator('[data-a="splitter:outline"]').dblclick();
    await expect(page.locator('[data-a="splitter:outline"]')).toHaveAttribute('aria-valuenow', '268');

    // Enter collapses to the icon rail; focus moves to the rail's expand
    // button, which brings the panel back.
    await page.locator('[data-a="splitter:outline"]').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-a="rail:outline"]')).toBeVisible();
    expect(Math.round(await widthOf(page, '#ws-outline'))).toBe(44);
    await expect(page.locator('[data-a="rail:outline:expand"]')).toBeFocused();
    await expect(page.locator('[data-a="rail:outline:expand"]')).toHaveAttribute('aria-expanded', 'false');
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-a="rail:outline"]')).toHaveCount(0);
    expect(Math.round(await widthOf(page, '#ws-outline'))).toBe(268);

    // The AI panel's rail opens straight to a tab.
    await page.locator('[data-a="splitter:right"]').focus();
    await page.keyboard.press('Enter');
    await page.click('[data-a="rail:right:comments"]');
    await expect(page.locator('#ws-right [data-a="ai:tab:Comments"]')).toHaveAttribute('aria-selected', 'true');

    await resetLayout(page);
    expect(errors).toEqual([]);
});

test('each layout preset arranges the studio', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);
    await openView(page);

    const expectLayout = async (layout) => {
        for (const [key, on] of Object.entries(layout)) await expectShown(page, key, on);
    };

    await page.click('[data-a="view:preset:write"]');
    await expectLayout({ outline: true, right: false, bottom: false });
    await expect(page.locator('[data-a="view:preset:write"]')).toHaveAttribute('aria-pressed', 'true');

    await page.click('[data-a="view:preset:edit"]');
    await expectLayout({ outline: false, right: true, bottom: false });
    await expect(page.locator('#ws-right [data-a="ai:tab:AI Editor"]')).toHaveAttribute('aria-selected', 'true');

    await page.click('[data-a="view:preset:research"]');
    await expectLayout({ outline: false, right: true });
    await expect(page.locator('#ws-right [data-a="ai:tab:Research"]')).toHaveAttribute('aria-selected', 'true');

    await page.click('[data-a="view:preset:focus"]');
    await expectLayout({ topNav: false, sidebar: false, credits: false, outline: false, right: false, bottom: false, toolbar: true });
    // Centred, at the medium measure.
    const prose = await page.locator('.manuscript .ProseMirror').boundingBox();
    const canvas = await page.locator('.manuscript').boundingBox();
    expect(Math.round(prose.width)).toBeLessThanOrEqual(720);
    expect(Math.abs((prose.x - canvas.x) - (canvas.x + canvas.width - prose.x - prose.width))).toBeLessThan(24);
    await page.keyboard.press('Escape');
    await shot(page, '03-focus-preset');

    await openView(page);
    await page.click('[data-a="view:preset:everything"]');
    await expectLayout({ topNav: true, sidebar: true, credits: true, outline: true, right: true, bottom: true, toolbar: true, statusBar: true });

    // A change by hand is no longer the preset.
    await page.click('[data-a="view:panel:bottom"]');
    await expect(page.locator('[data-a="view:preset:everything"]')).toHaveAttribute('aria-pressed', 'false');

    await resetLayout(page);
    expect(errors).toEqual([]);
});

test('the author saves a layout of their own and gets it back', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);
    await openView(page);

    // Drop any copy left by an earlier run.
    for (const stale of await page.locator('[data-a="view:mine:delete:E2E drafting"]').all()) await stale.click();

    await page.click('[data-a="view:panel:right"]');
    await page.click('[data-a="view:swap"]');
    await page.click('[data-a="view:width:narrow"]');
    await page.click('[data-a="view:save-as"]');
    await page.fill('[data-a="view:preset-name"]', 'E2E drafting');
    await page.click('[data-a="view:preset-save"]');
    await expect(page.locator('[data-a="view:mine:E2E drafting"]')).toHaveAttribute('aria-pressed', 'true');

    // Swapped: the outline is to the right of the manuscript.
    const outlineX = (await page.locator('#ws-outline').boundingBox()).x;
    const manuscriptX = (await page.locator('.manuscript').boundingBox()).x;
    expect(outlineX).toBeGreaterThan(manuscriptX);
    await page.keyboard.press('Escape');
    await shot(page, '04-custom-layout');

    await openView(page);
    await page.click('[data-a="view:preset:everything"]');
    await expectShown(page, 'right', true);

    await page.reload();
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    await openView(page);
    await page.click('[data-a="view:mine:E2E drafting"]');
    await expectShown(page, 'right', false);
    expect((await page.locator('#ws-outline').boundingBox()).x).toBeGreaterThan((await page.locator('.manuscript').boundingBox()).x);
    expect(Math.round((await page.locator('.manuscript .ProseMirror').boundingBox()).width)).toBeLessThanOrEqual(560);

    await page.click('[data-a="view:mine:delete:E2E drafting"]');
    await expect(page.locator('[data-a="view:mine:E2E drafting"]')).toHaveCount(0);

    await resetLayout(page);
    expect(errors).toEqual([]);
});

test('canvas width, font, size and spacing change the writing view and are kept', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);
    await openView(page);

    const prose = page.locator('.manuscript .ProseMirror');
    const style = (property) => prose.evaluate((el, p) => getComputedStyle(el)[p], property);

    // Room for the widest measure: the side panels out of the way.
    await page.click('[data-a="view:panel:outline"]');
    await page.click('[data-a="view:panel:right"]');

    await page.click('[data-a="view:width:narrow"]');
    expect(Math.round((await prose.boundingBox()).width)).toBe(560);

    await page.click('[data-a="view:width:custom"]');
    await page.fill('[data-a="view:custom-width"]', '640');
    await page.locator('[data-a="view:custom-width"]').press('Enter');
    await expect.poll(async () => Math.round((await prose.boundingBox()).width)).toBe(640);

    await page.selectOption('[data-a="view:font"]', 'sans');
    expect(await style('fontFamily')).toContain('Inter');
    await page.selectOption('[data-a="view:size"]', '20');
    expect(await style('fontSize')).toBe('20px');
    await page.selectOption('[data-a="view:leading"]', '1.8');
    expect(await style('lineHeight')).toBe('36px');

    await page.click('[data-a="view:background:paper"]');
    await expect(page.locator('.manuscript')).toHaveAttribute('data-paper', 'true');
    await page.click('[data-a="view:page"]');
    await expect(page.locator('.manuscript')).toHaveAttribute('data-page', 'true');

    // Floating: no bar until text is selected.
    await page.click('[data-a="view:toolbar:floating"]');
    await page.keyboard.press('Escape');
    const formatting = page.locator('[data-a="editor:toolbar-dock"] [role="toolbar"]');
    await expect(formatting).toBeHidden();
    await prose.click();
    await page.keyboard.press('Shift+End');
    await expect(formatting).toBeVisible();
    await expect(page.locator('[data-a="editor:toolbar-dock"]')).toHaveAttribute('data-floating', 'true');
    await page.waitForTimeout(900);

    await page.reload();
    await expect(prose).toBeVisible({ timeout: 25_000 });
    expect(Math.round((await prose.boundingBox()).width)).toBe(640);
    expect(await style('fontFamily')).toContain('Inter');
    expect(await style('fontSize')).toBe('20px');
    await expect(page.locator('.manuscript')).toHaveAttribute('data-page', 'true');

    await resetLayout(page);
    expect(errors).toEqual([]);
});

test('sub-panels: tabs, rows and cards inside the panels', async ({ page }) => {
    const errors = watchForErrors(page);
    await openStudio(page);
    await openView(page);

    await page.click('[data-a="view:sub:rightTabs.comments"]');
    await expect(page.locator('#ws-right [data-a="ai:tab:Comments"]')).toHaveCount(0);
    await page.click('[data-a="view:sub:bottomCards.cover"]');
    await expect(page.locator('[data-a="bottom:cover"]')).toHaveCount(0);
    await page.click('[data-a="view:sub:outlineTabs"]');
    await expect(page.locator('#ws-outline [data-a="outline:tab:Manuscript"]')).toHaveCount(0);
    await page.click('[data-a="view:sub:outlineTools"]');
    await expect(page.locator('#ws-outline [data-a="outline:search"]')).toBeHidden();

    // The last tab of the AI panel stays.
    for (const tab of ['editor', 'research']) await page.click(`[data-a="view:sub:rightTabs.${tab}"]`);
    await expect(page.locator('[data-a="view:sub:rightTabs.checks"]')).toBeDisabled();

    await resetLayout(page);
    expect(errors).toEqual([]);
});

test('a layout for this book only leaves the author\'s other books alone', async ({ page }) => {
    const errors = watchForErrors(page);
    const id = await openStudio(page);

    await page.goto('/books');
    const hrefs = await page.locator('a[href*="/edit"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
    const other = hrefs.map((h) => h.match(/books\/(\d+)/)?.[1]).find((i) => i && i !== String(id)) ?? null;
    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript .ProseMirror')).toBeVisible({ timeout: 25_000 });
    test.skip(!other, 'needs a second book');

    await openView(page);
    await page.click('[data-a="view:book-only"]');
    await expect(page.locator('[data-a="view:book-only"]')).toBeChecked();
    const save = saved(page);
    await page.click('[data-a="view:width:narrow"]');
    await save;

    await page.goto(`/books/${other}/edit`);
    await expect(page.locator('.manuscript')).toBeVisible({ timeout: 25_000 });
    await expect(page.locator('.manuscript')).not.toHaveAttribute('data-measure', 'true');

    await page.goto(`/books/${id}/edit`);
    await expect(page.locator('.manuscript')).toHaveAttribute('data-measure', 'true', { timeout: 25_000 });
    await openView(page);
    await page.click('[data-a="view:book-only"]');
    await expect(page.locator('[data-a="view:book-only"]')).not.toBeChecked();
    await expect(page.locator('.manuscript')).not.toHaveAttribute('data-measure', 'true');

    await resetLayout(page);
    expect(errors).toEqual([]);
});

test.describe('tablet', () => {
    test.use({ viewport: { width: 1024, height: 768 } });

    test('panels toggle and nothing scrolls sideways', async ({ page }) => {
        const errors = watchForErrors(page);
        await openStudio(page);
        await openView(page);

        for (const key of ['outline', 'bottom', 'sidebar', 'topNav']) {
            await page.click(`[data-a="view:panel:${key}"]`);
            await expectShown(page, key, false);
            await page.click(`[data-a="view:panel:${key}"]`);
            await expectShown(page, key, true);
        }

        // Below 1280 the AI panel is a drawer: its switch takes the pill
        // away, and its shortcut opens the drawer.
        await page.click('[data-a="view:panel:right"]');
        await expect(page.locator('[data-a="mobile:ai-pill"]')).toHaveCount(0);
        await page.click('[data-a="view:panel:right"]');
        await expect(page.locator('[data-a="mobile:ai-pill"]')).toBeVisible();
        await page.keyboard.press('Escape');
        await page.keyboard.press('Control+Alt+Backslash');
        await expect(page.locator('[data-a="drawer:ai"]')).toBeVisible();
        await page.keyboard.press('Escape');

        const outline = page.locator('[data-a="splitter:outline"]');
        await outline.focus();
        await page.keyboard.press('ArrowRight');
        await expect(outline).toHaveAttribute('aria-valuenow', '284');

        const overflow = await page.evaluate(() => document.body.scrollWidth - window.innerWidth);
        expect(overflow).toBeLessThanOrEqual(1);

        await resetLayout(page);
        expect(errors).toEqual([]);
    });
});

test.describe('phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('View is there with the canvas and the bars, and nothing scrolls sideways', async ({ page }) => {
        const errors = watchForErrors(page);
        await openStudio(page);

        await page.click('[data-a="view:open"]');
        await expect(page.locator('[data-a="view:menu"]')).toBeVisible();
        await expect(page.locator('[data-a="view:panel:statusBar"]')).toBeVisible();
        await expect(page.locator('[data-a="view:panel:toolbar"]')).toBeVisible();
        await expect(page.locator('[data-a="view:width:narrow"]')).toBeVisible();
        // The desktop panels are not offered on a phone.
        await expect(page.locator('[data-a="view:panel:outline"]')).toBeHidden();
        await shot(page, '05-phone-view');

        await page.click('[data-a="view:panel:statusBar"]');
        await expect(page.locator('[data-a="editor:status-bar"]')).toBeHidden();
        await page.click('[data-a="view:panel:statusBar"]');
        await expect(page.locator('[data-a="editor:status-bar"]')).toBeVisible();

        const overflow = await page.evaluate(() => document.body.scrollWidth - window.innerWidth);
        expect(overflow).toBeLessThanOrEqual(1);

        await page.click('[data-a="view:close"]');
        await expect(page.locator('[data-a="view:menu"]')).toHaveCount(0);
        // The tab bar is still the phone's shell.
        await expect(page.locator('[data-a="mobile:tabs"]')).toBeVisible();

        await resetLayout(page);
        expect(errors).toEqual([]);
    });
});
