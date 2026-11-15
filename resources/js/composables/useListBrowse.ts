import { matchesListFilters, sortListItems, type ListSort } from '@/lib/lists';
import { inRegistryOrder } from '@/lib/mediaTypes';
import type { UserMediaState } from '@/types/generated';
import type { ListItemEntry } from '@/types/media';
import { computed, ref, watch, type Ref } from 'vue';

/**
 * Big lists (imported franchises, the backlog) can run to thousands of
 * positions; rendering them all at once is the jank. Sorting, filtering and
 * reordering run over the full set — only the rendered slice is paged.
 */
const PER_PAGE = 50;

/**
 * How a list page browses its positions, all in the browser: the page holds
 * every position already, so no control needs a visit. Search and the panel's
 * filters (type, genre, the date added, hide rated, hide completed), the sort,
 * and the page of the result. Edit mode shows the whole list in its manual
 * order — reordering needs every position in view.
 */
export function useListBrowse(
    items: () => ListItemEntry[],
    orderedIds: Ref<number[]>,
    editing: Ref<boolean>,
    stateOf: (item: ListItemEntry) => UserMediaState,
) {
    const sort = ref<ListSort>('order');
    const sortDir = ref<'asc' | 'desc'>('asc');

    // Each sort has a natural direction (A–Z and list order ascending, the
    // rest newest or highest first); switching resets to it.
    watch(sort, () => {
        sortDir.value = sort.value === 'az' || sort.value === 'order' ? 'asc' : 'desc';
    });

    watch(editing, (on) => {
        if (on) {
            sort.value = 'order';
            sortDir.value = 'asc';
        }
    });

    const searchQuery = ref<string | null>(null);
    const typeFilter = ref<string | null>(null);
    const genreFilter = ref<string | null>(null);
    const fromDate = ref<string | null>(null);
    const toDate = ref<string | null>(null);
    const hideRated = ref(false);
    const hideCompleted = ref(false);

    const typeOptions = computed(() => inRegistryOrder(new Set(items().map((item) => item.media.type))).map((type) => ({ type })));

    // Genres present on this list, not across the account: the options
    // describe the set in front of you.
    const genreOptions = computed(() => [...new Set(items().flatMap((item) => item.genres))].sort((a, b) => a.localeCompare(b)));

    const activeFilterCount = computed(
        () =>
            [typeFilter.value, genreFilter.value, fromDate.value, toDate.value].filter((value) => value !== null && value !== '').length +
            (hideRated.value ? 1 : 0) +
            (hideCompleted.value ? 1 : 0),
    );

    // The panel's own reset: the search box is a separate control and survives it.
    function clearFilters(): void {
        typeFilter.value = null;
        genreFilter.value = null;
        fromDate.value = null;
        toDate.value = null;
        hideRated.value = false;
        hideCompleted.value = false;
    }

    const displayed = computed<ListItemEntry[]>(() => {
        const byId = new Map(items().map((item) => [item.id, item]));
        let ordered = orderedIds.value.map((id) => byId.get(id)).filter((item): item is ListItemEntry => item !== undefined);

        if (!editing.value) {
            const filters = {
                search: searchQuery.value,
                type: typeFilter.value,
                genre: genreFilter.value,
                from: fromDate.value,
                to: toDate.value,
                hideRated: hideRated.value,
                hideCompleted: hideCompleted.value,
            };

            ordered = ordered.filter((item) => matchesListFilters(item, filters, () => stateOf(item)));
        }

        return sortListItems(ordered, sort.value, sortDir.value, (item) => stateOf(item).rating ?? null);
    });

    const page = ref(1);
    const totalPages = computed(() => Math.max(1, Math.ceil(displayed.value.length / PER_PAGE)));
    const paged = computed<ListItemEntry[]>(() => displayed.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));

    // The visible set changed shape — back to page 1.
    watch([sort, sortDir, editing, searchQuery, typeFilter, genreFilter, fromDate, toDate, hideRated, hideCompleted], () => (page.value = 1));

    // Removing positions can shrink the list past the current page.
    watch(totalPages, (total) => {
        if (page.value > total) {
            page.value = total;
        }
    });

    return {
        sort,
        sortDir,
        searchQuery,
        typeFilter,
        genreFilter,
        fromDate,
        toDate,
        hideRated,
        hideCompleted,
        typeOptions,
        genreOptions,
        activeFilterCount,
        clearFilters,
        displayed,
        page,
        totalPages,
        paged,
        isFirst: (item: ListItemEntry): boolean => displayed.value[0]?.id === item.id,
        isLast: (item: ListItemEntry): boolean => displayed.value[displayed.value.length - 1]?.id === item.id,
    };
}
