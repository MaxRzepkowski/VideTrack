import { jsonFetch } from '@/lib/http';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { onMounted } from 'vue';

// The "Automatic" timezone preference is resolved here, from `Intl` — the
// server never sees it. Queued work has no browser, so without reporting it
// the Discord digest would read the instance timezone and fire at the wrong
// local hour.
//
// Reported with a plain fetch against an endpoint that answers 204, never
// through `router`: an Inertia visit re-renders the page it lands on, and this
// fires while the user is already reading and clicking. Going through
// preferences.update would re-render the page and tear any open <select> out
// from under the pointer mid-click.
const SESSION_KEY = 'videtrack:timezone-reported';

export function useTimezoneSync(): void {
    const page = usePage<SharedData>();

    onMounted(() => {
        const user = page.props.auth?.user;

        if (!user || sessionStorage.getItem(SESSION_KEY) !== null) {
            return;
        }

        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (!zone || user.preferences.detected_timezone === zone) {
            return;
        }

        // Claimed up front so a slow round trip cannot be started twice, then
        // released again if it failed for a reason a later page load could get
        // past — an expired CSRF token on a long-open tab, a dropped
        // connection. Left claimed on 422: the browser named a zone the server
        // rejects, and asking again with the same answer changes nothing.
        // Never surfaced to the user; the cost of losing it is a digest that
        // goes out on instance time until the next navigation retries.
        sessionStorage.setItem(SESSION_KEY, zone);

        // Nothing on screen depends on the answer.
        void jsonFetch(route('settings.timezone.detected'), { method: 'PATCH', body: { detected_timezone: zone } })
            .then((response) => {
                if (!response.ok && response.status !== 422) {
                    sessionStorage.removeItem(SESSION_KEY);
                }
            })
            .catch(() => sessionStorage.removeItem(SESSION_KEY));
    });
}
