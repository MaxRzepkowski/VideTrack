import { jsonFetch } from '@/lib/http';
import type { SharedData } from '@/types';
import type { NotificationFeed } from '@/types/notifications';
import { usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch, type Ref } from 'vue';

// The bell is written to by the queue, not by the person looking at it: a job
// pings Discord the moment an episode airs, while the shared Inertia prop only
// ever describes the last page render. Without asking again, an open tab shows
// yesterday's count until someone navigates.
//
// Asked with a plain `fetch` against a JSON endpoint, never through `router` —
// an Inertia visit re-renders the page under the pointer (an open <select>
// would lose its dropdown mid-click).
const POLL_MS = 60_000;

export function useNotificationFeed(paused: Ref<boolean>): {
    feed: Ref<NotificationFeed | null>;
    refresh: () => void;
} {
    const page = usePage<SharedData>();

    // Starts as whatever the page render carried, and every later navigation
    // wins over a poll: that response is by definition the fresher one.
    const polled = ref<NotificationFeed | null>(null);
    const feed = computed(() => polled.value ?? page.props.notifications);

    watch(
        () => page.props.notifications,
        () => {
            polled.value = null;
        },
    );

    let inFlight = false;

    function refresh(): void {
        // Guests have no bell; a second request while one is out would only
        // race it to the same answer.
        if (inFlight || page.props.notifications === null) {
            return;
        }

        inFlight = true;

        void jsonFetch(route('notifications.feed'))
            .then((response) => (response.ok ? (response.json() as Promise<NotificationFeed>) : null))
            .then((fresh) => {
                if (fresh) {
                    polled.value = fresh;
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

    return { feed, refresh };
}
