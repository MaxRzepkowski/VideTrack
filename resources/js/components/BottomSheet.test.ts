import BottomSheet from '@/components/BottomSheet.vue';
import { SHEET_CLOSE_DISTANCE } from '@/lib/sheet';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

function sheet() {
    const wrapper = mount(BottomSheet, {
        props: { open: true, title: 'Notifications', 'onUpdate:open': (open: boolean) => wrapper.setProps({ open }) },
        slots: { default: '<p>Inside</p>' },
        attachTo: document.body,
    });

    return wrapper;
}

const handle = () => document.querySelector<HTMLElement>('.cursor-grab')!;

function pullBy(distance: number): void {
    // The page's clock stands still in tests, and Vue drops an event stamped
    // no later than the moment its listener was bound: the finger comes later.
    vi.setSystemTime(Date.now() + 1000);
    handle().dispatchEvent(new PointerEvent('pointerdown', { clientY: 100, pointerId: 1, button: 0, bubbles: true }));
    handle().dispatchEvent(new PointerEvent('pointermove', { clientY: 100 + distance, pointerId: 1, bubbles: true }));
}

const content = () => document.querySelector<HTMLElement>('[role=dialog]')!;

describe('BottomSheet', () => {
    it('follows the handle down and closes when let go far enough', async () => {
        const wrapper = sheet();
        await flushPromises();

        pullBy(SHEET_CLOSE_DISTANCE + 20);
        await flushPromises();

        expect(content().style.transform).toBe(`translateY(${SHEET_CLOSE_DISTANCE + 20}px)`);

        handle().dispatchEvent(new PointerEvent('pointerup', { pointerId: 1, bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);

        wrapper.unmount();
    });

    it('never moves up, and stays open when pulled that way', async () => {
        const wrapper = sheet();
        await flushPromises();

        pullBy(-30);
        await flushPromises();
        expect(content().style.transform).toBe('');

        handle().dispatchEvent(new PointerEvent('pointerup', { pointerId: 1, bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('update:open')).toBeUndefined();
        expect(content().textContent).toContain('Inside');

        wrapper.unmount();
    });

    it('moves for the main button only', async () => {
        const wrapper = sheet();
        await flushPromises();

        vi.setSystemTime(Date.now() + 1000);
        handle().dispatchEvent(new PointerEvent('pointerdown', { clientY: 100, pointerId: 1, button: 2, bubbles: true }));
        handle().dispatchEvent(new PointerEvent('pointermove', { clientY: 400, pointerId: 1, bubbles: true }));
        handle().dispatchEvent(new PointerEvent('pointerup', { pointerId: 1, bubbles: true }));
        await flushPromises();

        expect(content().style.transform).toBe('');
        expect(wrapper.emitted('update:open')).toBeUndefined();

        wrapper.unmount();
    });
});
