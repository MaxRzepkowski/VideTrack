import { DRAG_THRESHOLD, useZoom, ZOOM_STEP } from '@/composables/useZoom';
import { withSetup } from '@/test/withSetup';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

/** A viewport 400×200 at the page's top left. */
function fakeViewport(): HTMLElement {
    const el = document.createElement('div');

    Object.defineProperties(el, {
        clientWidth: { value: 400 },
        clientHeight: { value: 200 },
        getBoundingClientRect: { value: () => ({ left: 0, top: 0 }) },
    });
    el.setPointerCapture = vi.fn();
    el.hasPointerCapture = vi.fn(() => true);
    el.releasePointerCapture = vi.fn();

    return el;
}

const pointer = (clientX: number, clientY: number, pointerId = 1) =>
    ({ clientX, clientY, pointerId, pointerType: 'mouse', button: 0 }) as unknown as PointerEvent;

const zoomOver = (max = 8) => {
    const el = fakeViewport();

    return { el, zoom: withSetup(() => useZoom(ref(el), ref(max))).result };
};

describe('useZoom', () => {
    it('zooms a button press about the centre, which stays put, up to the most and back to the whole', () => {
        const { zoom } = zoomOver(2);

        zoom.zoomIn();
        expect(zoom.scale.value).toBeCloseTo(ZOOM_STEP);
        expect(200 * zoom.scale.value + zoom.x.value).toBeCloseTo(200);
        expect(100 * zoom.scale.value + zoom.y.value).toBeCloseTo(100);

        zoom.zoomIn();
        expect(zoom.scale.value).toBe(2);

        zoom.zoomOut();
        zoom.zoomOut();
        expect([zoom.scale.value, zoom.x.value, zoom.y.value]).toEqual([1, 0, 0]);
    });

    it('zooms on the wheel only with Ctrl held, about the cursor, and leaves the plain wheel to the page', () => {
        const { zoom } = zoomOver();
        const plain = { ctrlKey: false, deltaY: -100, clientX: 0, clientY: 0, preventDefault: vi.fn() } as unknown as WheelEvent;
        const held = { ctrlKey: true, deltaY: -100, clientX: 0, clientY: 0, preventDefault: vi.fn() } as unknown as WheelEvent;

        zoom.onWheel(plain);
        expect(zoom.scale.value).toBe(1);
        expect(plain.preventDefault).not.toHaveBeenCalled();

        zoom.onWheel(held);
        expect(zoom.scale.value).toBeGreaterThan(1);
        expect([zoom.x.value, zoom.y.value]).toEqual([0, 0]);
        expect(held.preventDefault).toHaveBeenCalled();
    });

    it('pans a zoomed drawing by a drag, no further than its edges, and swallows the click that ends it', () => {
        const { el, zoom } = zoomOver();

        zoom.zoomIn();
        zoom.onPointerdown(pointer(200, 100));
        zoom.onPointermove(pointer(200 + DRAG_THRESHOLD / 2, 100));
        expect(el.setPointerCapture).not.toHaveBeenCalled();

        zoom.onPointermove(pointer(1000, 100));
        expect(el.setPointerCapture).toHaveBeenCalled();
        expect(zoom.x.value).toBe(0);

        zoom.onPointermove(pointer(-5000, 100));
        expect(zoom.x.value).toBeCloseTo(400 - 400 * zoom.scale.value);

        window.dispatchEvent(Object.assign(new Event('pointerup'), { pointerId: 1 }));
        const click = { preventDefault: vi.fn(), stopPropagation: vi.fn() } as unknown as MouseEvent;
        zoom.onClickCapture(click);
        expect(click.preventDefault).toHaveBeenCalled();
    });

    it('leaves a press on the drawing as it is drawn a click, and does not pan it', () => {
        const { zoom } = zoomOver();

        zoom.onPointerdown(pointer(200, 100));
        zoom.onPointermove(pointer(300, 100));
        window.dispatchEvent(Object.assign(new Event('pointerup'), { pointerId: 1 }));

        const click = { preventDefault: vi.fn(), stopPropagation: vi.fn() } as unknown as MouseEvent;
        zoom.onClickCapture(click);
        expect(zoom.x.value).toBe(0);
        expect(click.preventDefault).not.toHaveBeenCalled();
    });

    it('zooms a pinch about the middle of the two fingers', () => {
        const { zoom } = zoomOver();

        zoom.onPointerdown(pointer(150, 100, 1));
        zoom.onPointerdown(pointer(250, 100, 2));
        zoom.onPointermove(pointer(300, 100, 2));

        expect(zoom.scale.value).toBeCloseTo(1.5);
    });
});
