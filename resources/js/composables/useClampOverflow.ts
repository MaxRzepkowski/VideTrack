import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * Whether a clamped block of text runs past its clamp, so it needs a "Show
 * more". Measured on mount, again once the webfont is in (the fallback font's
 * narrower metrics can hide the overflow), and whenever the block changes size:
 * a narrowed window clamps a text that used to fit, a widened one frees it.
 * An open text cannot overflow, so while it is open the answer it had when
 * clamped stands.
 */
export function useClampOverflow(element: Ref<HTMLElement | null>, clamped: () => boolean): Ref<boolean> {
    const overflows = ref(false);
    let observer: ResizeObserver | null = null;

    function measure(): void {
        const el = element.value;

        if (el && clamped()) {
            overflows.value = el.scrollHeight > el.clientHeight + 1;
        }
    }

    onMounted(() => {
        measure();
        void document.fonts?.ready.then(measure);

        if (element.value) {
            observer = new ResizeObserver(measure);
            observer.observe(element.value);
        }
    });

    onBeforeUnmount(() => observer?.disconnect());

    return overflows;
}
