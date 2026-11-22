import { useListActions } from '@/composables/useListActions';
import type { JsonFetchOptions } from '@/lib/http';
import { reloadAfterWrites } from '@/stores/library';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

const sent: { url: string; method?: string; body?: unknown }[] = [];
let answer: (url: string) => Response = () => new Response(null, { status: 204 });

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method, body }: JsonFetchOptions = {}) => {
        sent.push({ url, method, body });

        return Promise.resolve(answer(url));
    },
}));

let visits: string[] = [];
let reloads: string[][] = [];

beforeEach(() => {
    sent.length = 0;
    visits = [];
    reloads = [];
    answer = () => new Response(null, { status: 204 });
    setPageProps({ auth: { user: { username: 'ana' } } });
    vi.spyOn(router, 'visit').mockImplementation(((url: string) => visits.push(url)) as never);
    vi.spyOn(router, 'reload').mockImplementation(((options: { only: string[]; onFinish: () => void }) => {
        reloads.push(options.only);
        options.onFinish();
    }) as never);
});

describe('useListActions', () => {
    it('opens a list it made, on its own page', async () => {
        answer = () => new Response(JSON.stringify({ data: { id: 12 } }), { status: 201 });

        expect(await useListActions().create('Weekend')).toBe(true);
        expect(sent).toEqual([{ url: '/ui.lists.store', method: 'POST', body: { title: 'Weekend' } }]);
        expect(visits).toEqual(['/users.lists.show/ana/12']);
    });

    it('reads the list again after its details change', async () => {
        await useListActions().update(3, { title: 'Renamed', description: null, is_public: true });
        await flushPromises();

        expect(reloads).toEqual([['list']]);
    });

    it("goes to the viewer's lists once a list is gone, without reading the gone list again", async () => {
        const unregister = reloadAfterWrites(['items', 'stats']);

        expect(await useListActions().destroy(3)).toBe(true);
        await flushPromises();
        unregister();

        expect(sent).toEqual([{ url: '/ui.lists.destroy/3', method: 'DELETE', body: undefined }]);
        expect(reloads).toEqual([]);
        expect(visits).toEqual(['/users.lists/ana']);
    });

    it('stays on a list the server refused to delete', async () => {
        vi.spyOn(toast, 'error').mockImplementation(() => 1);
        answer = () => new Response(JSON.stringify({ message: 'x' }), { status: 403 });

        expect(await useListActions().destroy(3)).toBe(false);
        expect(visits).toEqual([]);
    });

    it('answers a refused move, so the order on screen can go back', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);
        answer = () => new Response(JSON.stringify({ message: 'x', errors: { list_ids: ['Not yours.'] } }), { status: 422 });

        expect(await useListActions().reorder([2, 1])).toBe(false);
        expect(error).toHaveBeenCalledWith('Not yours.');
    });
});
