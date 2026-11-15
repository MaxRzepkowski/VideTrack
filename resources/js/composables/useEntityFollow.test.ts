import type { Creator } from '@/composables/useCreatorActions';
import { useEntityFollow } from '@/composables/useEntityFollow';
import type { JsonFetchOptions } from '@/lib/http';
import { nth } from '@/test/lists';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

const calls: { url: string; options: JsonFetchOptions; status: number }[] = [];
let status = 200;

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, options: JsonFetchOptions = {}) => {
        calls.push({ url, options, status });

        // The answer carries the creator's new state, as the server's does.
        const followed = (options.body as { mode: string | null }).mode !== null;

        return Promise.resolve(new Response(JSON.stringify({ creatorStates: { 'network:tmdb:49': { followed, listIds: [] } } }), { status }));
    },
}));

const person: Creator = { kind: 'person', provider: 'tmdb', ref: '7' };
const network: Creator = { kind: 'network', provider: 'tmdb', ref: '49' };

beforeEach(() => {
    calls.length = 0;
    status = 200;
    vi.spyOn(router, 'reload').mockImplementation(((options: { onFinish: () => void }) => options.onFinish()) as never);
});

describe('useEntityFollow', () => {
    it('lights the bell from the page, a company keyed with its kind', () => {
        setPageProps({ creatorStates: { 'person:tmdb:7': { followed: true, listIds: [] }, 'company:tmdb:49': { followed: true, listIds: [] } } });

        expect(useEntityFollow(person).followed.value).toBe(true);
        expect(useEntityFollow(network).followed.value).toBe(false);
        expect(useEntityFollow(person).label.value).toBe('Stop following');
        expect(useEntityFollow(network).label.value).toBe('Follow releases');
    });

    it('works on a page that sends no follows, as an unlit bell', () => {
        expect(useEntityFollow(person).followed.value).toBe(false);
    });

    it('flips the bell from the answer, busy until the write lands, with nothing to reload', async () => {
        const bell = useEntityFollow(network);

        const done = bell.toggle();

        expect(bell.busy.value).toBe(true);
        expect(nth(calls, 0).url).toBe('/ui.networks.follow.update/tmdb/49');
        expect(nth(calls, 0).options).toMatchObject({ method: 'PUT', body: { mode: 'follow' } });

        await done;
        expect(bell.busy.value).toBe(false);
        expect(bell.followed.value).toBe(true);
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('says so when the write fails', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);
        setPageProps({ creatorStates: { 'person:tmdb:7': { followed: true, listIds: [] } } });
        status = 500;

        await useEntityFollow(person).toggle();

        expect(nth(calls, 0).options.body).toEqual({ mode: null });
        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
        expect(router.reload).not.toHaveBeenCalled();
    });
});
