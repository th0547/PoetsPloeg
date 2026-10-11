import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    build: { chunkSizeWarningLimit: 1000 },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/planning.js', 'resources/js/calendar.js'],
            refresh: true,
        }),
    ],
});
