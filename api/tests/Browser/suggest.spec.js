import { expect, test } from '@playwright/test';
import { firstBookId, signIn, watchForErrors } from './helpers.js';

/**
 * Suggest mode and comments from a selection.
 *
 * What is typed and deleted while suggesting is kept as a suggestion — in
 * the editor, in the database (it survives a reload) and out of the exports
 * until somebody accepts it. Exports are read back through the Markdown
 * export, the manuscript as the book prints it.
 */
test.describe.configure({ timeout: 240_000 });

const editor = (page) => page.locator('.manuscript .ProseMirror');
const saved = (page) => expect(page.locator('.manuscript [role="status"]')).toHaveText(/Saved/, { timeout: 20_000 });

async function exported(page, id) {
    return (await page.request.get(`/books/${id}/export.md`)).text();
}

/** Open the first book's first chapter with Suggest mode on. */
async function suggesting(page) {
    await signIn(page);
    const id = await firstBookId(page);
    await page.goto(`/books/${id}/edit`);
    await expect(editor(page)).toBeVisible({ timeout: 25_000 });
    await page.waitForLoadState('networkidle');

    const toggle = page.locator('[data-a="editor:suggest"]');

    if ((await toggle.getAttribute('aria-pressed')) !== 'true') await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-a="editor:suggesting"]')).toBeVisible();

    // Start clean: a change an earlier, failed run left behind is rejected.
    const chip = page.locator('[data-a="editor:changes"]');

    if (await chip.isVisible().catch(() => false)) {
        await chip.click();
        await page.locator('[data-a="changes:reject-all"]:visible').click();
        await expect(page.locator('[data-a="changes:list"]:visible')).toHaveCount(0);
        if (await page.getByRole('dialog', { name: 'AI Editor' }).isVisible().catch(() => false)) await page.keyboard.press('Escape');
        await saved(page);
    }

    return id;
}

/** A paragraph with text in it: the nth one long enough to edit. */
async function paragraph(page, nth = 0) {
    const all = editor(page).locator('p');
    const count = await all.count();
    let seen = 0;

    for (let i = 0; i < count; i += 1) {
        const text = (await all.nth(i).innerText()).trim();

        if (text.length > 20 && !(await all.nth(i).locator('ins, del').count()) && seen++ === nth) return all.nth(i);
    }

    throw new Error('No paragraph to edit.');
}

/** The caret at the very start of a paragraph (Home only reaches the line). */
async function atStart(target) {
    await target.click({ position: { x: 1, y: 6 } });
}

test.describe('desktop', () => {
    test('typing and deleting are tracked, survive a reload, and stay out of exports until accepted', async ({ page }) => {
        const errors = watchForErrors(page);
        const id = await suggesting(page);
        const target = await paragraph(page);
        const original = (await target.innerText()).trim();
        const head = original.slice(0, 4);
        const marker = `SUGGESTED${Date.now()}`;

        // Typed at the start: an insertion, underlined.
        await atStart(target);
        await page.keyboard.type(`${marker} `);
        await expect(editor(page).locator('ins.suggest-ins', { hasText: marker })).toBeVisible();

        // The next four letters deleted: struck through, still there, one
        // change.
        for (let i = 0; i < 4; i += 1) await page.keyboard.press('Delete');
        const struck = editor(page).locator('del.suggest-del');
        await expect(struck).toHaveCount(1);
        await expect(struck).toHaveText(head);
        await expect(target).toContainText(original.slice(0, 20));

        await saved(page);

        // Pending suggestions are not the book.
        const before = await exported(page, id);
        expect(before).not.toContain(marker);
        expect(before).toContain(original.slice(0, 30));

        // Kept by the server, with who made them.
        await page.reload();
        await expect(editor(page)).toBeVisible({ timeout: 25_000 });
        const ins = editor(page).locator('ins.suggest-ins', { hasText: marker });
        await expect(ins).toBeVisible();
        await expect(editor(page).locator('del.suggest-del', { hasText: head })).toHaveCount(1);
        await expect(ins).toHaveAttribute('data-author', /.+/);

        // Hover the insertion: its card, then Accept.
        await ins.hover();
        const card = page.locator('[data-a="suggest:popover"]');
        await expect(card).toBeVisible();
        await card.locator('[data-a="suggest:accept"]').click();
        await expect(editor(page).locator('ins.suggest-ins', { hasText: marker })).toHaveCount(0);
        await expect(editor(page)).toContainText(marker);

        // Reject the deletion: the text is back, unmarked.
        await editor(page).locator('del.suggest-del', { hasText: head }).hover();
        await card.locator('[data-a="suggest:reject"]').click();
        await expect(editor(page).locator('del.suggest-del')).toHaveCount(0);

        await saved(page);
        await expect.poll(() => exported(page, id), { timeout: 15_000 }).toContain(marker);
        expect(await exported(page, id)).toContain(original.slice(0, 30));

        // Each decision is a version, labelled with what was decided.
        const section = new URL(page.url()).searchParams.get('section');
        if (section) {
            const versions = await (await page.request.get(`/sections/${section}/versions`)).json();
            expect(versions.versions.some((v) => v.reason === 'review' && /Accepted 1 suggestion/.test(v.label ?? ''))).toBe(true);
        }

        expect(errors).toEqual([]);
    });

    test('the changes list accepts all, and the decision is saved', async ({ page }) => {
        const errors = watchForErrors(page);
        const id = await suggesting(page);
        const target = await paragraph(page);
        const other = await paragraph(page, 1);
        const first = `ALPHA${Date.now()}`;
        const second = `BETA${Date.now()}`;

        await atStart(target);
        await page.keyboard.type(`${first} `);
        // Somewhere else: a second change.
        await atStart(other);
        await page.keyboard.type(`${second} `);
        await expect(editor(page).locator('ins.suggest-ins', { hasText: first })).toHaveCount(1);
        await expect(editor(page).locator('ins.suggest-ins', { hasText: second })).toHaveCount(1);

        await page.click('[data-a="editor:changes"]');
        const list = page.locator('[data-a="changes:list"]:visible');
        await expect(list).toBeVisible();
        await expect(list.locator('[data-a^="changes:item:"]')).toHaveCount(2);
        await expect(page.locator('[data-a="editor:changes"]')).toContainText('2');

        await list.locator('[data-a="changes:accept-all"]').click();
        await expect(editor(page).locator('ins.suggest-ins')).toHaveCount(0);
        await expect(list).toHaveCount(0);
        await saved(page);

        await page.reload();
        await expect(editor(page)).toBeVisible({ timeout: 25_000 });
        await expect(editor(page)).toContainText(first);
        await expect(editor(page).locator('ins.suggest-ins')).toHaveCount(0);

        const md = await exported(page, id);
        expect(md).toContain(first);
        expect(md).toContain(second);

        // Rejecting all takes typed suggestions back out.
        const third = `GAMMA${Date.now()}`;
        await atStart(await paragraph(page));
        await page.keyboard.type(`${third} `);
        await page.click('[data-a="editor:changes"]');
        await page.locator('[data-a="changes:reject-all"]:visible').click();
        await expect(editor(page)).not.toContainText(third);

        expect(errors).toEqual([]);
    });
});

