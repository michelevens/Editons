import { expect, test } from '@playwright/test';
import { signIn, watchForErrors } from './helpers.js';

/**
 * Notification settings on the profile (ADR — Notification frequency,
 * channels, mutes and digests): a frequency and an inbox switch per kind of
 * activity, the digest day and hour, quiet hours, and books muted from
 * their shelf card. At desktop and phone widths, with no console errors and
 * nothing wider than the screen.
 *
 * SHOTS, when set, is a directory the screenshots are written to.
 */
test.describe.configure({ timeout: 180_000 });

const shot = (page, name) => (process.env.SHOTS ? page.screenshot({ path: `${process.env.SHOTS}/${name}.png`, fullPage: true }) : null);

const DEFAULTS = {
    frequency: { collaboration: 'instant', writing: 'instant', publishing: 'instant', sales: 'instant', partners: 'instant' },
    inbox: { collaboration: true, writing: true, publishing: true, partners: true },
    quiet_hours: null,
    digest_day: 1,
    digest_hour: 8,
    preferences: { digest: false },
};

/** Put the e2e account back on the defaults, through the form's own endpoint. */
async function reset(page) {
    await page.evaluate((body) => window.axios.patch('/profile/email-preferences', body), DEFAULTS);
}

async function noSideScroll(page, width) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

for (const [label, viewport] of [
    ['desktop', { width: 1440, height: 1000 }],
    ['phone', { width: 390, height: 844 }],
]) {
    test(`chooses frequency, inbox, schedule and quiet hours on the profile (${label})`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = watchForErrors(page);
        await signIn(page);
        await page.goto('/profile');
        await reset(page);
        await page.reload();

        const form = page.locator('#email-preferences');
        await form.scrollIntoViewIfNeeded();
        await expect(page.locator('[data-a="profile:notify:frequency:collaboration"]')).toHaveValue('instant');
        await expect(page.locator('[data-a="profile:email:digest"]')).toHaveAttribute('aria-checked', 'false');
        await shot(page, `notify-profile-defaults-${label}`);

        await page.locator('[data-a="profile:notify:frequency:collaboration"]').selectOption('daily');
        await page.locator('[data-a="profile:notify:frequency:sales"]').selectOption('off');
        await page.locator('[data-a="profile:notify:inbox:publishing"]').uncheck();
        await page.locator('[data-a="profile:email:digest"]').click();
        await page.locator('[data-a="profile:notify:day"]').selectOption('5');
        await page.locator('[data-a="profile:notify:hour"]').selectOption('18');
        await page.locator('[data-a="profile:notify:quiet-on"]').check();
        await page.locator('[data-a="profile:notify:quiet-end"]').selectOption('6');
        await noSideScroll(page, viewport.width);
        await shot(page, `notify-profile-changed-${label}`);

        await page.locator('[data-a="profile:email:save"]').click();
        await expect(form.getByRole('status')).toBeVisible();

        await page.reload();
        await expect(page.locator('[data-a="profile:notify:frequency:collaboration"]')).toHaveValue('daily');
        await expect(page.locator('[data-a="profile:notify:frequency:sales"]')).toHaveValue('off');
        await expect(page.locator('[data-a="profile:notify:inbox:publishing"]')).not.toBeChecked();
        await expect(page.locator('[data-a="profile:email:digest"]')).toHaveAttribute('aria-checked', 'true');
        await expect(page.locator('[data-a="profile:notify:day"]')).toHaveValue('5');
        await expect(page.locator('[data-a="profile:notify:hour"]')).toHaveValue('18');
        await expect(page.locator('[data-a="profile:notify:quiet-start"]')).toHaveValue('22');
        await expect(page.locator('[data-a="profile:notify:quiet-end"]')).toHaveValue('6');

        await reset(page);
        expect(errors).toEqual([]);
    });

    test(`mutes a book from its shelf card and unmutes it in the profile (${label})`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = watchForErrors(page);
        await signIn(page);
        await page.goto('/books');

        const mute = page.locator('[data-a^="books:mute:"]').first();
        const id = (await mute.getAttribute('data-a')).split(':').pop();
        if ((await mute.getAttribute('aria-pressed')) === 'true') {
            await mute.click();
            await expect(mute).toHaveAttribute('aria-pressed', 'false');
        }

        await mute.click();
        await expect(page.locator(`[data-a="books:mute:${id}"]`)).toHaveAttribute('aria-pressed', 'true');
        await noSideScroll(page, viewport.width);
        await shot(page, `notify-shelf-muted-${label}`);

        await page.goto('/profile');
        const unmute = page.locator(`[data-a="profile:notify:unmute:${id}"]`);
        await unmute.scrollIntoViewIfNeeded();
        await expect(unmute).toBeVisible();
        await noSideScroll(page, viewport.width);
        await shot(page, `notify-profile-muted-${label}`);

        await unmute.click();
        await expect(unmute).toHaveCount(0);
        await expect(page.locator('[data-a="profile:notify:muted"]')).toBeVisible();

        await page.goto('/books');
        await expect(page.locator(`[data-a="books:mute:${id}"]`)).toHaveAttribute('aria-pressed', 'false');

        expect(errors).toEqual([]);
    });
}
