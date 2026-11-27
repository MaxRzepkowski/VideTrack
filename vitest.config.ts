import vue from '@vitejs/plugin-vue';
import path from 'path';
import { defineConfig } from 'vitest/config';

// The front's tests: the app's Vue plugin, a DOM without a browser, and
// coverage over everything the app ships. The texts are the PHP lang files,
// read in memory (resources/js/test/setup.ts).
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
            thresholds: { lines: 77.5, statements: 76.5, branches: 68.2, functions: 69.8 },
        },
    },
});
