import { jsonFetch } from '@/lib/http';
import { onUnmounted, shallowRef, watch, type Ref } from 'vue';

/** How often a list under way asks again. */
export const POLL_MS = 3000;

/**
 * A page's list that moves on its own, like runs under way: the
 * page's props, asked for again as JSON at `url` while something in them is
 * under way, the endpoint answering in the props' shape. A plain fetch, never
 * `router`: a visit would repaint the page under whoever is reading it. One
 * question at a time, so a slow server does not stack them up; an answer to
 * one asked before the props last changed speaks of a list already replaced
 * and is dropped.
 */
export function usePolledWhile<T>(fresh: () => T, url: () => string, underWay: (value: T) => boolean): Ref<T> {
    const value = shallowRef(fresh()) as Ref<T>;
    let generation = 0;
    let asking = false;

    watch(fresh, (props) => {
        generation++;
        value.value = props;
    });

    async function ask(): Promise<void> {
        asking = true;
        const sentAt = generation;

        try {
            const response = await jsonFetch(url());

            if (response.ok) {
                const answer = (await response.json()) as T;

                if (sentAt === generation) {
                    value.value = answer;
                }
            }
        } catch {
            // Offline for a moment: the next tick asks again.
        } finally {
            asking = false;
        }
    }

    const timer = window.setInterval(() => {
        if (underWay(value.value) && !asking) {
            void ask();
        }
    }, POLL_MS);

    onUnmounted(() => window.clearInterval(timer));

    return value;
}
