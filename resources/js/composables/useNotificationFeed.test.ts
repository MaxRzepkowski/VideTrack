import { useNotificationFeed } from '@/composables/useNotificationFeed';
import { notificationEntry, userPreferences } from '@/test/factories';
import { setPageProps, testPage } from '@/test/page';
import { withSetup } from '@/test/withSetup';
import type { NotificationFeed } from '@/types/notifications';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

const fetchMock = vi.fn();
const fresh: NotificationFeed = { unread: 4, recent: [notificationEntry()] };

// The list outlives the header, so each test signs in someone the last one did not.
let accounts = 100;

function signIn(id: number, unread: number | null = 1): void {
    setPageProps({
        unreadNotifications: unread,
        auth: { user: { id, name: 'Alex', username: `alex${id}`, preferences: userPreferences(), password_sign_in: true } },
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockResolvedValue(new Response(JSON.stringify(fresh)));
    signIn(++accounts);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

describe('useNotificationFeed', () => {
    it('starts from the counter the page carried, without a list, and polls every minute', async () => {
        const { result, wrapper } = withSetup(() => useNotificationFeed(ref(false)));
        expect(result.unread.value).toBe(1);
        expect(result.recent.value).toBeNull();

        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetchMock).toHaveBeenCalledWith('/notifications.feed', expect.objectContaining({ method: 'GET' }));
        expect(result.unread.value).toBe(4);
        expect(result.recent.value).toEqual(fresh.recent);

        wrapper.unmount();
    });

    it('lets the next page render win over a poll for the counter', async () => {
        const { result, wrapper } = withSetup(() => useNotificationFeed(ref(false)));
        await vi.advanceTimersByTimeAsync(60_000);

        testPage.props.unreadNotifications = 0;
        await nextTick();

        expect(result.unread.value).toBe(0);
        wrapper.unmount();
    });

    it("opens the next page's bell on the list it last showed, but never on another account's", async () => {
        const first = withSetup(() => useNotificationFeed(ref(false)));
        first.result.refresh();
        await vi.runOnlyPendingTimersAsync();
        first.wrapper.unmount();

        // The header is drawn anew with the next page.
        const next = withSetup(() => useNotificationFeed(ref(false)));
        expect(next.result.recent.value).toEqual(fresh.recent);
        next.wrapper.unmount();

        signIn(++accounts);
        const someoneElse = withSetup(() => useNotificationFeed(ref(false)));
        expect(someoneElse.result.recent.value).toBeNull();
        someoneElse.wrapper.unmount();
    });

    it('holds still while the list is open, and asks nothing for a guest', async () => {
        const paused = ref(true);
        const { wrapper } = withSetup(() => useNotificationFeed(paused));
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetchMock).not.toHaveBeenCalled();
        wrapper.unmount();

        setPageProps({ unreadNotifications: null, auth: { user: null } });
        const guest = withSetup(() => useNotificationFeed(ref(false)));
        guest.result.refresh();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(guest.result.unread.value).toBeNull();
        guest.wrapper.unmount();
    });

    it('keeps the count as it was when the poll fails', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 401 }));
        const { result, wrapper } = withSetup(() => useNotificationFeed(ref(false)));

        result.refresh();
        await vi.runOnlyPendingTimersAsync();

        expect(result.unread.value).toBe(1);
        expect(result.recent.value).toBeNull();
        wrapper.unmount();
    });

    it('stops polling when the header goes', async () => {
        const { wrapper } = withSetup(() => useNotificationFeed(ref(false)));
        wrapper.unmount();

        await vi.advanceTimersByTimeAsync(120_000);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
