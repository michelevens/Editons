/**
 * Light and dark.
 *
 * The choice is 'system', 'light' or 'dark', stored on the account
 * (users.theme) and printed on <html data-theme> by app.blade.php, which also
 * sets the `dark` class before the first paint. This module keeps the class
 * right afterwards: when the author changes the setting, and, on 'system',
 * when the device switches between light and dark while the page is open.
 */

const media = () =>
    typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

export const THEMES = ['system', 'light', 'dark'];

export function currentTheme() {
    const value = document.documentElement.dataset.theme;

    return THEMES.includes(value) ? value : 'system';
}

/** Put the class on <html> for a choice. Returns whether dark is now on. */
export function applyTheme(theme) {
    const root = document.documentElement;
    const choice = THEMES.includes(theme) ? theme : 'system';
    const dark = choice === 'dark' || (choice === 'system' && Boolean(media()?.matches));

    root.dataset.theme = choice;
    root.classList.toggle('dark', dark);

    return dark;
}

/** Apply and save to the account. The page re-themes before the reply. */
export async function setTheme(theme) {
    applyTheme(theme);

    try {
        await window.axios.patch('/profile/theme', { theme });
    } catch {
        // Signed out, or offline: the page still changed, and the next
        // successful save will carry the choice.
    }
}

/** Follow the device while the choice is 'system'. Called once at boot. */
export function watchSystemTheme() {
    const query = media();

    query?.addEventListener?.('change', () => {
        if (currentTheme() === 'system') applyTheme('system');
    });
}
