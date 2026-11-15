import { useStreamedCredits } from '@/composables/useStreamedCredits';
import { mediaSummary } from '@/test/factories';
import { nth } from '@/test/lists';
import type { CompanyCredit } from '@/types/media';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';

const fetchMock = vi.fn();
const credit = (ref: string): CompanyCredit => ({ media: mediaSummary({ ref }), role: 'developer', category: 'movie' });
const chunk = (refs: string[], hasMore: boolean) => new Response(JSON.stringify({ credits: refs.map(credit), hasMore, userStates: {} }));

beforeEach(() => vi.stubGlobal('fetch', fetchMock));

afterEach(() => {
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

function stream(company = ref('a')) {
    return useStreamedCredits(
        () => company.value,
        () => [credit('1'), credit('2')],
        () => true,
        (page) => `/credits?page=${page}`,
    );
}

describe('useStreamedCredits', () => {
    it('appends the next chunks, skipping titles the page already holds', async () => {
        fetchMock.mockResolvedValueOnce(chunk(['2', '3'], true)).mockResolvedValueOnce(chunk(['4'], false));
        const credits = stream();

        await credits.loadMore();
        expect(credits.credits.value.map((c) => c.media.ref)).toEqual(['1', '2', '3']);
        expect(nth(fetchMock.mock.calls, 0)[0]).toBe('/credits?page=2');

        await credits.loadMore();
        expect(nth(fetchMock.mock.calls, 1)[0]).toBe('/credits?page=3');
        expect(credits.hasMore.value).toBe(false);

        await credits.loadMore();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('starts over for another company', async () => {
        fetchMock.mockResolvedValueOnce(chunk(['3'], true));
        const company = ref('a');
        const credits = stream(company);
        await credits.loadMore();

        company.value = 'b';
        await nextTick();

        expect(credits.credits.value.map((c) => c.media.ref)).toEqual(['1', '2']);
        expect(credits.hasMore.value).toBe(true);
    });

    it('drops a chunk that lands after the page moved on to another company', async () => {
        let land: (response: Response) => void = () => undefined;
        fetchMock.mockReturnValueOnce(new Promise<Response>((resolve) => (land = resolve)));
        const company = ref('a');
        const credits = stream(company);
        const loading = credits.loadMore();

        company.value = 'b';
        await nextTick();
        land(chunk(['3'], false));
        await loading;

        expect(credits.credits.value.map((c) => c.media.ref)).toEqual(['1', '2']);
        expect(credits.hasMore.value).toBe(true);

        fetchMock.mockResolvedValueOnce(chunk(['9'], false));
        await credits.loadMore();
        expect(nth(fetchMock.mock.calls, 1)[0]).toBe('/credits?page=2');
    });

    it('stops the catalogue and says so when a chunk fails', async () => {
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 503 }));
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);
        const credits = stream();

        await credits.loadMore();

        expect(credits.hasMore.value).toBe(false);
        expect(credits.loading.value).toBe(false);
        expect(error).toHaveBeenCalled();
    });
});
