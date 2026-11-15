import { useDragScroll } from '@/composables/useDragScroll';
import { withSetup } from '@/test/withSetup';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

/** A row 300px wide over 1000px of tiles, each 200px with a 16px gap; scroll is plain data here. */
function fakeRow(tiles = 4) {
    const el = document.createElement('div');
    const state = { scrollLeft: 0 };

    Object.defineProperties(el, {
        scrollLeft: { get: () => state.scrollLeft, set: (value: number) => (state.scrollLeft = value) },
        clientWidth: { value: 300 },
        scrollWidth: { value: 1000 },
        getBoundingClientRect: { value: () => ({ left: 0 }) },
    });
    el.scrollBy = vi.fn();
    el.scrollTo = vi.fn();
    el.setPointerCapture = vi.fn();
    el.hasPointerCapture = vi.fn(() => true);
    el.releasePointerCapture = vi.fn();
    el.style.columnGap = '16px';

    for (let i = 0; i < tiles; i++) {
        const tile = document.createElement('a');
        Object.defineProperty(tile, 'offsetWidth', { value: 200 });
        Object.defineProperty(tile, 'getBoundingClientRect', { value: () => ({ left: i * 216 - state.scrollLeft }) });
        el.appendChild(tile);
    }

    document.body.appendChild(el);

    return el;
}

const pointer = (type: string, clientX: number, pointerType = 'mouse') =>
    ({ type, clientX, pointerId: 1, pointerType, button: 0 }) as unknown as PointerEvent;

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            disconnect() {}
        },
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('useDragScroll', () => {
    it('lights each arrow only while there is somewhere to go, with a pixel of slack', () => {
        const el = fakeRow();
        const { result } = withSetup(() => useDragScroll(ref(el)));

        expect([result.canScrollLeft.value, result.canScrollRight.value]).toEqual([false, true]);

        el.scrollLeft = 699.5;
        result.readScroll();
        expect([result.canScrollLeft.value, result.canScrollRight.value]).toEqual([true, false]);
    });

    it('moves an arrow press by exactly one tile and its gap, or a viewport on an empty row', () => {
        const el = fakeRow();
        withSetup(() => useDragScroll(ref(el))).result.scrollByTile(1);
        expect(el.scrollBy).toHaveBeenCalledWith({ left: 216, behavior: 'smooth' });

        const empty = fakeRow(0);
        withSetup(() => useDragScroll(ref(empty))).result.scrollByTile(-1);
        expect(empty.scrollBy).toHaveBeenCalledWith({ left: -300, behavior: 'smooth' });
    });

    it('leaves a short press a click, so the tile still opens', () => {
        const el = fakeRow();
        const { result } = withSetup(() => useDragScroll(ref(el)));

        result.startDrag(pointer('pointerdown', 100));
        result.moveDrag(pointer('pointermove', 102));
        window.dispatchEvent(Object.assign(new Event('pointerup'), { pointerId: 1 }));

        const click = new MouseEvent('click', { cancelable: true });
        result.guardClick(click);
        expect(click.defaultPrevented).toBe(false);
        expect(el.scrollLeft).toBe(0);
    });

    it('follows a drag pixel for pixel, swallows the click that ends it and glides to the nearest tile', () => {
        const el = fakeRow();
        const { result } = withSetup(() => useDragScroll(ref(el)));

        result.startDrag(pointer('pointerdown', 300));
        result.moveDrag(pointer('pointermove', 200));
        expect(el.scrollLeft).toBe(100);
        expect(result.dragging.value).toBe(true);
        expect(result.snapOff.value).toBe(true);
        expect(el.setPointerCapture).toHaveBeenCalledWith(1);

        window.dispatchEvent(Object.assign(new Event('pointerup'), { pointerId: 1 }));
        expect(result.dragging.value).toBe(false);
        expect(el.scrollTo).toHaveBeenCalledWith({ left: 0, behavior: 'smooth' });
        expect(result.snapOff.value).toBe(true);

        const click = new MouseEvent('click', { cancelable: true });
        result.guardClick(click);
        expect(click.defaultPrevented).toBe(true);

        vi.advanceTimersByTime(600);
        expect(result.snapOff.value).toBe(false);
    });

    it('takes a glide in progress along when the row goes', () => {
        const el = fakeRow();
        const remove = vi.spyOn(el, 'removeEventListener');
        const { result, wrapper } = withSetup(() => useDragScroll(ref(el)));

        result.startDrag(pointer('pointerdown', 300));
        result.moveDrag(pointer('pointermove', 200));
        window.dispatchEvent(Object.assign(new Event('pointerup'), { pointerId: 1 }));
        wrapper.unmount();

        expect(remove).toHaveBeenCalledWith('scrollend', expect.any(Function));
        expect(vi.getTimerCount()).toBe(0);
    });

    it('leaves touch to the browser', () => {
        const el = fakeRow();
        const { result } = withSetup(() => useDragScroll(ref(el)));

        result.startDrag(pointer('pointerdown', 300, 'touch'));
        result.moveDrag(pointer('pointermove', 100, 'touch'));

        expect(el.scrollLeft).toBe(0);
        expect(result.dragging.value).toBe(false);
    });
});
