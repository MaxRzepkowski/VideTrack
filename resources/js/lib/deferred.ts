import { defineAsyncComponent, type Component } from 'vue';

// A browser without requestIdleCallback (Safari) gets the same work after a
// pause long enough for the page to have settled.
export const IDLE_FALLBACK_MS = 2000;

/** Run work once the browser has nothing more pressing to do. */
export function whenIdle(work: () => void): void {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(() => work());
    } else {
        setTimeout(work, IDLE_FALLBACK_MS);
    }
}

/**
 * A component no page draws until a click asks for it (a modal, the command
 * palette): its code stays out of what every page loads first, and is fetched
 * while the browser idles, so it is in hand by the time the click comes.
 */
export function deferredComponent(load: () => Promise<{ default: Component }>): Component {
    whenIdle(() => void load());

    return defineAsyncComponent(load);
}
