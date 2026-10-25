import { expect, test } from '@playwright/test';
import { firstBookId, signIn } from './helpers.js';

/**
 * The studio is one screen tall, and only its panels scroll. An absolutely
 * positioned element deep in a long panel (an sr-only label in the print kit)
 * once escaped to the page and let it scroll on into 2,000px of blank space
 * below the studio; the panel and the shell are positioned to contain it.
 */
test.use({ viewport: { width: 1440, height: 900 } });

test('the studio page never scrolls past the screen', async ({ page }) => {
    test.setTimeout(240_000);
    await signIn(page);
    const id = await firstBookId(page);

    for (const pane of ['export', 'royalties', 'distribution']) {
        await page.goto(`/books/${id}/edit?pane=${pane}`);
        await page.locator('#main').waitFor();
        await page.waitForTimeout(1500);
        const extra = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
        expect(extra, `${pane} pane`).toBeLessThanOrEqual(1);
    }
});
