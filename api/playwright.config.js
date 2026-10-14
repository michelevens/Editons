import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests.
 *
 * The handoff names a Playwright crawl as the acceptance test: every
 * [data-a] control clicked, zero console errors. That is the check that
 * catches what PHP tests cannot — a page that returns 200 and renders
 * nothing, which is exactly how this app shipped a blank screen.
 *
 * BASE_URL points at an already-running server. It defaults to the LAN
 * address because that is what APP_URL is set to locally, and a mismatch
 * between the two makes every asset cross-origin and the app blank — the
 * failure this suite exists to catch, which it would otherwise cause.
 */
export default defineConfig({
    testDir: './tests/Browser',
    // One worker: these tests share one database and one demo account, so
    // parallel runs would fight over the same rows.
    workers: 1,
    fullyParallel: false,
    reporter: process.env.CI ? 'github' : 'list',
    timeout: 45_000,
    expect: { timeout: 10_000 },

    use: {
        baseURL: process.env.BASE_URL ?? 'http://192.168.68.56:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...devices['Desktop Chrome'],
        viewport: { width: 1536, height: 1024 },
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1536, height: 1024 } },
        },
    ],
});
