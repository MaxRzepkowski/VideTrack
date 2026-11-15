import { ref, watch, type Ref } from 'vue';

export interface ManualOrder {
    /** The order on screen: the server's, or a move that is on its way there. */
    orderedIds: Ref<number[]>;
    /** The card being dragged, for its "lifted" look. */
    dragId: Ref<number | null>;
    startDrag(event: DragEvent, id: number): void;
    endDrag(): void;
    dropOn(targetId: number): void;
    move(id: number, delta: number): void;
    moveToEdge(id: number, edge: 'top' | 'bottom'): void;
}

/**
 * A hand-arranged order — drag & drop, plus the step and jump buttons for
 * touch and keyboard, where drag & drop does not fire. Pinned entries keep
 * their slots: every move runs on the movable ones alone and is spliced back
 * around them, so "top" means the top of what the user may arrange.
 *
 * A move shows at once and is then saved; `save` reports a failure through
 * `onError`, and the order on screen goes back to the server's. Not to the one
 * before this move: with two quick moves, the first may never have got there.
 */
export function useManualOrder(
    ids: () => number[],
    save: (ids: number[], onError: () => void) => void,
    isPinned: (id: number) => boolean = () => false,
): ManualOrder {
    const orderedIds = ref<number[]>(ids());
    const dragId = ref<number | null>(null);

    // The server's order, once it lands, is the truth again.
    watch(ids, (next) => (orderedIds.value = next));

    function reorder(mutate: (movable: number[]) => boolean): void {
        const movable = orderedIds.value.filter((id) => !isPinned(id));

        if (!mutate(movable)) {
            return;
        }

        // `movable` holds exactly the unpinned ids, so it never runs dry here.
        orderedIds.value = orderedIds.value.map((id) => (isPinned(id) ? id : (movable.shift() ?? id)));
        save(orderedIds.value, () => (orderedIds.value = ids()));
    }

    function startDrag(event: DragEvent, id: number): void {
        // `draggable=false` only stops a card being a drag source; the browser
        // then selects it instead, and drags that selection on the next grab.
        // Cancelling the drag leaves nothing to fall back to.
        if (isPinned(id)) {
            event.preventDefault();

            return;
        }

        dragId.value = id;
    }

    function dropOn(targetId: number): void {
        const dragged = dragId.value;

        if (dragged === null || dragged === targetId || isPinned(dragged) || isPinned(targetId)) {
            return;
        }

        reorder((movable) => {
            const from = movable.indexOf(dragged);
            const to = movable.indexOf(targetId);

            if (from === -1 || to === -1) {
                return false;
            }

            movable.splice(to, 0, ...movable.splice(from, 1));

            return true;
        });
    }

    function move(id: number, delta: number): void {
        reorder((movable) => {
            const from = movable.indexOf(id);
            const to = from + delta;

            if (from === -1 || to < 0 || to >= movable.length) {
                return false;
            }

            movable.splice(to, 0, ...movable.splice(from, 1));

            return true;
        });
    }

    // Stepping an entry to the far end of a long list one place at a time
    // (and saving each step) is not workable.
    function moveToEdge(id: number, edge: 'top' | 'bottom'): void {
        reorder((movable) => {
            const from = movable.indexOf(id);

            if (from === -1) {
                return false;
            }

            const moved = movable.splice(from, 1);

            if (edge === 'top') {
                movable.unshift(...moved);
            } else {
                movable.push(...moved);
            }

            return true;
        });
    }

    return { orderedIds, dragId, startDrag, endDrag: () => (dragId.value = null), dropOn, move, moveToEdge };
}
