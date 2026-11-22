import CommandPalette from '@/components/command/CommandPalette.vue';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { setPageProps } from '@/test/page';
import { withSetup } from '@/test/withSetup';
import type { PaletteList } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const asked: string[] = [];
let lists: PaletteList[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string) => {
        asked.push(url);

        return Promise.resolve(new Response(JSON.stringify({ recent: [], results: [], lists }), { status: 200 }));
    },
}));

let wrapper: VueWrapper;
let palette: { result: ReturnType<typeof useCommandPalette>; wrapper: VueWrapper };

beforeEach(() => {
    vi.useFakeTimers();
    asked.length = 0;
    lists = [];
    setPageProps({});
    wrapper = mount(CommandPalette);
    palette = withSetup(() => useCommandPalette());
});

afterEach(() => {
    palette.result.close();
    palette.wrapper.unmount();
    wrapper.unmount();
    vi.useRealTimers();
});

const input = () => new DOMWrapper(document.body).find('input');

describe('CommandPalette', () => {
    it('asks for nothing once the box it was waiting on is emptied', async () => {
        palette.result.open.value = true;
        await flushPromises();
        asked.length = 0;

        await input().setValue('abc');
        await input().setValue('');
        await vi.advanceTimersByTimeAsync(500);

        expect(asked).toEqual([]);

        await input().setValue('x');
        await vi.advanceTimersByTimeAsync(500);

        expect(asked).toHaveLength(1);
        expect(asked[0]).toContain('x');
    });

    it('offers the lists a query names, each opening where it lives', async () => {
        lists = [
            { id: 4, title: 'Weekend films', href: '/user/alex/lists/4', itemCount: 3, isCollection: false },
            { id: 9, title: 'The Weekend Saga', href: '/collections/tmdb/119', itemCount: 1, isCollection: true },
        ];
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        palette.result.open.value = true;
        await flushPromises();

        await input().setValue('weekend');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        const rows = new DOMWrapper(document.body).findAll('[role="option"]').filter((row) => row.text().includes('Weekend'));

        expect(rows.map((row) => row.text())).toEqual(['Weekend films3 items', 'The Weekend Saga1 item']);

        await rows[1]?.trigger('click');

        expect(visit).toHaveBeenCalledWith('/collections/tmdb/119');
    });
});
