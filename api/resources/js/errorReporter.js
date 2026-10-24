/**
 * Errors the browser catches, sent to the console's error groups
 * (POST /client-errors, App\Platform\Ops\ErrorGroups).
 *
 * Only the message, the stack and the page's path — never the page's text,
 * a form's values or the query string. At most five reports a page load,
 * each different message once; the server limits and caps them again.
 *
 * The last one is kept for the Help form, which attaches it to a support
 * request so the author does not have to describe a red error they saw.
 */
const MAX_PER_PAGE = 5;
const KEY = 'ecrivain:last-error';
const seen = new Set();

function send(message, stack) {
    const text = String(message ?? '').slice(0, 1000);

    if (!text || seen.has(text) || seen.size >= MAX_PER_PAGE) return;

    seen.add(text);

    try {
        window.sessionStorage.setItem(KEY, text.slice(0, 500));
    } catch {
        // Private mode: the Help form simply has nothing to attach.
    }

    try {
        window.axios
            ?.post('/client-errors', {
                message: text,
                stack: stack ? String(stack).slice(0, 8000) : null,
                url: window.location.origin + window.location.pathname,
            })
            .catch(() => {});
    } catch {
        // Reporting must never become the error.
    }
}

export function lastClientError() {
    try {
        return window.sessionStorage.getItem(KEY);
    } catch {
        return null;
    }
}

export function installErrorReporter() {
    if (typeof window === 'undefined' || window.__ecrivainErrors) return;

    window.__ecrivainErrors = true;

    window.addEventListener('error', (event) => {
        // A failed <img> or <script> load has no error object and no message
        // worth grouping.
        if (!event.message) return;

        send(event.message, event.error?.stack);
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;

        // An axios call the page already showed a message for is not a bug.
        if (reason?.isAxiosError || reason?.response) return;

        send(reason?.message ?? reason, reason?.stack);
    });
}
