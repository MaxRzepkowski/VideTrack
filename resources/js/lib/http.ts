import { trans } from 'laravel-vue-i18n';
/**
 * JSON calls made outside Inertia — for anything that must not repaint the
 * page: a background report, a modal loading its rows, a card polling a
 * device code. Never router.* for these: an Inertia visit follows the
 * controller's redirect and re-renders the page under the user (an open
 * <select> loses its list and commits the option under the pointer).
 */
export interface JsonFetchOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    /** Sent as JSON, or as multipart when it is FormData (an uploaded file). */
    body?: unknown;
    signal?: AbortSignal;
}

/** Laravel reads the (decrypted) XSRF-TOKEN cookie back from this header on writes. */
function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

export function jsonFetch(url: string, { method = 'GET', body, signal }: JsonFetchOptions = {}): Promise<Response> {
    const isForm = body instanceof FormData;
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (method !== 'GET') {
        headers['X-XSRF-TOKEN'] = xsrfToken();
    }

    if (body !== undefined && !isForm) {
        headers['Content-Type'] = 'application/json';
    }

    return fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        signal,
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });
}

/**
 * A request built by someone else's code (the API docs' "Try it"), sent as
 * this page's session: its cookies and the XSRF token Laravel reads on writes.
 * The URL there is editable, so the token goes only to this origin.
 */
export function sessionFetch(input: string | URL | Request, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined));
    const url = new URL(input instanceof Request ? input.url : input, window.location.href);

    if (url.origin === window.location.origin) {
        headers.set('X-XSRF-TOKEN', xsrfToken());
    }

    return fetch(input, { ...init, headers, credentials: 'same-origin' });
}

/**
 * What to tell the user about a request the server turned down, in its own
 * words where it gave any: a refusal (422) names its reason, an expired
 * session or a stale form token (401, 419) asks for a reload — trying again
 * on the same page would fail the same way. Null where only a generic word fits.
 */
export async function failureOf(response: Response): Promise<string | null> {
    if (response.status === 401 || response.status === 419) {
        return trans('common.session_expired_reload');
    }

    return refusalOf(response);
}

/**
 * What the server said it refused, for the user to read: the first message of
 * a 422 (a rule of the library said no, or the input was invalid). Null for
 * any other failure, which has no words of its own to show.
 */
export async function refusalOf(response: Response): Promise<string | null> {
    if (response.status !== 422) {
        return null;
    }

    try {
        const body = (await response.json()) as { errors?: Record<string, string[]> };

        return Object.values(body.errors ?? {})[0]?.[0] ?? null;
    } catch (error) {
        // An answer that is not JSON carries no message either.
        if (error instanceof SyntaxError) {
            return null;
        }

        throw error;
    }
}
