import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { watchSystemTheme } from './theme';

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
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#2166F5',
    },
});
