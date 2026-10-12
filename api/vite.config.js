import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        // Tailwind v4 runs as a Vite plugin rather than through PostCSS.
        // That is why postcss.config.js, tailwind.config.js and autoprefixer
        // were removed — v4 needs none of them.
        tailwindcss(),
    ],
});
