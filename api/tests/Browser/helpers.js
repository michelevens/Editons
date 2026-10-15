import { expect } from '@playwright/test';

/**
 * Shared browser-test helpers.
 */

export const DEMO = {
    email: process.env.DEMO_EMAIL ?? 'test@editons.local',
    password: process.env.DEMO_PASSWORD ?? 'password',
};

/**
 * Collect console errors and failed requests for the life of a page.
 *
 * Returns a live array. Assert on it at the END of a test rather than
 * subscribing per-action: a script that fails to load reports once, long
 * before whatever assertion would have noticed the page was empty.
 *
 * Favicon 404s are ignored. Nothing else is: a page that logs an error is a
 * page where something did not happen, even when it still looks right.
 */
export function watchForErrors(page) {
    const errors = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(`console: ${message.text().slice(0, 300)}`);
        }
    });

    page.on('pageerror', (error) => {
        errors.push(`uncaught: ${String(error).slice(0, 300)}`);
    });

    page.on('requestfailed', (request) => {
        if (request.url().includes('favicon')) return;

        // ERR_ABORTED means the browser cancelled a request that was still
        // in flight, which happens for two legitimate reasons here:
        //
        //  - Vite::prefetch speculatively loads other pages' bundles, and
        //    navigating away cancels them.
        //  - The crawl clicks through chapters faster than their findings and
        //    versions can load, so React unmounts mid-fetch.
        //
        // Both were verified by fetching the same URLs directly: all return
        // 200. An abort is the browser's choice, not the server's failure.
        //
        // Only aborts are exempt, and only for a cancellable fetch. A script
        // that genuinely cannot load reports a different error, and that is
        // the blank-page failure this suite exists to catch.
        if (request.failure()?.errorText === 'net::ERR_ABORTED') return;

        errors.push(
            `request failed: ${request.url().slice(0, 160)} — ${request.failure()?.errorText}`,
        );
    });

    return errors;
}

/**
 * Drop console noise a test provoked on purpose.
 *
 * A test that submits a bad ISBN to prove the server refuses it will see the
 * browser log that 4xx. Filtering at the assertion keeps the watcher strict
 * everywhere else: the exemption is named by the test that earned it, rather
 * than widened for all of them.
 */
export function ignoring(errors, ...patterns) {
    return errors.filter(
        (error) => !patterns.some((pattern) => error.includes(pattern)),
    );
}

/** Sign in through the real form, so the session is the one a user gets. */
export async function signIn(page) {
    await page.goto('/login');
    await page.fill('#email', DEMO.email);
    await page.fill('#password', DEMO.password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
}

/**
 * Assert a page actually rendered.
 *
 * A 200 proves nothing about a React app: the blank screen this suite exists
 * to catch returned 200 with correct HTML and simply never mounted. So this
 * checks the Inertia root has children and the page has visible text.
 */
export async function expectRendered(page, { minText = 50 } = {}) {
    const root = page.locator('#app');

    await expect(root).toBeAttached();

    const childCount = await root.evaluate((element) => element.children.length);
    expect(childCount, 'Inertia root has no children — React did not mount').toBeGreaterThan(0);

    const text = (await page.locator('body').innerText()).trim();
    expect(text.length, `page rendered only ${text.length} characters`).toBeGreaterThan(minText);
}

/**
 * Assert the page is still rendered after doing something to it.
 *
 * expectRendered is called once, on arrival. The blank-page bug that
 * prompted this happened on a CLICK: the body went to zero characters while
 * #app stayed attached and the response stayed 200, so every signal the
 * suite watched said the page was fine.
 *
 * Wrap any interaction that unmounts a pane — switching tabs, adding a
 * section, restoring a version — and the failure is caught where it
 * happens rather than at the next assertion that happens to need text.
 */
export async function expectSurvives(page, action, { minText = 200 } = {}) {
    await action();

    await expect
        .poll(async () => (await page.locator('body').innerText()).trim().length, {
            timeout: 15_000,
            message: 'the page went blank after that interaction',
        })
        .toBeGreaterThan(minText);
}

/** The id of the demo account's first book. */
export async function firstBookId(page) {
    await page.goto('/books');

    const href = await page
        .locator('a[href*="/edit"], [data-a^="books:open:"]')
        .first()
        .getAttribute('href');

    const match = href?.match(/books\/(\d+)/);

    if (!match) throw new Error('No book found for the demo account.');

    return match[1];
}

/**
 * The demo account's worked example, by name.
 *
 * firstBookId returns whatever sorts first, which is fine for "does this
 * screen render" but wrong for anything that depends on the manuscript —
 * the shelf now holds three books, and two of them are deliberately thin.
 * A test about reviewing prose needs the book that has prose.
 */
export async function flagshipBookId(page) {
    await page.goto('/books');

    const card = page.locator('li').filter({ hasText: 'Building an Independent Practice' });
    const href = await card.locator('a[href*="/edit"]').first().getAttribute('href');

    const match = href?.match(/books\/(\d+)/);

    if (!match) throw new Error('The demo example book was not found.');

    return match[1];
}
