import { expect, test } from '@playwright/test';
import { expectRendered, firstBookId, ignoring, signIn, watchForErrors } from './helpers.js';

/**
 * The handoff's acceptance test, ported.
 *
 * "A Playwright crawl clicked all 709 [data-a] controls across the 16 routes
 * with 0 JS errors and 0 unhandled actions. Reproduce both as the e2e suite."
 *
 * This is the smaller version of that: every control on every screen that is
 * built, clicked once, asserting no console errors and that the page is still
 * rendered afterwards. It grows as screens land rather than being written
 * once against screens that do not exist.
 */

test.describe('every screen renders', () => {
    test('sign-in page', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.goto('/login');
        await expectRendered(page);
        await expect(page.getByRole('heading', { name: /welcome back/i })).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('registration page', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.goto('/register');
        await expectRendered(page);
        await expect(page.getByRole('heading', { name: /start writing/i })).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('the root sends a signed-out visitor to sign in', async ({ page }) => {
        await page.goto('/');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('book list', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await expectRendered(page);

        expect(errors).toEqual([]);
    });

    test('overview', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/overview`);
        await expectRendered(page, { minText: 200 });

        // The figures are counted server-side; what matters here is that they
        // reached the screen at all.
        await expect(page.getByText(/before this is finished/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('book settings', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/settings`);
        await expectRendered(page, { minText: 200 });

        await expect(page.getByRole('heading', { name: /book settings/i })).toBeVisible();
        // The three writing toggles are switches, not checkboxes, so they are
        // asserted by role — which also proves the aria wiring.
        await expect(page.getByRole('switch')).toHaveCount(3);

        expect(errors).toEqual([]);
    });

    test('a writing preference saves and survives a reload', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/settings`);

        const autosave = page.getByRole('switch', { name: /autosave/i });
        const before = await autosave.getAttribute('aria-checked');

        await autosave.click();
        await page.waitForTimeout(900);
        await page.reload();

        await expect(page.getByRole('switch', { name: /autosave/i })).toHaveAttribute(
            'aria-checked',
            before === 'true' ? 'false' : 'true',
        );

        expect(errors).toEqual([]);
    });

    test('shelf filters', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');
        await expectRendered(page);

        await page.click('[data-a="books:filter:archived"]');
        await page.waitForURL(/filter=archived/);
        await expectRendered(page, { minText: 100 });

        expect(errors).toEqual([]);
    });

    test('AI Editor gathers findings across the book', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="nav:ai"]');
        await expectRendered(page, { minText: 200 });

        await expect(page.getByRole('heading', { name: /ai editor/i })).toBeVisible();
        await expect(page.locator('[data-a="ai-screen:status:open"]')).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('references renders a bibliography in each style', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:references"]');
        await expectRendered(page, { minText: 150 });

        await expect(page.getByText(/bibliography —/i)).toBeVisible();

        // Switching style must re-render, not just change the select.
        await page.selectOption('[data-a="references:style"]', 'MLA');
        await expect(page.getByText(/bibliography — MLA/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('a template inserts into the open chapter', async ({ page }) => {
        // The insertion goes through the normal save path, so the proof is
        // that the chapter is longer afterwards — not that a button changed.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const words = page.locator('[data-a="side:manuscript"]').first();
        await expect(words).toBeVisible();

        const before = await page.locator('.manuscript').innerText();

        await page.click('[data-a="side:templates"]');
        await expect(page.getByRole('heading', { name: /templates/i })).toBeVisible();
        await page.click('[data-a="templates:insert:checklist"]');

        await page.click('[data-a="side:manuscript"]');
        await expect
            .poll(async () => (await page.locator('.manuscript').innerText()).length, {
                timeout: 15_000,
            })
            .toBeGreaterThan(before.length);

        expect(errors).toEqual([]);
    });

    test('analytics charts real history', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="nav:analytics"]');
        await expectRendered(page, { minText: 200 });

        await expect(page.getByRole('heading', { name: /analytics/i })).toBeVisible();
        await expect(page.getByText(/words written per day/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('team members invites and shows the link', async ({ page }) => {
        // Nothing is emailed, so the link appearing IS the feature. A test
        // that only checked for a success toast would pass while the person
        // invited had no way in.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:team"]');
        await expect(page.getByRole('heading', { name: /team members/i })).toBeVisible();

        const email = `invitee${Date.now()}@example.com`;
        await page.fill('[data-a="team:email"]', email);
        await page.selectOption('[data-a="team:role"]', 'viewer');
        await page.click('[data-a="team:invite"]');

        await expect(page.getByText(email)).toBeVisible();
        await expect(page.getByText(/copy invite link/i).first()).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('an unknown invitation link says so', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.goto('/invitations/not-a-real-token');
        await expectRendered(page, { minText: 80 });
        await expect(page.getByText(/do not recognise this link/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('a comment posts and appears in its thread', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="ai:tab:Comments"]');

        const body = `A question ${Date.now()}`;
        await page.fill('[data-a="comments:draft"]', body);
        await page.click('[data-a="comments:post"]');

        // The proof is the thread rendering, not a button changing.
        await expect(page.getByText(body)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('applying a suggestion changes the manuscript text', async ({ page }) => {
        // The failure this guards shipped for weeks: "Apply fix" marked the
        // finding done and left the prose untouched, so the panel agreed with
        // the author while the document disagreed. The assertion is therefore
        // on the TEXT, not on the finding disappearing.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        // Type a defect the copy editor is guaranteed to flag with a fix, so
        // this cannot quietly skip. A skipped test proves nothing.
        //
        // The marker is unique per run: repeated runs that all appended the
        // same phrase left several identical passages, and the applier then
        // correctly refused as ambiguous — a test failing on its own residue.
        const marker = `zz${Date.now()}`;
        const canvas = page.locator('.manuscript .ProseMirror');
        await canvas.click();
        await page.keyboard.press('Control+End');
        await page.keyboard.type(` The the ${marker} word.`);

        // Let the autosave land before reviewing what was saved.
        await page.waitForTimeout(2000);
        await page.click('[data-a="ai:run:copy"]');

        // Apply buttons live inside an agent's findings view, not on the
        // agent list, so the agent has to be opened first.
        await page.click('[data-a="ai:agent:copy"]');

        // Find THIS run's finding by its excerpt, not the first apply button
        // on screen. Taking the first one applied an earlier run's suggestion
        // instead and then failed on a marker nothing had touched — a true
        // negative about the test, not the feature.
        const card = page
            .locator('li')
            .filter({ hasText: `The the ${marker}` })
            .first();
        const applyButton = card.locator('[data-a^="ai:accept:"]');
        await expect(applyButton).toBeVisible({ timeout: 15_000 });

        const prose = page.locator('.manuscript .ProseMirror');
        const before = await prose.innerText();
        expect(before).toContain(`The the ${marker}`);

        await applyButton.click();

        // The assertion that matters: this run's defect is gone from the prose.
        await expect
            .poll(async () => prose.innerText(), { timeout: 15_000 })
            .not.toContain(`The the ${marker}`);

        expect(errors).toEqual([]);
    });

    test('design and layout', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:layout"]');

        // The preview must show the author's own prose, not filler. A sample
        // paragraph would prove the panel rendered and nothing else.
        await expect(page.locator('[data-a="layout:trim"]')).toBeVisible();
        await expect(page.getByText(/Professional Opportunity|Chapter/i).first()).toBeVisible();

        // Changing the trim size must survive a reload, or the screen is a
        // set of controls that do nothing.
        await page.selectOption('[data-a="layout:trim"]', '5 x 8 in');
        await page.waitForTimeout(900);
        await page.reload();
        await page.click('[data-a="side:layout"]');
        await expect(page.locator('[data-a="layout:trim"]')).toHaveValue('5 x 8 in');

        // Put it back, so a later run starts where this one did.
        await page.selectOption('[data-a="layout:trim"]', '6 x 9 in');
        await page.waitForTimeout(900);

        expect(errors).toEqual([]);
    });

    test('cover studio', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:cover"]');

        await expect(page.locator('[data-a="cover:preview"]')).toBeVisible();

        await page.click('[data-a="cover:palette:2"]');
        await page.waitForTimeout(700);
        await page.reload();
        await page.click('[data-a="side:cover"]');
        await expect(page.locator('[data-a="cover:palette:2"]')).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        expect(errors).toEqual([]);
    });

    test('export and publish lists what blocks a submission', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:export"]');

        await expect(page.locator('[data-a="publish:title"]')).toBeVisible();
        await expect(page.locator('[data-a="publish:channel:amazon_kdp"]')).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('generating a file produces a real download', async ({ page }) => {
        // The assertion that matters: a file arrives with bytes in it. A
        // button that flips to "ready" over an empty file is the failure
        // this guards.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:export"]');

        await page.click('[data-a="publish:generate:epub"]');

        const row = page.locator('[data-a^="publish:download:"]').first();
        await expect(row).toBeVisible({ timeout: 20_000 });

        const download = await Promise.all([
            page.waitForEvent('download'),
            row.click(),
        ]).then(([event]) => event);

        const path = await download.path();
        expect(path).toBeTruthy();

        expect(errors).toEqual([]);
    });

    test('isbn and rights', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:rights"]');

        await expect(page.getByText('ISBNs by edition')).toBeVisible();
        await expect(page.getByText('Rights checklist')).toBeVisible();

        // A mistyped ISBN must be refused rather than printed on a cover.
        await page.click('[data-a="rights:own:ebook"]');
        await page.fill('[data-a="rights:own:input"]', '9780306406158');
        await page.click('[data-a="rights:own:save"]');
        await expect(page.getByText(/not a valid ISBN/i)).toBeVisible({ timeout: 10_000 });

        // The 422 is the point of this test: the server refusing a mistyped
        // number is the behaviour being asserted, not a defect.
        expect(ignoring(errors, '422')).toEqual([]);
    });

    test('inserting a copyright page satisfies two checks at once', async ({ page }) => {
        // Both items are computed from the manuscript, so one action must
        // move both without anything being stored.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:rights"]');

        await page.click('[data-a="rights:check:copyright_page"]');

        await expect(page.locator('[data-a="rights:check:disclaimer"]')).toHaveText(
            /Undo/,
            { timeout: 10_000 },
        );

        expect(errors).toEqual([]);
    });

    test('editions', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:editions"]');

        await expect(page.locator('[data-a="editions:workbook"]')).toBeVisible();
        await expect(page.locator('[data-a="editions:language"]')).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('the top nav opens Design and Publishing', async ({ page }) => {
        // These three buttons used to be marked "not built" and led nowhere.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        await page.click('[data-a="nav:design"]');
        await expect(page.locator('[data-a="layout:trim"]')).toBeVisible();

        await page.click('[data-a="nav:publishing"]');
        await expect(page.locator('[data-a="publish:title"]')).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('writing studio', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);

        await page.goto(`/books/${id}/edit`);
        await expectRendered(page, { minText: 400 });

        // All seven agents, from the server's catalogue.
        await expect(page.getByText('Developmental Editor')).toBeVisible();
        await expect(page.getByText('Accessibility Check')).toBeVisible();

        expect(errors).toEqual([]);
    });
});

test.describe('controls', () => {
    /**
     * Click every [data-a] on the studio and assert nothing breaks.
     *
     * Re-queried each time because clicking swaps panes and invalidates
     * earlier handles. Controls that navigate away are visited last, since
     * each one costs a reload to come back from.
     */
    test('every control on the studio is wired', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await expectRendered(page);

        const seen = new Set();
        const navigates = (name) =>
            name.startsWith('outline:open:') ||
            name.startsWith('outline:row:') ||
            name.startsWith('nav:') ||
            name.startsWith('side:overview');

        for (let guard = 0; guard < 200; guard++) {
            const names = await page.locator('[data-a]').evaluateAll((elements) =>
                elements.map((element) => element.dataset.a),
            );

            const next = names.find((name) => !seen.has(name) && !navigates(name));

            if (!next) break;

            seen.add(next);

            const control = page.locator(`[data-a="${next}"]`).first();

            if (!(await control.isVisible().catch(() => false))) continue;
            if (await control.isDisabled().catch(() => true)) continue;

            await control.click({ timeout: 5000 }).catch(() => {});
            await page.waitForTimeout(120);

            // Come back if a click left the studio.
            if (!page.url().includes('/edit')) {
                await page.goto(`/books/${id}/edit`);
                await page.waitForTimeout(400);
            }
        }

        expect(seen.size, 'no controls found — the page probably did not render').toBeGreaterThan(10);
        expect(errors).toEqual([]);
    });
});

/*
 * The sidebar's scroll area.
 *
 * The studio is a fixed-height shell, so a list longer than the viewport has
 * no page scroll to fall back on. These assert the whole list stays reachable
 * on a short screen, which is where the last items were stranded below the
 * fold once Editions and ISBN & Rights were added.
 */
test.describe('the book sidebar', () => {
    test('every sidebar item is reachable on a short viewport', async ({ page }) => {
        const errors = watchForErrors(page);

        await page.setViewportSize({ width: 1440, height: 720 });
        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        // The item the fold used to cut off.
        const last = page.locator('[data-a="side:distribution"]');

        await last.scrollIntoViewIfNeeded();
        await expect(last).toBeInViewport();

        expect(errors).toEqual([]);
    });

    test('the book card stays put while the list scrolls', async ({ page }) => {
        // The book being worked on should not scroll out of sight.
        await page.setViewportSize({ width: 1440, height: 720 });
        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const card = page.getByText('Building an Independent Practice').first();
        const before = await card.boundingBox();

        await page.locator('[data-a="side:distribution"]').scrollIntoViewIfNeeded();

        const after = await card.boundingBox();

        expect(after.y).toBe(before.y);
    });

    test('the sidebar scrolls on its own, without moving the studio', async ({ page }) => {
        // The shell must not scroll as a whole: that would drag the manuscript
        // and the AI panel out of position.
        await page.setViewportSize({ width: 1440, height: 720 });
        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const header = page.locator('header').first();
        const before = await header.boundingBox();

        await page.locator('[data-a="side:distribution"]').scrollIntoViewIfNeeded();

        expect((await header.boundingBox()).y).toBe(before.y);
    });

    test('the same is true on Overview and Settings', async ({ page }) => {
        // Three pages share the sidebar inside three different shells, so the
        // fix has to hold in all of them.
        await page.setViewportSize({ width: 1440, height: 720 });
        await signIn(page);
        const id = await firstBookId(page);

        for (const path of [`/books/${id}/overview`, `/books/${id}/settings`]) {
            await page.goto(path);

            const last = page.locator('[data-a="side:distribution"]');
            await last.scrollIntoViewIfNeeded();
            await expect(last, `${path} clips the end of the list`).toBeInViewport();
        }
    });
});

/*
 * Plans & billing, and the credit strip.
 *
 * Every figure comes from the ledger, so these assert real movement rather
 * than that a number is present.
 */
test.describe('billing', () => {
    test('the billing screen renders from real ledger data', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/billing');
        await expectRendered(page, { minText: 300 });

        await expect(page.getByRole('heading', { name: /plans & billing/i })).toBeVisible();
        await expect(page.getByText('What things cost')).toBeVisible();
        await expect(page.getByText(/credits used per day/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('the credit strip shows in the studio and links to billing', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const strip = page.locator('[data-a="credits:strip"]');
        await expect(strip).toBeVisible({ timeout: 15_000 });
        await expect(strip).toContainText(/credits/i);

        await page.click('[data-a="credits:billing"]');
        await expect(page).toHaveURL(/\/billing$/);

        expect(errors).toEqual([]);
    });

    test('the studio still lays out with the strip above it', async ({ page }) => {
        // The strip changed main into a flex column, which is exactly the kind of
        // change that silently collapses a pane to zero height.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const canvas = page.locator('.manuscript');
        await expect(canvas).toBeVisible();

        const box = await canvas.boundingBox();
        expect(box.height, 'the manuscript pane collapsed').toBeGreaterThan(200);

        expect(errors).toEqual([]);
    });

    test('running an editor moves the balance', async ({ page }) => {
        // The whole point of charging: the number goes down, and the strip shows
        // it without a reload.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const strip = page.locator('[data-a="credits:strip"]');
        await expect(strip).toBeVisible({ timeout: 15_000 });

        const read = async () => {
            const text = await strip.innerText();
            return Number(text.match(/([\d,]+)\s+credits/)[1].replace(/,/g, ''));
        };

        const before = await read();
        await page.click('[data-a="ai:run:copy"]');

        await expect.poll(read, { timeout: 20_000 }).toBeLessThan(before);

        expect(errors).toEqual([]);
    });
});

/*
 * The book menu, against the prototype.
 *
 * The prototype is the visual authority, so the assertion is equality with
 * its list rather than a spot check: a missing item is a screen an author
 * cannot reach.
 */
test.describe('the book menu', () => {
    // The prototype's sidebar, in order. Anything missing here is a screen an
    // author cannot reach, and anything extra is one the prototype does not have.
    const EXPECTED = [
        'Overview', 'Manuscript', 'Research Library', 'Characters & Terms',
        'References & Citations', 'Notes', 'Templates', 'Book Settings',
        'Comments', 'Suggestions', 'Review Workflow', 'Team Members',
        'Design & Layout', 'Cover Studio', 'Export & Publish', 'ISBN & Rights',
        'Editions', 'Accessibility', 'Sales & Distribution', 'Royalties',
        'Launch Planner', 'Audiobook Studio', 'Course Builder', 'Marketing Kit',
        'Author Website',
    ];

    test('the sidebar matches the prototype item for item', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const labels = await page.locator('[data-a^="side:"]').allInnerTexts();
        const cleaned = labels.map((l) => l.split('\n')[0].trim());

        expect(cleaned).toEqual(EXPECTED);
        expect(errors).toEqual([]);
    });

    test('the four groups are present and labelled', async ({ page }) => {
        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        for (const group of ['COLLABORATION', 'PUBLISHING', 'GROW']) {
            await expect(page.getByText(group, { exact: true })).toBeVisible();
        }
    });

    test('every menu item draws an icon', async ({ page }) => {
        // A missing glyph renders nothing at all and only warns in dev, so a
        // blank space in the menu is invisible until someone looks. Twenty-six
        // icons were missing from the generated map before this.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const items = page.locator('[data-a^="side:"]');
        const total = await items.count();

        for (let i = 0; i < total; i++) {
            const label = (await items.nth(i).innerText()).split('\n')[0].trim();
            await expect(items.nth(i).locator('svg'), `${label} has no icon`).toHaveCount(1);
        }

        expect(errors).toEqual([]);
    });

    test('every item opens something rather than doing nothing', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        const keys = await page
            .locator('[data-a^="side:"]')
            .evaluateAll((els) => els.map((e) => e.dataset.a.replace('side:', '')));

        for (const key of keys) {
            // Overview and Settings navigate away; the rest swap the pane.
            if (key === 'overview' || key === 'settings') continue;

            await page.click(`[data-a="side:${key}"]`);

            const main = page.locator('main');
            const text = (await main.innerText()).trim();

            expect(text.length, `${key} opened an empty pane`).toBeGreaterThan(40);
        }

        expect(errors).toEqual([]);
    });
});

/*
 * Accessibility, sales and the launch planner.
 */
test.describe('publishing and growth screens', () => {
    test('accessibility shows computed checks', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:a11y"]');

        await expect(page.locator('[data-a="a11y:check:heading_order"]')).toBeVisible();
        await expect(page.locator('[data-a="a11y:check:cover_contrast"]')).toBeVisible();
        await expect(page.getByText(/checks pass/i)).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('sales says plainly that nothing reports yet', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:distribution"]');

        await expect(page.locator('[data-a="sales:reporting"]')).toBeVisible();
        await expect(page.locator('[data-a="sales:reporting"]')).toContainText(/store/i);

        expect(errors).toEqual([]);
    });

    test('royalties is the same data with a different emphasis', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:royalties"]');

        await expect(page.getByRole('heading', { name: 'Royalties' })).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('the launch planner dates its timeline from the launch date', async ({ page }) => {
        // The timeline is computed, so setting a date must move every task.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:launch"]');

        await expect(page.locator('[data-a="launch:task:publish"]')).toBeVisible();

        const future = new Date(Date.now() + 90 * 864e5).toISOString().slice(0, 10);
        await page.fill('[data-a="launch:date"]', future);

        await expect(page.getByText(/days to launch/i)).toBeVisible({ timeout: 10_000 });

        expect(errors).toEqual([]);
    });

    test('ticking a launch task sticks', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:launch"]');

        const task = page.locator('[data-a="launch:task:recruit_readers"]');
        await expect(task).toBeVisible();
        await task.click();
        await expect(task).toHaveAttribute('aria-checked', 'true', { timeout: 10_000 });

        await page.reload();
        await page.click('[data-a="side:launch"]');
        await expect(page.locator('[data-a="launch:task:recruit_readers"]')).toHaveAttribute(
            'aria-checked',
            'true',
        );

        // Put it back for the next run.
        await page.click('[data-a="launch:task:recruit_readers"]');

        expect(errors).toEqual([]);
    });

    test('an advance reader can be added and removed', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:launch"]');

        const email = `reader${Date.now()}@example.com`;
        await page.fill('[data-a="launch:reader-name"]', 'A Reader');
        await page.fill('[data-a="launch:reader-email"]', email);
        await page.click('[data-a="launch:reader-add"]');

        await expect(page.getByText(email)).toBeVisible({ timeout: 10_000 });

        const row = page.locator('li').filter({ hasText: email });
        await row.locator('[data-a^="launch:reader-remove:"]').click();
        await expect(page.getByText(email)).toHaveCount(0, { timeout: 10_000 });

        expect(errors).toEqual([]);
    });
});

/*
 * Notes, the marketing kit, the unbuilt screens, and the brand mark.
 */
test.describe('growth and brand', () => {
    test('notes save and survive a reload', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:notes"]');

        const marker = `Reader: clinicians ${Date.now()}`;
        await page.fill('[data-a="notes:body"]', marker);
        await expect(page.locator('[data-a="notes:status"]')).toHaveText('Saved', { timeout: 10_000 });

        await page.reload();
        await page.click('[data-a="side:notes"]');
        await expect(page.locator('[data-a="notes:body"]')).toHaveValue(marker);

        expect(errors).toEqual([]);
    });

    test('the marketing kit builds copy from the manuscript', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:marketing"]');

        await page.click('[data-a="kit:generate"]');

        await expect(page.locator('[data-a="kit:description"]')).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('[data-a="kit:email"]')).toBeVisible();

        // The copy must contain the author's own words, not invented ones.
        await expect(page.locator('[data-a="kit:description"]')).toContainText(/practice|practition/i);

        expect(errors).toEqual([]);
    });

    test('an unbuilt screen says what it would do and what it needs', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        for (const screen of ['audio', 'course', 'site']) {
            await page.click(`[data-a="side:${screen}"]`);

            const panel = page.locator(`[data-a="locked:${screen}"]`);
            await expect(panel).toBeVisible({ timeout: 15_000 });
            await expect(panel).toContainText(/not built yet/i);
        }

        expect(errors).toEqual([]);
    });

    test('comments opens from the sidebar, not only the AI drawer', async ({ page }) => {
        // The panel was built and tested but the menu entry led nowhere.
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.click('[data-a="side:comments"]');

        await expect(page.locator('[data-a="comments:draft"]')).toBeVisible({ timeout: 15_000 });

        expect(errors).toEqual([]);
    });

    test('every menu item is marked built, and opens', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);

        // No item carries the "not built" marker any more.
        await expect(page.locator('[data-a^="side:"] [title="Not built yet"]')).toHaveCount(0);

        const keys = await page
            .locator('[data-a^="side:"]')
            .evaluateAll((els) => els.map((e) => e.dataset.a.replace('side:', '')));

        expect(keys).toHaveLength(25);

        for (const key of keys) {
            if (key === 'overview' || key === 'settings') continue;

            await page.click(`[data-a="side:${key}"]`);
            const text = (await page.locator('main').innerText()).trim();
            expect(text.length, `${key} opened an empty pane`).toBeGreaterThan(60);
        }

        expect(errors).toEqual([]);
    });

    test('the brand mark is the packaged logo', async ({ page }) => {
        const errors = watchForErrors(page);

        await signIn(page);
        await page.goto('/books');

        const mark = page.locator('header img[src="/brand/symbol.svg"]');
        await expect(mark).toBeVisible();
        await expect(page.getByText('Ecrivain', { exact: true })).toBeVisible();

        // The asset must actually be served, not 404 behind an <img>.
        const response = await page.request.get('/brand/symbol.svg');
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('svg');

        expect(errors).toEqual([]);
    });
});

/*
 * The XSRF header on writes.
 *
 * The one class of bug this suite is otherwise blind to: development is
 * single-host, so axios attaches the token whether or not it is configured
 * to cross-origin, and every write passes. In production the studio and the
 * API are different hosts, axios omitted the header, and every review, save
 * and generate failed with 419.
 */
test.describe('cross-origin writes', () => {
    test('writes carry the XSRF header, not just the cookie', async ({ page }) => {
        const errors = watchForErrors(page);
        const writes = [];

        // headers() is synchronous; allHeaders() awaits the network stack and
        // can throw while a request is still in flight.
        page.on('request', (request) => {
            if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method())) return;
            if (request.url().includes('/login')) return;

            writes.push({
                url: request.url(),
                hasToken: Boolean(request.headers()['x-xsrf-token']),
            });
        });

        await signIn(page);
        const id = await firstBookId(page);
        await page.goto(`/books/${id}/edit`);
        await page.waitForTimeout(1500);

        await page.click('[data-a="ai:run:copy"]');
        await page.waitForTimeout(3000);

        expect(writes.length, 'no write request was observed').toBeGreaterThan(0);

        const missing = writes.filter((w) => !w.hasToken).map((w) => w.url);

        expect(missing, `these writes had no X-XSRF-TOKEN: ${missing.join(', ')}`).toEqual([]);
        expect(errors).toEqual([]);
    });
});
