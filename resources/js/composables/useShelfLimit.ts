import { POSTER_GRID_STEPS } from '@/lib/ui';
import { computed, readonly, ref, type ComputedRef, type Ref } from 'vue';

// One listener per breakpoint for the whole app: a page can hold several
// shelves, and every one of them asks the same three questions.
const breakpoints = new Map<string, Readonly<Ref<boolean>>>();

function atLeast(minWidth: string): Readonly<Ref<boolean>> {
    let matches = breakpoints.get(minWidth);

    if (matches === undefined) {
        const list = window.matchMedia(`(min-width: ${minWidth})`);
        const state = ref(list.matches);

        list.addEventListener('change', (event) => (state.value = event.matches));
        matches = readonly(state);
        breakpoints.set(minWidth, matches);
    }

    return matches;
}

/**
 * How many cards fit in one row of a poster shelf right now — read from
 * `POSTER_GRID_STEPS` (`@/lib/ui`), the table `MediaGrid` draws its classes from.
 *
 * @param widest  `MediaGrid`'s `columns`: 4 is the grid beside a sidebar
 */
export function usePosterColumns(widest: 4 | 5 = 5): ComputedRef<number> {
    const steps = POSTER_GRID_STEPS[widest].map((step) => ({
        columns: step.columns,
        matches: step.minWidth === null ? null : atLeast(step.minWidth),
    }));

    return computed(() =>
        steps.reduce((columns, step) => (step.matches === null || step.matches.value ? step.columns : columns), POSTER_GRID_STEPS[widest][0].columns),
    );
}

/**
 * A shelf's fold, rounded up to a whole row.
 *
 * A fixed cap tears: twelve cards in a five-column grid leave two rows and a
 * stub with three empty cells beside it, and the gap reads as a rendering bug
 * rather than a fold. Rounding the cap up to fill its last row means the fold
 * is always a straight edge, at every breakpoint.
 *
 * @param base  the fold the page wants, in cards
 */
export function useShelfLimit(base: number): ComputedRef<number> {
    const columns = usePosterColumns();

    return computed(() => Math.ceil(base / columns.value) * columns.value);
}

/**
 * A shelf with no "show more" behind it (related, similar): the head of the
 * list up to `base` cards, rounded up to a whole row like `useShelfLimit` —
 * up, so a breakpoint never hides a title the base count includes. Only a list
 * shorter than the rounded fold ends on a partial row.
 */
export function usePosterShelf(base: number): <T>(items: T[]) => T[] {
    const limit = useShelfLimit(base);

    return (items) => items.slice(0, limit.value);
}

/**
 * The head of a list that fills whole rows — for an opened shelf whose provider
 * still holds more.
 *
 * Opening a shelf shows everything the page has, which almost never divides by
 * the column count, so the same ragged edge that the fold avoids would reappear
 * above the button. The one to four cards that cannot fill a row wait here and
 * come back with the next chunk. A shelf too short for a single row keeps all
 * of it — hiding the lot would be worse than a short row.
 */
export function wholeRows<T>(items: T[], columns: number): T[] {
    const whole = Math.floor(items.length / columns) * columns;

    return whole === 0 ? items : items.slice(0, whole);
}