test.describe('phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('suggesting on a phone: type, tap the change, accept it', async ({ page }) => {
        const errors = watchForErrors(page);
        await suggesting(page);
        const target = await paragraph(page);
        const marker = `PHONE${Date.now()}`;

        await target.tap({ position: { x: 1, y: 6 } });
        await page.keyboard.type(`${marker} `);
        const ins = editor(page).locator('ins.suggest-ins', { hasText: marker });
        await expect(ins).toBeVisible();

        await ins.tap();
        const card = page.locator('[data-a="suggest:popover"]');
        await expect(card).toBeVisible();
        await card.locator('[data-a="suggest:accept"]').tap();
        await expect(editor(page).locator('ins.suggest-ins', { hasText: marker })).toHaveCount(0);
        await expect(editor(page)).toContainText(marker);
        await saved(page);

        // The list is in the AI drawer's Comments tab.
        await page.keyboard.type('Z');
        await page.locator('[data-a="editor:changes"]').tap();
        const drawer = page.getByRole('dialog', { name: 'AI Editor' });
        await expect(drawer).toBeVisible();
        await expect(drawer.locator('[data-a="changes:list"]')).toBeVisible();
        await drawer.locator('[data-a="changes:reject-all"]').tap();
        await expect(drawer.locator('[data-a="changes:list"]')).toHaveCount(0);

        expect(errors).toEqual([]);
    });

    test('a comment from a selection, then its thread from the text', async ({ page }) => {
        const errors = watchForErrors(page);
        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await expect(editor(page)).toBeVisible({ timeout: 25_000 });
        await page.waitForLoadState('networkidle');

        const target = await paragraph(page);
        await target.tap();
        await page.keyboard.press('Home');
        for (let i = 0; i < 12; i += 1) await page.keyboard.press('Shift+ArrowRight');

        const bubble = page.locator('[data-a="selection:bubble"]');
        await expect(bubble).toBeVisible();
        await bubble.locator('[data-a="selection:comment"]').tap();

        const dialog = page.locator('[data-a="comment:dialog"]');
        await expect(dialog).toBeVisible();
        const body = `Phone comment ${Date.now()}`;
        await dialog.locator('[data-a="comment:body"]').fill(body);
        await dialog.locator('[data-a="comment:post"]').tap();
        await expect(dialog).toHaveCount(0);

        // Highlighted in the text; a tap opens the thread.
        const anchor = editor(page).locator('.comment-anchor').first();
        await expect(anchor).toBeVisible();

        let opened = false;
        for (const candidate of await editor(page).locator('.comment-anchor').all()) {
            await candidate.tap();
            const sheet = page.locator('[data-a="thread:sheet"]');

            if (await sheet.isVisible().catch(() => false) && (await sheet.innerText()).includes(body)) {
                opened = true;
                break;
            }

            const popover = page.locator('[data-a="comment:popover"]');
            if (await popover.isVisible().catch(() => false)) {
                await popover.locator('[data-a="comment:open-thread"]').tap();
                if ((await page.locator('[data-a="thread:sheet"]').innerText()).includes(body)) {
                    opened = true;
                    break;
                }
            }

            if (await sheet.isVisible().catch(() => false)) await page.keyboard.press('Escape');
        }
        expect(opened).toBe(true);

        const sheet = page.locator('[data-a="thread:sheet"]');
        await sheet.locator('[data-a="thread:reply-body"]').fill('A reply from the phone');
        await sheet.locator('[data-a="thread:reply"]').tap();
        await expect(sheet).toContainText('A reply from the phone');

        await sheet.locator('[data-a="thread:resolve"]').tap();
        await expect(sheet.locator('[data-a="thread:resolve"]')).toHaveText('Reopen');

        // The server has it, resolved, with the reply.
        const threads = (await (await page.request.get(`/books/${id}/comments`)).json()).threads;
        const mine = threads.find((thread) => thread.body === body);
        expect(mine?.status).toBe('resolved');
        expect(mine?.replies?.[0]?.body).toBe('A reply from the phone');
        expect(mine?.excerpt?.length).toBeGreaterThan(5);

        expect(errors).toEqual([]);
    });
});
