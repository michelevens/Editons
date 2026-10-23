import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * A dedicated account for the browser suite, rebuilt before every run.
 *
 * The suite used to sign in as test@editons.local and edit whichever book sat
 * newest on that shelf: locally that was a real exam-prep manuscript, which
 * had its autosave switched off, its trim size changed and text, charts and
 * footnotes appended. Now it runs against its own account, reset by the
 * DemoSeeder to the same three demo books (and sample theses) a visitor gets,
 * so every run starts from a known state and touches nothing else.
 *
 * Provisioning runs `php artisan db:seed --class=DemoSeeder` in this checkout,
 * so the server under test (BASE_URL) must use the same database as api/.env.
 * Against a server this machine cannot seed, provision the account there and
 * pass E2E_EMAIL and E2E_PASSWORD: setup is then skipped. The account must be
 * a throwaway: the address has to contain "e2e", so a real account is never
 * the one the suite writes to.
 */
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

export default async function globalSetup() {
    const email = process.env.E2E_EMAIL ?? 'e2e-browser@editons.test';

    if (!/e2e/i.test(email)) {
        throw new Error(
            `E2E_EMAIL=${email} does not look like a throwaway account. The browser suite edits ` +
                'its books; point it at an address containing "e2e".',
        );
    }

    if (process.env.E2E_EMAIL && process.env.E2E_PASSWORD) {
        return;
    }

    const password = process.env.E2E_PASSWORD ?? randomBytes(18).toString('base64url');

    execFileSync(
        process.env.E2E_PHP ?? 'php',
        ['artisan', 'db:seed', '--class=DemoSeeder', '--force', '--no-interaction'],
        {
            cwd: ROOT,
            stdio: 'inherit',
            // Windows: php is often a .bat shim (Herd), which only a shell
            // resolves.
            shell: process.platform === 'win32',
            env: { ...process.env, DEMO_EMAIL: email, DEMO_PASSWORD: password },
        },
    );

    // Read by helpers.js in every worker: environment set here is inherited.
    process.env.E2E_EMAIL = email;
    process.env.E2E_PASSWORD = password;
}
