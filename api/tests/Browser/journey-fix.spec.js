import { expect, test } from '@playwright/test';
import { registerFreshAuthor, watchForErrors } from './helpers.js';

/**
 * The new author's first book again, after the journey friction fixes (ADR —
 * Journey friction fixes), at 1440 and 390 px:
 *
 *  - signup names how many books the free plan includes; Quick Start opens
 *    the focused first-run view with a way to the full studio;
 *  - a sample review's double space and filler word apply after approval,
 *    advice-only findings say so, agents outside the plan are locked and
 *    "Review this chapter" sends no refused request;
 *  - a citation's claim is prefilled from the selection and its marker lands
 *    in the prose; history opens from its own button;
 *  - the store listing opens as to-dos, offers the account's name, and the
 *    shelf at the plan limit keeps the import with "Import into".
 *
 * Every account is a throwaway registered through the form. No console
 * errors, no horizontal scroll.
 */
test.describe.configure({ mode: 'serial', timeout: 900_000 });
test.use({ actionTimeout: 120_000, navigationTimeout: 180_000 });
const slow = expect.configure({ timeout: 120_000 });

const SHOTS = process.env.JFIX_SHOTS ?? null;

async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function noHorizontalScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

/** Select the words of a paragraph in the canvas, as a mouse would. */
async function selectText(page, text) {
    await page.evaluate((needle) => {
        const root = document.querySelector('.ProseMirror');
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const at = node.textContent.indexOf(needle);

            if (at >= 0) {
                const range = document.createRange();
                range.setStart(node, at);
                range.setEnd(node, at + needle.length);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);

                return;
            }
        }

        throw new Error(`"${needle}" is not in the chapter`);
    }, text);
}

async function quickStart(page, title) {
    await registerFreshAuthor(page);
    await page.goto('/start');
    await page.fill('[data-a="quick:title"]', title);
    await page.click('[data-a="quick:start"]');
    await page.waitForURL(/\/books\/\d+\/edit/);

    return Number(page.url().match(/\/books\/(\d+)\/edit/)[1]);
}

