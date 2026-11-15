import CommandPalette from '@/components/command/CommandPalette.vue';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { setPageProps } from '@/test/page';
import { withSetup } from '@/test/withSetup';
import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const asked: string[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string) => {
        asked.push(url);

        return Promise.resolve(new Response(JSON.stringify({ recent: [], results: [] }), { status: 200 }));
    },
}));

let wrapper: VueWrapper;
let palette: { result: ReturnType<typeof useCommandPalette>; wrapper: VueWrapper };

beforeEach(() => {
    vi.useFakeTimers();
    asked.length = 0;
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
});
