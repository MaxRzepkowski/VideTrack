import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/** One press of a zoom button, in or out. */
export const ZOOM_STEP = 1.6;
/** How far the cursor travels before a press becomes a drag; below it the press stays a click. */
export const DRAG_THRESHOLD = 4;
/** Wheel travel to zoom factor: a notch of a mouse wheel (~100) is a little under one button press. */
const WHEEL_RATE = 0.004;

/**
 * Zoom and pan over a drawing that fills its viewport: buttons, a drag with
 * one pointer, a pinch with two, and the wheel with Ctrl held (the trackpad's
 * pinch arrives as that too). The plain wheel is left to the page, or it would
 * stop scrolling every time the cursor crossed the drawing. The mechanics
 * only: the drawing reads `scale`, `x` and `y` (CSS pixels, the top left
 * corner's offset) and lays itself out.
 *
 * The drawing never leaves the viewport: at 1 it sits as drawn, and zoomed in
 * it can be panned only as far as its own edges.
 */
export function useZoom(viewport: Ref<HTMLElement | null>, max: Ref<number>) {
    const scale = ref(1);
    const x = ref(0);
    const y = ref(0);
    const zoomed = computed(() => scale.value > 1);

    const size = (): { width: number; height: number } => ({
        width: viewport.value?.clientWidth ?? 0,
        height: viewport.value?.clientHeight ?? 0,
    });

    const clamp = (value: number, low: number, high: number): number => Math.min(high, Math.max(low, value));

    const keepInside = () => {
        const { width, height } = size();

        x.value = clamp(x.value, width - width * scale.value, 0);
        y.value = clamp(y.value, height - height * scale.value, 0);
    };

    /** Zooms by `factor` keeping the point (`px`, `py`) of the viewport where it is. */
    const zoomAt = (factor: number, px: number, py: number) => {
        const next = clamp(scale.value * factor, 1, Math.max(1, max.value));
        const k = next / scale.value;

        x.value = px - (px - x.value) * k;
        y.value = py - (py - y.value) * k;
        scale.value = next;
        keepInside();
    };

    const zoomAtCentre = (factor: number) => {
        const { width, height } = size();

        zoomAt(factor, width / 2, height / 2);
    };

    const zoomIn = () => zoomAtCentre(ZOOM_STEP);
    const zoomOut = () => zoomAtCentre(1 / ZOOM_STEP);
    const reset = () => {
        scale.value = 1;
        x.value = 0;
        y.value = 0;
    };

    const local = (event: { clientX: number; clientY: number }): { px: number; py: number } => {
        const box = viewport.value?.getBoundingClientRect();

        return { px: event.clientX - (box?.left ?? 0), py: event.clientY - (box?.top ?? 0) };
    };

    const onWheel = (event: WheelEvent) => {
        if (!event.ctrlKey) {
            return;
        }

        event.preventDefault();
        const { px, py } = local(event);
        zoomAt(Math.exp(-event.deltaY * WHEEL_RATE), px, py);
    };

    // The pointers down on the drawing, where each was last.
    const pointers = new Map<number, { clientX: number; clientY: number }>();
    let travelled = 0;
    let dragging = false;
    let swallowClick = false;

    const onPointerdown = (event: PointerEvent) => {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        pointers.set(event.pointerId, { clientX: event.clientX, clientY: event.clientY });

        if (pointers.size === 1) {
            travelled = 0;
            dragging = false;
            swallowClick = false;
        }
    };

    const onPointermove = (event: PointerEvent) => {
        const last = pointers.get(event.pointerId);

        if (last === undefined) {
            return;
        }

        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const other = a === last ? b : a;

            if (other !== undefined) {
                const before = Math.hypot(last.clientX - other.clientX, last.clientY - other.clientY);
                const after = Math.hypot(event.clientX - other.clientX, event.clientY - other.clientY);
                const { px, py } = local({ clientX: (event.clientX + other.clientX) / 2, clientY: (event.clientY + other.clientY) / 2 });

                if (before > 0) {
                    zoomAt(after / before, px, py);
                }
            }

            dragging = true;
        } else if (zoomed.value) {
            travelled += Math.hypot(event.clientX - last.clientX, event.clientY - last.clientY);

            // The drag earns the pointer before it takes it: a press that does not move stays a click on whatever lies under it.
            if (!dragging && travelled >= DRAG_THRESHOLD) {
                dragging = true;
                viewport.value?.setPointerCapture(event.pointerId);
            }

            if (dragging) {
                x.value += event.clientX - last.clientX;
                y.value += event.clientY - last.clientY;
                keepInside();
            }
        }

        pointers.set(event.pointerId, { clientX: event.clientX, clientY: event.clientY });
    };

    const onPointerup = (event: PointerEvent) => {
        if (!pointers.delete(event.pointerId)) {
            return;
        }

        if (viewport.value?.hasPointerCapture(event.pointerId) === true) {
            viewport.value.releasePointerCapture(event.pointerId);
        }

        if (pointers.size === 0) {
            swallowClick = dragging;
            dragging = false;
        }
    };

    // Capture phase: the click that ends a drag dies before it reaches what lies under the pointer.
    const onClickCapture = (event: MouseEvent) => {
        if (swallowClick) {
            swallowClick = false;
            event.preventDefault();
            event.stopPropagation();
        }
    };

    // A press released off the drawing was never captured; the window hears its end.
    onMounted(() => {
        window.addEventListener('pointerup', onPointerup);
        window.addEventListener('pointercancel', onPointerup);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('pointerup', onPointerup);
        window.removeEventListener('pointercancel', onPointerup);
    });

    return { scale, x, y, zoomed, zoomIn, zoomOut, reset, onWheel, onPointerdown, onPointermove, onClickCapture };
}