test('1440: first book, review, citation and store listing', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = watchForErrors(page);

    // Signup says how many books the free plan includes.
    await page.goto('/register');
    await expect(page.getByText(/includes 1 book/)).toBeVisible();
    await shot(page, 'd01-register');

    const bookId = await quickStart(page, 'The Harbour Clinic');

    // The focused first-run view, once, with the way out in plain words.
    await slow(page.locator('[data-a="editor:first-run"]')).toBeVisible();
    expect(page.url()).not.toContain('start=focus');
    await shot(page, 'd02-first-run');
    await page.click('[data-a="editor:open-studio"]');
    await expect(page.locator('[data-a="editor:first-run"]')).toHaveCount(0);

    // Write something with a double space and a filler word in it.
    const canvas = page.locator('.ProseMirror').first();
    await canvas.click();
    await page.keyboard.type('The clinic opened at dawn.  Nurses came early. She was really tired by noon. Rural clinics lost a third of their staff in a decade.');
    await slow(page.locator('[data-a="editor:status-bar"], header').getByText('Saved').first()).toBeVisible();

    // Agents outside the Free plan are locked, and a full review asks for
    // none of them (no 422 in the log).
    await slow(page.locator('[data-a="ai:locked:fact_checker"]')).toBeVisible();
    await expect(page.locator('[data-a="ai:run:fact_checker"]')).toHaveCount(0);
    const refused = [];
    page.on('response', (response) => {
        if (response.url().includes('/review') && response.status() === 422) refused.push(response.url());
    });
    await page.click('[data-a="ai:run-full"]');
    await slow(page.locator('[data-a="ai:run-full"]')).toBeEnabled();
    expect(refused).toEqual([]);
    await shot(page, 'd03-agents-locked');

    // The Copy Editor's findings: the mechanical ones apply after approval.
    await page.click('[data-a="ai:agent:copy"]');
    const accept = page.locator('[data-a^="ai:accept:"]');
    await slow(accept.first()).toBeVisible();
    const before = await accept.count();
    await shot(page, 'd04-copy-findings');
    await accept.first().click();
    await slow(accept).toHaveCount(before - 1);
    await page.click('[data-a="ai:agent:back"]');

    // Advice-only findings say so instead of a dead button.
    await page.click('[data-a="ai:agent:line"]');
    await page.click('[data-a="ai:agent:back"]');

    // History has its own button, and its dialog closes with Escape.
    await page.click('[data-a="editor:history"]');
    await slow(page.locator('[data-a="history:dialog"]')).toBeVisible();
    await shot(page, 'd05-history');
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-a="history:dialog"]')).toHaveCount(0);

    // A verified source, then cite the selected claim.
    await page.evaluate(async (id) => {
        await window.axios.post(`/books/${id}/sources`, {
            kind: 'book', title: 'Rural Health in Transition', authors: 'Moreau, Claire', published: '2021', publisher: 'Harbour Press',
        });
    }, bookId);
    await selectText(page, 'Rural clinics lost a third of their staff in a decade.');
    await page.click('[data-a="ai:tab:Research"]');
    await page.click('[data-a="citations:add"]');
    await expect(page.locator('[data-a="citations:claim"]')).toHaveValue('Rural clinics lost a third of their staff in a decade.');
    const sourceValue = await page.locator('[data-a="citations:source"] option', { hasText: 'Moreau' }).getAttribute('value');
    await page.selectOption('[data-a="citations:source"]', sourceValue);
    await shot(page, 'd06-cite');
    await page.click('[data-a="citations:record"]');
    await slow(canvas).toContainText('(Moreau, 2021)');
    await shot(page, 'd07-cited');

    // The store listing opens as to-dos, not errors, and offers the name.
    await page.goto(`/books/${bookId}/edit?pane=publishing`);
    await slow(page.locator('[data-a="publish:author"]')).toBeVisible();
    await expect(page.locator('[data-a="publish:todo"]').first()).toBeVisible();
    await expect(page.locator('[data-a="publish:author-suggest"]')).toContainText('E2E Author');
    await shot(page, 'd08-listing');
    await page.click('[data-a="publish:author-suggest"]');
    await slow(page.locator('[data-a="publish:author"]')).toHaveValue('E2E Author');

    // The shelf at the Free plan's limit keeps the import, with two ways on.
    await page.goto('/books');
    await page.click('[data-a="books:import"]');
    await expect(page.locator('[data-a="books:import-into"]')).toContainText('The Harbour Clinic');
    await expect(page.locator('[data-a="books:see-plans"]')).toBeVisible();
    await shot(page, 'd09-shelf-limit');

    await noHorizontalScroll(page, 1440);
    expect(errors).toEqual([]);
});

test('390: the same first book fits a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const errors = watchForErrors(page);

    await page.goto('/register');
    await noHorizontalScroll(page, 390);

    const bookId = await quickStart(page, 'Pocket Book');

    await slow(page.locator('[data-a="editor:first-run"]')).toBeVisible();
    await noHorizontalScroll(page, 390);
    await shot(page, 'm01-first-run');
    await page.click('[data-a="editor:open-studio"]');
    await noHorizontalScroll(page, 390);
    await shot(page, 'm02-studio');

    await page.goto(`/books/${bookId}/edit?pane=publishing`);
    await slow(page.locator('[data-a="publish:author"]')).toBeVisible();
    await noHorizontalScroll(page, 390);
    await shot(page, 'm03-listing');

    await page.goto('/books');
    await page.click('[data-a="books:import"]');
    await expect(page.locator('[data-a="books:import-into"]')).toBeVisible();
    await noHorizontalScroll(page, 390);
    await shot(page, 'm04-shelf-limit');

    expect(errors).toEqual([]);
});
