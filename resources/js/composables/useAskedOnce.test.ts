import { useAskedOnce } from '@/composables/useAskedOnce';
import { withSetup } from '@/test/withSetup';
import { describe, expect, it } from 'vitest';
import { nextTick, ref } from 'vue';

describe('useAskedOnce', () => {
    it('turns true on the first ask and stays so', async () => {
        const open = ref(false);
        const { result, wrapper } = withSetup(() => useAskedOnce(() => open.value));
        expect(result.value).toBe(false);

        open.value = true;
        await nextTick();
        expect(result.value).toBe(true);

        open.value = false;
        await nextTick();
        expect(result.value).toBe(true);

        wrapper.unmount();
    });

    it('starts true when asked already', () => {
        const { result, wrapper } = withSetup(() => useAskedOnce(() => true));
        expect(result.value).toBe(true);
        wrapper.unmount();
    });
});
