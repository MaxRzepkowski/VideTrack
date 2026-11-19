import '@fontsource-variable/roboto-flex';
import '../css/app.css';

import { vTooltip } from '@/directives/tooltip';
import { registerMediaTypes } from '@/lib/mediaTypes';
import { registerServiceWorker, removeServiceWorker } from '@/lib/pwa';
import { followPage } from '@/stores/library';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { getActiveLanguage, i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'VideTrack';

createInertiaApp({
    title: (title) => `${title} – ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // Shared with every page and fixed for the session: what each media
        // type can do, read by the lib functions without a page context.
        registerMediaTypes(props.initialPage.props.mediaTypes);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18nVue, {
                lang: props.initialPage.props.locale,
                resolve: async (lang: string) => {
                    const langs = import.meta.glob<{ default: Record<string, string> }>('../../lang/*.json');
                    const load = langs[`../../lang/${lang}.json`];

                    if (load === undefined) {
                        throw new Error(`No translations for the locale ${lang}.`);
                    }

                    return await load();
                },
            })
            .directive('tooltip', vTooltip)
            .mount(el);

        // The library store takes in each page's states as it arrives.
        followPage();
    },
    progress: {
        // The brand accent — the top loading bar reads as ours, not a neutral
        // gray. Inertia needs a concrete color at init, so it is read from the
        // token instead of copied out of it.
        color: getComputedStyle(document.documentElement).getPropertyValue('--color-brand').trim() || 'currentColor',
    },
});

// Installable app and cached covers in a production build only: the development server's hot reload must not
// sit behind a service worker.
if (import.meta.env.PROD) {
    registerServiceWorker();
} else {
    removeServiceWorker();
}

// A modal radix overlay (dropdown, dialog) parks `pointer-events: none` on
// <body> and takes it back when it closes. An Inertia visit started from
// inside one unmounts the whole layout mid-close, and that undo never runs —
// the page paints normally and ignores every click, which reads as a hung app.
// Once no dismissable layer is left in the DOM, nothing may keep the body
// deaf, so the navigation itself clears it.
router.on('navigate', () => {
    requestAnimationFrame(() => {
        if (!document.querySelector('[data-dismissable-layer]')) {
            document.body.style.pointerEvents = '';
        }
    });
});

// The SPA never full-reloads, so a locale changed in the settings must be
// picked up from the shared page prop and swapped in live.
router.on('success', (event) => {
    const locale = event.detail.page.props.locale;

    if (locale && locale !== getActiveLanguage()) {
        void loadLanguageAsync(locale);
    }
});
