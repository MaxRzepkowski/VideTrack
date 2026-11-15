import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite';
import path from 'path';
import { defineConfig, loadEnv } from 'vite';

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
            i18n(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
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
