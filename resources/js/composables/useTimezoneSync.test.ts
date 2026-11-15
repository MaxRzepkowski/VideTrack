import { useTimezoneSync } from '@/composables/useTimezoneSync';
import { nth } from '@/test/lists';
import { setPageProps } from '@/test/page';
import { withSetup } from '@/test/withSetup';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();
const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

beforeEach(() => {
    sessionStorage.clear();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('useTimezoneSync', () => {
    it("reports the browser's zone once per session, in the background", async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 204 }));

        withSetup(() => useTimezoneSync());
        withSetup(() => useTimezoneSync());
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = nth(fetchMock.mock.calls, 0);
        expect(url).toBe('/settings.timezone.detected');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toEqual({ detected_timezone: zone });
    });

    it('stays quiet when the server already knows the zone, or nobody is signed in', async () => {
        setPageProps({ auth: { user: { id: 1, preferences: { detected_timezone: zone } } } });
        withSetup(() => useTimezoneSync());

        setPageProps({ auth: { user: null } });
        withSetup(() => useTimezoneSync());
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('tries again on a later page when the report failed, but not after a 422', async () => {
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 419 }));
        withSetup(() => useTimezoneSync());
        await flush();
        expect(sessionStorage.length).toBe(0);

        fetchMock.mockResolvedValueOnce(new Response(null, { status: 422 }));
        withSetup(() => useTimezoneSync());
        await flush();
        expect(sessionStorage.length).toBe(1);
    });

    it('releases the claim when the connection drops', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('offline'));
        withSetup(() => useTimezoneSync());
        await flush();

        expect(sessionStorage.length).toBe(0);
    });
});
