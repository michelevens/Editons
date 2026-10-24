import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { watchSystemTheme } from './theme';
import PlatformFrame from './Components/Platform/PlatformFrame';
import { installErrorReporter } from './errorReporter';

// Browser errors go to the console's error groups (errorReporter.js). Built
// pages only: the dev server's own errors are not the product's.
if (import.meta.env.PROD) {
    installErrorReporter();
}

// The theme class is set by app.blade.php before paint; this keeps it in step
// with the device while the choice is 'system'.
watchSystemTheme();

// Installable app (public/sw.js). Browsers only allow it on HTTPS or
// localhost, so the LAN address used for tablet testing simply skips it.
if ('serviceWorker' in navigator && window.isSecureContext && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    // Every page sits inside PlatformFrame: the "View as" banner, the
    // announcement and notices are on every screen without each page having
    // to remember them. A page with its own layout keeps it, inside.
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        );
        const own = page.default.layout;

        page.default.layout = own?.platformFramed
            ? own
            : Object.assign(
                  (child) => <PlatformFrame>{typeof own === 'function' ? own(child) : child}</PlatformFrame>,
                  { platformFramed: true },
              );

        return page;
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#2166F5',
    },
});
