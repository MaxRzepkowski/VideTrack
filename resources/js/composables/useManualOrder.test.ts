import { useManualOrder } from '@/composables/useManualOrder';
import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

function arrange(ids: number[], pinned: number[] = []) {
    const server = ref(ids);
    const saves: number[][] = [];
    let fail: (() => void) | null = null;

    const order = useManualOrder(
        () => server.value,
        (next, onError) => {
            saves.push(next);
            fail = onError;
        },
        (id) => pinned.includes(id),
    );

    return { order, server, saves, fail: () => fail?.() };
}

const drag = () => new Event('dragstart') as DragEvent;

describe('useManualOrder', () => {
    it('steps an entry, and does nothing past either end', () => {
        const { order, saves } = arrange([1, 2, 3]);

        order.move(1, 1);
        order.move(1, 5);

        expect(order.orderedIds.value).toEqual([2, 1, 3]);
        expect(saves).toEqual([[2, 1, 3]]);
    });

    it('jumps an entry to either end', () => {
        const { order } = arrange([1, 2, 3]);

        order.moveToEdge(3, 'top');
        expect(order.orderedIds.value).toEqual([3, 1, 2]);

        order.moveToEdge(3, 'bottom');
        expect(order.orderedIds.value).toEqual([1, 2, 3]);
    });

    it('drops a dragged entry where the target was', () => {
        const { order } = arrange([1, 2, 3, 4]);

        order.startDrag(drag(), 4);
        order.dropOn(2);
        order.endDrag();

        expect(order.orderedIds.value).toEqual([1, 4, 2, 3]);
        expect(order.dragId.value).toBeNull();
    });

    it('keeps a pinned entry in its slot and never lets it be dragged', () => {
        const { order, saves } = arrange([9, 1, 2], [9]);
        const event = drag();
        const prevent = vi.spyOn(event, 'preventDefault');

        order.moveToEdge(2, 'top');
        expect(order.orderedIds.value).toEqual([9, 2, 1]);

        order.startDrag(event, 9);
        expect(prevent).toHaveBeenCalled();

        order.startDrag(drag(), 1);
        order.dropOn(9);
        expect(saves).toHaveLength(1);
    });

    it('goes back when the save fails, and follows the server when it answers', async () => {
        const { order, server, fail } = arrange([1, 2]);

        order.move(2, -1);
        fail();
        expect(order.orderedIds.value).toEqual([1, 2]);

        server.value = [2, 1];
        await nextTick();
        expect(order.orderedIds.value).toEqual([2, 1]);
    });

    it("goes back to the server's order, not to a move the server may never have had", () => {
        const { order, fail } = arrange([1, 2, 3]);

        order.move(3, -1);
        order.move(3, -1);
        fail();

        expect(order.orderedIds.value).toEqual([1, 2, 3]);
    });
});
