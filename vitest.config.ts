import vue from '@vitejs/plugin-vue';
import path from 'path';
import { defineConfig } from 'vitest/config';

// The front's tests: the app's Vue plugin, a DOM without a browser, and
// coverage over everything the app ships. Not the i18n plugin: it deletes the
// lang/php_*.json it generated when it stops, which would pull the translations
// out from under the running dev server — the tests read the PHP lang files
// in memory instead (resources/js/test/setup.ts).
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, './resources/js'),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.test.ts'],
        setupFiles: ['resources/js/test/setup.ts'],
        restoreMocks: true,
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{ts,vue}'],
            // sw.ts is the worker's wiring and runs in a service worker scope, which happy-dom does not have; its
            // rules live in lib/serviceWorker.ts, which is measured.
            exclude: [
                'resources/js/**/*.test.ts',
                'resources/js/test/**',
                'resources/js/**/*.d.ts',
                'resources/js/components/ui/**',
                'resources/js/sw.ts',
            ],
            reporter: ['text-summary'],
            // The floor only rises: raise it when the suite passes it. Every test
            // reads one frozen instant (test/setup.ts), so the count does not
            // wobble between runs. Measured by Vitest 5, which maps
            // V8 coverage onto the source's syntax tree: a Vue file counts its
            // script, not its compiled render function.
            thresholds: { lines: 74.1, statements: 73.2, branches: 64.5, functions: 66.3 },
        },
    },
});
