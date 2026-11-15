import { failureOf, jsonFetch, refusalOf } from '@/lib/http';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn(() => Promise.resolve(new Response('{}')));

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=abc%3D%3D';
});

afterEach(() => {
    vi.unstubAllGlobals();
    fetchMock.mockClear();
});

function sent(): { url: string; init: RequestInit & { headers: Record<string, string> } } {
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit & { headers: Record<string, string> }];

    return { url, init };
}

describe('jsonFetch', () => {
    it('asks for JSON as an XHR and sends no token on a read', async () => {
        await jsonFetch('/feed');

        const { url, init } = sent();
        expect(url).toBe('/feed');
        expect(init.method).toBe('GET');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers).toEqual({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' });
        expect(init.body).toBeUndefined();
    });

    it('carries the decoded XSRF token and a JSON body on a write', async () => {
        await jsonFetch('/save', { method: 'POST', body: { a: 1 } });

        const { init } = sent();
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc==');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.body).toBe('{"a":1}');
    });

    it('sends a form as multipart, leaving the boundary to the browser', async () => {
        const form = new FormData();
        form.append('file', 'x');

        await jsonFetch('/upload', { method: 'POST', body: form });

        const { init } = sent();
        expect(init.body).toBe(form);
        expect(init.headers['Content-Type']).toBeUndefined();
    });

    it('passes the abort signal through', async () => {
        const controller = new AbortController();

        await jsonFetch('/search', { signal: controller.signal });

        expect(sent().init.signal).toBe(controller.signal);
    });
});

describe('refusalOf', () => {
    const answer = (status: number, body: string) => new Response(body, { status });

    it('reads the first message of a refusal', async () => {
        expect(
            await refusalOf(answer(422, JSON.stringify({ message: 'x', errors: { watched_at: ['No release date.'], amount: ['Too big.'] } }))),
        ).toBe('No release date.');
    });

    it('has nothing to say for any other failure, or for a refusal without words', async () => {
        expect(await refusalOf(answer(500, JSON.stringify({ errors: { a: ['b'] } })))).toBeNull();
        expect(await refusalOf(answer(422, JSON.stringify({ message: 'invalid' })))).toBeNull();
        expect(await refusalOf(answer(422, '<html>'))).toBeNull();
    });
});

describe('failureOf', () => {
    const answer = (status: number, body: string) => new Response(body, { status });

    it('asks for a reload when the session or the form token has gone stale', async () => {
        expect(await failureOf(answer(419, ''))).toBe('Your session has expired. Reload the page and try again.');
        expect(await failureOf(answer(401, ''))).toBe('Your session has expired. Reload the page and try again.');
    });

    it('names a refusal in its own words, and says nothing of an outage', async () => {
        expect(await failureOf(answer(422, JSON.stringify({ errors: { rating: ['Off the scale.'] } })))).toBe('Off the scale.');
        expect(await failureOf(answer(503, ''))).toBeNull();
    });
});
