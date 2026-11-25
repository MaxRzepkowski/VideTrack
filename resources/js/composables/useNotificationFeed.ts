import { jsonFetch } from '@/lib/http';
import type { SharedData } from '@/types';
import type { NotificationEntry, NotificationFeed } from '@/types/notifications';
import { usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch, type ComputedRef, type Ref } from 'vue';

// The bell is written to by the queue, not by the person looking at it: a job
// pings Discord the moment an episode airs, while the shared Inertia prop only
// ever describes the last page render. Without asking again, an open tab shows
// yesterday's count until someone navigates.
//
// Asked with a plain `fetch` against a JSON endpoint, never through `router` —
// an Inertia visit re-renders the page under the pointer (an open <select>
// would lose its dropdown mid-click).
const POLL_MS = 60_000;

// The list as the feed last answered it, held here rather than in the header:
// every page draws its header anew, and the bell should open on what it last
// showed while it asks again. Kept with whose it is, so another account signed
// in on the same tab never opens on someone else's.
const lastList = ref<{ userId: number; entries: NotificationEntry[] } | null>(null);

export function useNotificationFeed(paused: Ref<boolean>): {
    /** How many are unread; null for a guest, who has no bell. */
    unread: ComputedRef<number | null>;
    /** The latest few, newest first; null until the feed has been asked for once. */
    recent: ComputedRef<NotificationEntry[] | null>;
    refresh: () => void;
} {
    const page = usePage<SharedData>();

    // The counter starts as the page render carried it, and every later
    // navigation wins over a poll: that response is by definition the fresher
    // one. The list comes only with the feed.
    const polledUnread = ref<number | null>(null);
    const unread = computed(() => (page.props.unreadNotifications === null ? null : (polledUnread.value ?? page.props.unreadNotifications)));
    const viewerId = () => page.props.auth.user?.id ?? null;
    const recent = computed(() => (lastList.value !== null && lastList.value.userId === viewerId() ? lastList.value.entries : null));

    watch(
        () => page.props.unreadNotifications,
        () => {
            polledUnread.value = null;
        },
    );

    let inFlight = false;

    function refresh(): void {
        // Guests have no bell; a second request while one is out would only
        // race it to the same answer.
        const userId = viewerId();

        if (inFlight || page.props.unreadNotifications === null || userId === null) {
            return;
        }

        inFlight = true;

        void jsonFetch(route('notifications.feed'))
            .then((response) => (response.ok ? (response.json() as Promise<NotificationFeed>) : null))
            .then((fresh) => {
                if (fresh) {
                    polledUnread.value = fresh.unread;
                    lastList.value = { userId, entries: fresh.recent };
                }
            })
            // A dropped connection or an expired session is not worth a toast:
            // the counter simply stays as it was until the next tick.
            .catch(() => undefined)
            .finally(() => {
                inFlight = false;
            });
    }

    const timer = window.setInterval(() => {
        // Nothing to refresh for a hidden tab, and nothing that may move while
        // the list is open under the pointer — reopening asks again anyway.
        if (!document.hidden && !paused.value) {
            refresh();
        }
    }, POLL_MS);

    // Coming back to the tab is the moment the stale count is most visible.
    const onVisibility = () => {
        if (!document.hidden && !paused.value) {
            refresh();
        }
    };

    document.addEventListener('visibilitychange', onVisibility);

    onUnmounted(() => {
        window.clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { unread, recent, refresh };
}
