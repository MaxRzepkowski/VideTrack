/**
 * Allow-list external URLs to http(s) only. Provider-supplied fields
 * (TMDB `homepage`, IGDB `websites.url`, JustWatch deep links…) are
 * community-editable and could carry a `javascript:` payload that would run
 * in our origin on click. Returns the URL when safe, otherwise null.
 */
export function safeUrl(url: string | null | undefined): string | null {
    if (!url) {
        return null;
    }

    return /^https?:\/\//i.test(url.trim()) ? url : null;
}
