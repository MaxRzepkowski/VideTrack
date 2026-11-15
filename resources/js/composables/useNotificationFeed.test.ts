import { useNotificationFeed } from '@/composables/useNotificationFeed';
import { setPageProps, testPage } from '@/test/page';
import { withSetup } from '@/test/withSetup';
import type { NotificationFeed } from '@/types/notifications';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

const fetchMock = vi.fn();
const rendered: NotificationFeed = { unread: 1, recent: [] };
const fresh: NotificationFeed = { unread: 4, recent: [] };

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockResolvedValue(new Response(JSON.stringify(fresh)));
    setPageProps({ notifications: rendered });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

describe('useNotificationFeed', () => {
    it('starts from the page render and polls every minute', async () => {
        const { result, wrapper } = withSetup(() => useNotificationFeed(ref(false)));
        expect(result.feed.value).toEqual(rendered);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetchMock).toHaveBeenCalledWith('/notifications.feed', expect.objectContaining({ method: 'GET' }));
        expect(result.feed.value).toEqual(fresh);

        wrapper.unmount();
    });

    it('lets the next page render win over a poll', async () => {
        const { result, wrapper } = withSetup(() => useNotificationFeed(ref(false)));
        await vi.advanceTimersByTimeAsync(60_000);

        const next = { unread: 0, recent: [] };
        testPage.props.notifications = next;
        await nextTick();

        expect(result.feed.value).toEqual(next);
        wrapper.unmount();
    });

    it('holds still while the list is open, and asks nothing for a guest', async () => {
        const paused = ref(true);
        const { wrapper } = withSetup(() => useNotificationFeed(paused));
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetchMock).not.toHaveBeenCalled();
        wrapper.unmount();

        setPageProps({ notifications: null });
        const guest = withSetup(() => useNotificationFeed(ref(false)));
        guest.result.refresh();
        expect(fetchMock).not.toHaveBeenCalled();
        guest.wrapper.unmount();
    });

    it('keeps the count as it was when the poll fails', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 401 }));
        const { result, wrapper } = withSetup(() => useNotificationFeed(ref(false)));

        result.refresh();
        await vi.runOnlyPendingTimersAsync();

        expect(result.feed.value).toEqual(rendered);
        wrapper.unmount();
    });

    it('stops polling when the header goes', async () => {
        const { wrapper } = withSetup(() => useNotificationFeed(ref(false)));
        wrapper.unmount();

        await vi.advanceTimersByTimeAsync(120_000);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
