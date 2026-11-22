import { pageTranslations, rememberLanguage, switchLanguage, textsOf } from '@/lib/translations';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const asked: string[] = [];
let answer: () => Promise<Response> = () => Promise.resolve(new Response('{}'));
const loaded: string[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string) => {
        asked.push(url);

        return answer();
    },
}));

vi.mock('laravel-vue-i18n', async (importOriginal) => ({
    ...(await importOriginal<typeof import('laravel-vue-i18n')>()),
    loadLanguageAsync: (lang: string) => {
        loaded.push(lang);

        return Promise.resolve(lang);
    },
}));

function shell(href: string | null): Document {
    const page = document.implementation.createHTMLDocument();

    if (href !== null) {
        page.head.innerHTML = `<link rel="preload" href="${href}" as="fetch" crossorigin="anonymous" data-translations>`;
    }

    return page;
}

const answers = (texts: Record<string, string>) => () => Promise.resolve(new Response(JSON.stringify(texts)));

beforeEach(() => {
    asked.length = 0;
    loaded.length = 0;
});

describe('pageTranslations', () => {
    it('reads the texts at the address the page names', async () => {
        answer = answers({ 'common.show_more': 'Pokaż więcej' });

        expect(await pageTranslations(shell('http://localhost/lang/pl.0123456789abcdef.json'))).toEqual({ 'common.show_more': 'Pokaż więcej' });
        expect(asked).toEqual(['http://localhost/lang/pl.0123456789abcdef.json']);
    });

    it('starts without texts when the page names none, the server fails or nothing answers', async () => {
        expect(await pageTranslations(shell(null))).toEqual({});
        expect(asked).toEqual([]);

        answer = () => Promise.resolve(new Response('Server Error', { status: 500 }));
        expect(await pageTranslations(shell('http://localhost/lang/pl.0123456789abcdef.json'))).toEqual({});

        answer = () => Promise.reject(new TypeError('Failed to fetch'));
        expect(await pageTranslations(shell('http://localhost/lang/pl.0123456789abcdef.json'))).toEqual({});
    });
});

describe('switchLanguage', () => {
    it('speaks a language once its texts arrive, and fetches them only once', async () => {
        answer = answers({ 'common.show_more': 'Mehr anzeigen' });

        expect(await switchLanguage('de', '/lang/de.0123456789abcdef.json')).toBe(true);
        expect(await switchLanguage('de', '/lang/de.0123456789abcdef.json')).toBe(true);

        expect(asked).toEqual(['/lang/de.0123456789abcdef.json']);
        expect(loaded).toEqual(['de', 'de']);
        expect(textsOf('de')).toEqual({ 'common.show_more': 'Mehr anzeigen' });
    });

    it('keeps the language it speaks when no texts come', async () => {
        answer = () => Promise.reject(new TypeError('Failed to fetch'));

        expect(await switchLanguage('fr', '/lang/fr.0123456789abcdef.json')).toBe(false);
        expect(loaded).toEqual([]);
        expect(textsOf('fr')).toEqual({});
    });

    it('needs no fetch for the language the page started in', async () => {
        rememberLanguage('nl', { 'common.show_more': 'Meer tonen' });

        expect(await switchLanguage('nl', '/lang/nl.0123456789abcdef.json')).toBe(true);
        expect(asked).toEqual([]);
        expect(loaded).toEqual(['nl']);
    });
});
