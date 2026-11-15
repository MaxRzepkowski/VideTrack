import { useConfirmHost } from '@/composables/useConfirm';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { withSetup as mountWith } from '@/test/withSetup';
import { router } from '@inertiajs/vue3';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

interface Visit {
    method: string;
    prefetch: boolean;
    url?: URL;
    preserveScroll?: boolean;
    preserveState?: boolean;
    replace?: boolean;
}

type BeforeListener = (event: { detail: { visit: Visit }; preventDefault: () => void }) => void;

function captureBefore(): { fire: (visit: Visit) => boolean; removed: () => boolean; attached: () => number } {
    let listener: BeforeListener | undefined;
    let removed = false;
    let attached = 0;
    vi.spyOn(router, 'on').mockImplementation(((_: string, callback: BeforeListener) => {
        listener = callback;
        attached++;

        return () => (removed = true);
    }) as unknown as typeof router.on);

    return {
        fire: (visit) => {
            let prevented = false;
            listener?.({ detail: { visit }, preventDefault: () => (prevented = true) });

            return prevented;
        },
        removed: () => removed,
        attached: () => attached,
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

// The guard is one for the whole page, so every test unmounts what it mounted.
const mounted: VueWrapper[] = [];

function withSetup(composable: () => void): { wrapper: VueWrapper } {
    const { wrapper } = mountWith(composable);
    mounted.push(wrapper);

    return { wrapper };
}

afterEach(() => {
    useConfirmHost().answer(false);
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
});

describe('useUnsavedChanges', () => {
    it("asks in the app's own dialog before leaving a dirty page, and stays when the user says no", async () => {
        const before = captureBefore();
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        withSetup(() => useUnsavedChanges(() => true));

        expect(before.fire({ method: 'get', prefetch: false, url: new URL('http://localhost/calendar') })).toBe(true);
        await nextTick();

        const host = useConfirmHost();
        expect(host.pending.value?.message).toBe('You have unsaved changes. Leave this page?');
        expect(host.pending.value?.confirmLabel).toBe('Leave');

        host.answer(false);
        await flush();
        expect(visit).not.toHaveBeenCalled();
    });

    it('makes the visit again once the user agrees to leave, without asking twice', async () => {
        const before = captureBefore();
        const url = new URL('http://localhost/calendar');
        let retryStopped: boolean | undefined;
        // As Inertia does: the visit asks its `before` listeners on the spot.
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {
            retryStopped = before.fire({ method: 'get', prefetch: false, url });
        });
        withSetup(() => useUnsavedChanges(() => true));

        before.fire({ method: 'get', prefetch: false, url, preserveScroll: true, preserveState: false, replace: false });
        useConfirmHost().answer(true);
        await flush();

        expect(visit).toHaveBeenCalledWith(url, expect.objectContaining({ preserveScroll: true, preserveState: false, replace: false }));
        expect(retryStopped).toBe(false);
        // Once made, the next visit away is guarded again.
        expect(before.fire({ method: 'get', prefetch: false, url })).toBe(true);
    });

    it('one guard serves a page with several drafts, and keeps guarding the one left', async () => {
        const before = captureBefore();
        withSetup(() => useUnsavedChanges(() => true));
        const { wrapper } = withSetup(() => useUnsavedChanges(() => false));

        expect(before.attached()).toBe(1);

        wrapper.unmount();
        mounted.pop();
        expect(before.removed()).toBe(false);
        expect(before.fire({ method: 'get', prefetch: false, url: new URL('http://localhost/calendar') })).toBe(true);
    });

    it('lets saves, prefetches and clean pages through without asking', () => {
        const before = captureBefore();
        const dirty = ref(true);
        withSetup(() => useUnsavedChanges(() => dirty.value));

        expect(before.fire({ method: 'patch', prefetch: false })).toBe(false);
        expect(before.fire({ method: 'get', prefetch: true })).toBe(false);
        dirty.value = false;
        expect(before.fire({ method: 'get', prefetch: false })).toBe(false);
        expect(useConfirmHost().pending.value).toBeNull();
    });

    it('guards closing the tab only while dirty, and lets go on unmount', () => {
        captureBefore();
        const add = vi.spyOn(window, 'addEventListener');
        const remove = vi.spyOn(window, 'removeEventListener');
        const dirty = ref(false);
        const { wrapper } = withSetup(() => useUnsavedChanges(() => dirty.value));

        expect(add).not.toHaveBeenCalledWith('beforeunload', expect.any(Function));
        dirty.value = true;
        expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function));

        wrapper.unmount();
        mounted.pop();
        expect(remove).toHaveBeenCalledWith('beforeunload', expect.any(Function));
    });
});
