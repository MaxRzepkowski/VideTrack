// The setup runs in Node (it reads the PHP lang files from disk); the app's
// own code is browser code and keeps Node's types out of its globals.
/// <reference types="node" />
import { followPage } from '@/stores/library';
import { config } from '@vue/test-utils';
import { i18nVue } from 'laravel-vue-i18n';
import { parseAll } from 'laravel-vue-i18n/loader';
import { resolve } from 'node:path';
import { afterEach, beforeEach, vi } from 'vitest';
import { createApp } from 'vue';
import { resetPage, testPage } from './page';
import { route } from './route';

// Real English texts, so a test reads what a user reads: the framework's
// lang/en.json plus our group files, parsed from PHP in memory, as the server
// reads them for the page.
const langs = import.meta.glob<{ default: Record<string, string> }>('../../../lang/*.json', { eager: true });
const groups = Object.fromEntries(parseAll(resolve('lang')).map(({ name, translations }) => [name, translations]));

createApp({}).use(i18nVue, {
    lang: 'en',
    resolve: (lang: string) => ({ ...(langs[`../../../lang/${lang}.json`]?.default ?? {}), ...(groups[`php_${lang}.json`] ?? {}) }),
});

globalThis.route = route as typeof globalThis.route;
config.global.mocks.route = route;
// usePage() and <Head> are stand-ins, the rest of Inertia is real: usePage()
// answers from the test's page, and <Head> writes through the head manager
// the Inertia app sets up, which a mounted page has none of.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => testPage,
    Head: Object.assign(() => null, { props: { title: String } }),
}));

// The library store follows the test's page, as it follows the real one.
followPage();

// A Vue warning is a bug (a hook outside a component, a prop nobody declared):
// it fails the test that caused it instead of scrolling past in the output.
const vueWarnings: string[] = [];
const warn = console.warn.bind(console);

console.warn = (...args: unknown[]) => {
    if (String(args[0]).startsWith('[Vue warn]')) {
        vueWarnings.push(String(args[0]));
    }

    warn(...args);
};

// Every test reads the same instant, as Pest's do (`travelTo` in tests/Pest.php):
// "today", "upcoming" and "x days ago" never flip with the wall clock. Only
// `Date` is held; a test that needs timers fakes them itself.
const FROZEN_NOW = new Date('2026-10-10T12:00:00Z');

beforeEach(() => {
    vi.useFakeTimers({ now: FROZEN_NOW, toFake: ['Date'] });
    resetPage();
    vueWarnings.length = 0;
});

afterEach(() => {
    vi.useRealTimers();

    if (vueWarnings.length > 0) {
        throw new Error(`Vue warned:\n${vueWarnings.join('\n')}`);
    }
});
