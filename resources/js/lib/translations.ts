import { jsonFetch } from '@/lib/http';
import { loadLanguageAsync } from 'laravel-vue-i18n';

type Texts = Record<string, string>;

// Every language the page has spoken, by locale: each is fetched once.
const languages = new Map<string, Texts>();

/**
 * One language's texts from the address the server names for them. A fetch
 * that fails gives none rather than stopping the app.
 */
export async function translationsAt(href: string): Promise<Texts> {
    try {
        const response = await jsonFetch(href);

        return response.ok ? ((await response.json()) as Texts) : {};
    } catch (error) {
        // No answer (offline, the server down), or one that is no JSON at all.
        if (error instanceof TypeError || error instanceof SyntaxError) {
            return {};
        }

        throw error;
    }
}

/**
 * The reader's language, from the address the page shell names
 * (`<link data-translations>`): the browser has been fetching it beside the
 * app's code, so waiting for it costs the start nothing it was not paying
 * already.
 */
export function pageTranslations(page: Document = document): Promise<Texts> {
    const href = page.querySelector<HTMLLinkElement>('link[data-translations]')?.href;

    return href ? translationsAt(href) : Promise.resolve({});
}

/** Keeps a language's texts for the i18n plugin to resolve it to. */
export function rememberLanguage(locale: string, texts: Texts): void {
    languages.set(locale, texts);
}

/** What the i18n plugin reads a language from: the texts fetched for it. */
export function textsOf(locale: string): Texts {
    return languages.get(locale) ?? {};
}

/**
 * Speaks another language from now on, its texts fetched from `href` unless
 * the page has them already. When none come, the page keeps the language it
 * speaks rather than show keys; the answer says whether it switched.
 */
export async function switchLanguage(locale: string, href: string): Promise<boolean> {
    if (!languages.has(locale)) {
        const texts = await translationsAt(href);

        if (Object.keys(texts).length === 0) {
            return false;
        }

        languages.set(locale, texts);
    }

    await loadLanguageAsync(locale);

    return true;
}
