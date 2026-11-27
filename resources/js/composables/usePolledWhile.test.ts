import { usePolledWhile } from '@/composables/usePolledWhile';
import { jsonFetch } from '@/lib/http';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref, type Ref } from 'vue';

vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));

type List = { busy: boolean; label: string };

function host(props: Ref<List>) {
    let polled: Ref<List> | undefined;
    const wrapper = mount(
        defineComponent({
            setup() {
                polled = usePolledWhile(
                    () => props.value,
                    () => '/list',
                    (list) => list.busy,
                );

                return () => h('p', polled?.value.label);
            },
        }),
    );

    return wrapper;
}

describe('usePolledWhile', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.mocked(jsonFetch).mockReset();
    });

    it('asks again while the list is under way and shows the answer', async () => {
        vi.mocked(jsonFetch).mockResolvedValue(new Response(JSON.stringify({ busy: false, label: 'done' })));
        const wrapper = host(ref({ busy: true, label: 'working' }));

        vi.advanceTimersByTime(3000);
        await flushPromises();

        expect(jsonFetch).toHaveBeenCalledWith('/list');
        expect(wrapper.text()).toBe('done');
    });

    it('leaves a settled list alone', () => {
        host(ref({ busy: false, label: 'done' }));

        vi.advanceTimersByTime(9000);

        expect(jsonFetch).not.toHaveBeenCalled();
    });

    it('drops an answer to a question asked before the page changed the list', async () => {
        let answer: (response: Response) => void = () => {};
        vi.mocked(jsonFetch).mockReturnValue(new Promise((resolve) => (answer = resolve)));
        const props = ref({ busy: true, label: 'working' });
        const wrapper = host(props);

        vi.advanceTimersByTime(3000);
        props.value = { busy: false, label: 'cleared' };
        await flushPromises();
        answer(new Response(JSON.stringify({ busy: true, label: 'stale' })));
        await flushPromises();

        expect(wrapper.text()).toBe('cleared');
    });
});
