import { sheetDragCloses } from '@/lib/sheet';
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';

/**
 * A sheet pulled down by its handle: it follows the finger with no easing,
 * closes when let go far or fast enough (`sheetDragCloses`), and eases back
 * otherwise. One finger, or the main button, pulls: a second finger or a right
 * click moves nothing. Bind `grab`, `pull` and `release` to the handle's
 * pointer events and `followFinger` to the sheet's style.
 */
export function useSheetDrag(open: Ref<boolean>): {
    grab: (event: PointerEvent) => void;
    pull: (event: PointerEvent) => void;
    release: (event: PointerEvent) => void;
    followFinger: ComputedRef<{ transform: string | undefined; transition: string }>;
} {
    const pulled = ref(0);
    const pulling = ref(false);
    let startY = 0;
    let startedAt = 0;
    let pointer: number | null = null;

    // A sheet that opens again starts where it belongs, not where it was let go.
    watch(open, (isOpen) => {
        if (isOpen) {
            pulled.value = 0;
        }
    });

    function grab(event: PointerEvent): void {
        if (event.button !== 0 || pointer !== null) {
            return;
        }

        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
        pointer = event.pointerId;
        pulling.value = true;
        startY = event.clientY;
        startedAt = performance.now();
    }

    function pull(event: PointerEvent): void {
        if (event.pointerId === pointer) {
            pulled.value = Math.max(0, event.clientY - startY);
        }
    }

    function release(event: PointerEvent): void {
        if (event.pointerId !== pointer) {
            return;
        }

        pointer = null;
        pulling.value = false;

        if (sheetDragCloses(pulled.value, performance.now() - startedAt)) {
            open.value = false;
        } else {
            pulled.value = 0;
        }
    }

    const followFinger = computed(() => ({
        transform: pulled.value > 0 ? `translateY(${pulled.value}px)` : undefined,
        transition: pulling.value ? 'none' : 'transform 200ms ease-out',
    }));

    return { grab, pull, release, followFinger };
}
