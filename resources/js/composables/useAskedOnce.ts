import { readonly, ref, watch, type Ref } from 'vue';

/**
 * Whether something has been asked for at least once: false until the source
 * turns true, then true for good. What mounts on the first ask (a deferred
 * modal) stays mounted, so it closes with its own animation and opens again at
 * once.
 */
export function useAskedOnce(wanted: () => boolean): Readonly<Ref<boolean>> {
    const asked = ref(wanted());

    if (!asked.value) {
        const stop = watch(wanted, (now) => {
            if (now) {
                asked.value = true;
                stop();
            }
        });
    }

    return readonly(asked);
}
