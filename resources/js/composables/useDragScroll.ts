import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * A horizontal row that scrolls by arrows, by wheel and touch, and by a mouse
 * drag that settles on the nearest tile — the mechanics, with no markup.
 * ScrollRow is the markup that uses them.
 */
export function useDragScroll(row: Ref<HTMLElement | null>) {
    const canScrollLeft = ref(false);
    const canScrollRight = ref(false);

    // Both ends are measured with a pixel of slack: a scroll position is
    // fractional under browser zoom, so `scrollLeft + clientWidth` lands a hair
    // short of `scrollWidth` at the end of the row and would leave the right
    // arrow lit with nothing left to reach.
    const readScroll = () => {
        const el = row.value;
        if (!el) return;

        canScrollLeft.value = el.scrollLeft > 1;
        canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
    };

    // A press moves by exactly one tile. The step is measured off the first tile
    // rather than computed from the basis, because the basis is a percentage that
    // `grow` may have stretched; the gap comes from the row's own style for the
    // same reason. An empty row falls back to a viewport's worth.
    const scrollByTile = (direction: -1 | 1) => {
        const el = row.value;
        if (!el) return;

        const tile = el.firstElementChild as HTMLElement | null;
        const gap = Number.parseFloat(getComputedStyle(el).columnGap) || 0;

        el.scrollBy({ left: direction * (tile ? tile.offsetWidth + gap : el.clientWidth), behavior: 'smooth' });
    };

    /**
     * Drag to scroll, for the mouse only — touch already pans the row natively and
     * a second implementation on top of it would fight the browser's momentum.
     *
     * Every tile is a link, so the drag has to earn the pointer before it takes
     * it: nothing moves until the cursor has travelled past a threshold, and only
     * then is the pointer captured. Below it the press stays an ordinary click and
     * the link opens. Past it the click that closes the gesture is swallowed,
     * because a drag that ends over a tile must not also navigate to it.
     */
    const DRAG_THRESHOLD = 4;

    const dragging = ref(false);
    let dragPointer: number | null = null;
    let dragOriginX = 0;
    let dragOriginScroll = 0;
    let dragMoved = false;
    let swallowClick = false;

    const startDrag = (event: PointerEvent) => {
        if (event.pointerType !== 'mouse' || event.button !== 0 || !row.value) return;

        dragPointer = event.pointerId;
        dragOriginX = event.clientX;
        dragOriginScroll = row.value.scrollLeft;
        dragMoved = false;
        swallowClick = false;
    };

    const moveDrag = (event: PointerEvent) => {
        const el = row.value;
        if (dragPointer !== event.pointerId || !el) return;

        const travelled = event.clientX - dragOriginX;
        if (!dragMoved && Math.abs(travelled) < DRAG_THRESHOLD) return;

        if (!dragMoved) {
            dragMoved = true;
            dragging.value = true;
            el.setPointerCapture(event.pointerId);
        }

        // One pixel of cursor is one pixel of row while the button is down: CSS
        // snapping is off for the duration, or it would haul the row back to a
        // tile edge on every frame and the drag would never follow the hand.
        el.scrollLeft = dragOriginScroll - travelled;
    };

    /**
     * Where the row comes to rest once the drag ends: the tile nearest to where it
     * was let go, glided to rather than jumped to.
     *
     * The settle is animated by hand instead of being handed back to CSS, because
     * switching `scroll-snap-type` back to `mandatory` mid-flight re-snaps the row
     * instantly — the drag would end in a jump, which is the gesture-decides-for-you
     * feel this is meant to avoid. Snapping stays off until the row has stopped,
     * and `scrollend` (with a timer behind it, for the browsers that still lack the
     * event) is what says so.
     */
    const settling = ref(false);

    // The glide in progress, so leaving the page mid-glide takes its timer and
    // listener along instead of leaving them on a detached row.
    let stopSettling: (() => void) | null = null;

    // Snapping belongs to the wheel and to touch, which have no settle of their
    // own; the pointer drag owns the row from the moment it takes it until the
    // glide that ends it has finished.
    //
    // This rides on the element's `style`, not on a `snap-none` class. Both
    // utilities set `scroll-snap-type` at the same specificity, so which one wins
    // is decided by their order in the generated sheet rather than by the order
    // they are written in — and with `mandatory` left standing the browser hauls
    // every programmatic `scrollLeft` back to the nearest tile, which reads as a
    // drag that does nothing at all.
    const snapOff = computed(() => dragging.value || settling.value);

    const settle = () => {
        const el = row.value;
        if (!el) return;

        // A tile's snap position is the left edge of the snapport, which is the
        // row's padding box pulled in by `scroll-padding-left` — below `md` that
        // is the page gutter, so a settled tile lines up with the text above it
        // instead of running to the screen edge. Read it rather than assume it,
        // and fall back to the padding when it is left at `auto`.
        //
        // Rects rather than `offsetLeft`: the row is not the offset parent, and
        // below `md` it carries a negative margin `offsetLeft` would fold in.
        const style = getComputedStyle(el);
        const inset = Number.parseFloat(style.scrollPaddingLeft) || Number.parseFloat(style.paddingLeft) || 0;
        const edge = el.getBoundingClientRect().left + inset;
        const limit = el.scrollWidth - el.clientWidth;

        let target = el.scrollLeft;
        let closest = Number.POSITIVE_INFINITY;

        for (const tile of Array.from(el.children) as HTMLElement[]) {
            const at = Math.min(Math.max(el.scrollLeft + tile.getBoundingClientRect().left - edge, 0), limit);
            const distance = Math.abs(at - el.scrollLeft);

            if (distance < closest) {
                closest = distance;
                target = at;
            }
        }

        let timer = 0;

        const stopped = () => {
            settling.value = false;
            el.removeEventListener('scrollend', stopped);
            window.clearTimeout(timer);
            stopSettling = null;
        };

        stopSettling?.();
        settling.value = true;
        el.scrollTo({ left: target, behavior: 'smooth' });
        el.addEventListener('scrollend', stopped);
        timer = window.setTimeout(stopped, 600);
        stopSettling = stopped;
    };

    const endDrag = (event: PointerEvent) => {
        if (dragPointer !== event.pointerId) return;

        const el = row.value;
        if (el?.hasPointerCapture(event.pointerId)) el.releasePointerCapture(event.pointerId);

        swallowClick = dragMoved;
        dragPointer = null;
        dragging.value = false;

        if (dragMoved) settle();
        dragMoved = false;
    };

    // Capture phase: the click has to die before it reaches the tile's link.
    const guardClick = (event: MouseEvent) => {
        if (!swallowClick) return;

        swallowClick = false;
        event.preventDefault();
        event.stopPropagation();
    };

    // The row's overflow changes with the viewport, not only with scrolling, so
    // the arrows answer to a resize observer as well as to the scroll event.
    let rowObserver: ResizeObserver | null = null;

    onMounted(() => {
        readScroll();

        if (row.value) {
            rowObserver = new ResizeObserver(readScroll);
            rowObserver.observe(row.value);
        }

        // A press released off the row never crossed the threshold, so it was
        // never captured — the window is what hears its end.
        window.addEventListener('pointerup', endDrag);
        window.addEventListener('pointercancel', endDrag);
    });

    onBeforeUnmount(() => {
        stopSettling?.();
        rowObserver?.disconnect();
        window.removeEventListener('pointerup', endDrag);
        window.removeEventListener('pointercancel', endDrag);
    });

    return { canScrollLeft, canScrollRight, dragging, snapOff, readScroll, scrollByTile, startDrag, moveDrag, guardClick };
}
