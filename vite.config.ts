import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import { defineConfig, loadEnv } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = new URL(env.APP_URL || 'http://localhost:8080');
    const appHost = appUrl.hostname;
    const devServerHost = env.VITE_DEV_SERVER_HOST || appHost;

    return {
        server: {
            host: '0.0.0.0',
            origin: `http://${devServerHost}:5173`,
            hmr: {
                host: devServerHost,
            },
            cors: {
                origin: [appUrl.origin, 'http://localhost:8080', 'http://127.0.0.1:8080'],
            },
        },
        plugins: [
            laravel({
                input: ['resources/js/app.ts'],
                refresh: true,
            }),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            // The service worker is written to the web root, not public/build: a worker only controls the pages
            // under its own path. The manifest is a static file in public/, and pwa.ts registers the worker in a
            // production build.
            VitePWA({
                strategies: 'injectManifest',
                srcDir: 'resources/js',
                filename: 'sw.ts',
                outDir: 'public',
                injectRegister: false,
                manifest: false,
                injectManifest: {
                    globDirectory: 'public',
                    globPatterns: ['build/assets/*.{js,css,woff2}', 'offline.html', 'logo.svg'],
                    // Hashed names already change with the content.
                    dontCacheBustURLsMatching: /^build\/assets\//,
                    rollupFormat: 'iife',
                },
            }),
        ],
        resolve: {
            alias: {
                '@': path.resolve(import.meta.dirname, './resources/js'),
            },
        },
    };
});
