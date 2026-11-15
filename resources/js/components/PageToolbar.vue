<script setup lang="ts">
import FiltersButton from '@/components/FiltersButton.vue';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * The control block every listing view wears, so nothing moves as the user
 * walks between them. Three rows, always in this order:
 *
 *   1. heading — the page's own title, with its primary action opposite
 *   2. controls — narrowing on the left (search, filters), presentation on the
 *      right (sort, view mode, then any per-view action)
 *   3. count — what the controls above left behind
 *
 * The filter panel belongs to its button, so this component owns both: the
 * fields inside differ per view (a countdown has no watch status, a history no
 * release window), the button, the badge and the panel do not. It opens between
 * the controls and the count, so the count reads as the result of both.
 *
 * `variant="band"` is the same block drawn as a strip that runs the full width
 * and sticks under the app header. Like the header it lets the page through —
 * its own colour at 90% over a blur, so results scrolling beneath read as
 * movement rather than as a second surface. There the heading has already been
 * said above it, the count joins the control row, and every control marked
 * `data-toolbar-control` lifts to `surface-control` so it reads against the
 * strip. It is the only tinted thing above the results, which is what makes it
 * read as the surface you act on. Render it OUTSIDE the page's container — it
 * brings its own — and directly after a `PageMasthead`.
 *
 * The mark wins over a class, so a control that lights up (a toggle going
 * brand) drops the attribute in that state rather than fighting it:
 * `:data-toolbar-control="on ? null : ''"`.
 *
 * Below `md` a toolbar that owns a filter panel keeps only the filter button
 * and sorting in its row; `search`, `view`, `actions` and the count render
 * inside the panel instead. Each slot is mounted in one place at a time, so a
 * control there may hold state. The count never rides in the narrow row — the
 * strip sticks under the header, and a row it wraps onto is a row of results
 * nobody can see.
 */
const props = withDefaults(
    defineProps<{
        /** The count line; omit where the view states it elsewhere. */
        count?: string | null;
        /** A full-width control strip used between a page heading and its results. */
        variant?: 'default' | 'band';
        /**
         * How many filter fields are set. `null` = this view has no panel —
         * pass it wherever the `filters` slot would render nothing, or the
         * button opens onto an empty panel.
         */
        filterCount?: number | null;
    }>(),
    { count: null, filterCount: null, variant: 'default' },
);

const emit = defineEmits<{ clearFilters: [] }>();

// Closed on arrival even when fields are set: picking one closes the panel, so
// re-opening it on the way back in would undo that on every view whose filters
// travel by a visit that remounts. The badge on the button says how many are
// on; the panel is for changing them, not for reporting them.
const filtersOpen = ref(false);

// The button is lit for both reasons a panel matters: it is open, or it is
// closed over fields that are set. A button that is lit in every state says
// nothing, so the band draws its resting state like the controls beside it.
const filtersActive = computed(() => filtersOpen.value || (props.filterCount ?? 0) > 0);

// On a phone the strip sticks under the header, so every row it wears is a row
// of results the user cannot see. Below `md` it keeps the two controls worth a
// permanent seat — the filter button on the left, sorting on the right — and
// everything else (search, view mode, per-view actions, the count) moves inside
// the panel the button opens. A view with no panel has nowhere to move them to,
// so it keeps the full row.
const narrow = useMediaQuery('(max-width: 47.999rem)');
const collapsed = computed(() => narrow.value && props.filterCount !== null);
</script>

<template>
    <div
        :class="
            variant === 'band'
                ? 'border-line-faint bg-surface-toolbar/90 **:data-toolbar-control:bg-surface-control sticky top-14 z-30 mb-8 border-y backdrop-blur-md md:top-18'
                : 'mb-8'
        "
    >
        <div :class="variant === 'band' ? 'max-w-content mx-auto w-full px-4 py-4 md:px-10' : ''">
            <div v-if="$slots.heading || $slots.primary" class="flex flex-wrap items-center justify-between gap-4">
                <slot name="heading" />
                <div v-if="$slots.primary" class="flex flex-wrap items-center gap-3"><slot name="primary" /></div>
            </div>

            <div class="flex flex-wrap justify-between gap-3" :class="variant === 'default' && 'mt-5'">
                <div class="flex flex-wrap items-center gap-3">
                    <slot v-if="!collapsed" name="search" />

                    <FiltersButton
                        v-if="filterCount !== null"
                        :count="filterCount"
                        :active="filtersActive"
                        :expanded="filtersOpen"
                        :variant="variant"
                        @click="filtersOpen = !filtersOpen"
                    />

                    <p
                        v-if="count && variant === 'band' && !narrow && !$slots.search && filterCount === null"
                        class="text-ink-faint m-0 text-base font-semibold"
                    >
                        {{ count }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <p
                        v-if="count && variant === 'band' && !narrow && ($slots.search || filterCount !== null)"
                        class="text-ink-faint m-0 text-base font-semibold"
                    >
                        {{ count }}
                    </p>
                    <slot name="sort" />
                    <slot v-if="!collapsed" name="view" />
                    <slot v-if="!collapsed" name="actions" />
                </div>
            </div>

            <Transition name="filter-panel">
                <!-- A field that has been set has had its say: `change` bubbles
                     from whatever the slot put here — a select, a date, a
                     checkbox — so the panel folds away and leaves the results
                     it just narrowed in view. -->
                <div
                    v-if="filterCount !== null && filtersOpen"
                    :class="
                        variant === 'band' ? 'border-line-faint mt-3 border-t pt-4 pb-1' : 'border-line-subtle bg-surface mt-4 rounded-xl border p-5'
                    "
                    @change="filtersOpen = false"
                >
                    <!-- What the narrow row sent here, above the fields it came
                         to join. Their `change` stops at this wrapper: a search
                         box that folded the panel away the moment it lost focus
                         would be unusable. -->
                    <div v-if="collapsed" class="mb-4 flex flex-col gap-3" @change.stop>
                        <slot name="search" />
                        <div v-if="$slots.view || $slots.actions" class="flex flex-wrap items-center gap-3">
                            <slot name="view" />
                            <slot name="actions" />
                        </div>
                        <p v-if="count" class="text-ink-faint m-0 text-base font-semibold">{{ count }}</p>
                    </div>

                    <div class="flex flex-wrap items-end gap-x-6 gap-y-4">
                        <slot name="filters" />

                        <button
                            v-if="filterCount > 0"
                            type="button"
                            class="text-brand-soft ml-auto pb-1 text-base font-semibold"
                            @click="emit('clearFilters')"
                        >
                            {{ trans('common.clear_filters') }}
                        </button>
                    </div>
                </div>
            </Transition>

            <p v-if="count && variant === 'default'" class="text-ink-faint mt-5 mb-0 text-base font-semibold">{{ count }}</p>
        </div>
    </div>
</template>

<style scoped>
.filter-panel-enter-active,
.filter-panel-leave-active {
    transition:
        opacity 200ms ease,
        transform 200ms ease;
}

.filter-panel-enter-from,
.filter-panel-leave-to {
    opacity: 0;
    transform: translateY(-0.5rem);
}
</style>
