import { onScopeDispose, readonly, ref, type Ref } from 'vue';

// A single shared 1-second clock: every countdown card reads the same `now`
// rather than each owning an interval. Ref-counted so the timer only runs while
// at least one component is using it.
const now = ref<number>(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;
let subscribers = 0;

function start(): void {
    if (timer === null) {
        timer = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    }
}

function stop(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

/** Reactive current epoch-ms, updated once a second and shared across callers. */
export function useNow(): Readonly<Ref<number>> {
    subscribers++;
    now.value = Date.now();
    start();

    onScopeDispose(() => {
        subscribers--;

        if (subscribers <= 0) {
            subscribers = 0;
            stop();
        }
    });

    return readonly(now);
}
