<script setup lang="ts">
import { useDragScroll } from '@/composables/useDragScroll';
import { trans } from 'laravel-vue-i18n';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * A row of tiles that scrolls sideways instead of wrapping into more rows.
 *
 * The arrows stand in the layout, one at each end of the row, and the tiles
 * give up the width they take. They are disabled rather than removed at the
 * ends of the row: pulled out of the flow they would hand their width back
 * and resize every tile mid-scroll.
 *
 * Below `sm` they are gone — a thumb pans the row itself — and the row bleeds
 * to the screen edges instead, where a tile cut off by the viewport has to
 * read as "there is more" rather than as a broken margin. The tiles size
 * themselves (basis, snap-start); the row only moves them.
 */
const row = ref<HTMLElement | null>(null);
const { canScrollLeft, canScrollRight, dragging, snapOff, readScroll, scrollByTile, startDrag, moveDrag, guardClick } = useDragScroll(row);
</script>

<template>
    <div class="flex items-center gap-3">
        <button
            type="button"
            :aria-label="trans('common.previous')"
            :disabled="!canScrollLeft"
            class="border-line-subtle bg-surface text-ink-muted enabled:hover:border-brand enabled:hover:text-ink focus-visible:ring-brand focus-visible:ring-offset-surface-base hidden size-9 shrink-0 place-items-center rounded-full border transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-35 sm:grid"
            @click="scrollByTile(-1)"
        >
            <ChevronLeft class="size-5" :stroke-width="2.5" />
        </button>

        <div
            ref="row"
            class="scroll-none -mx-4 flex min-w-0 flex-1 snap-x snap-mandatory scroll-pl-4 gap-4 overflow-x-auto overscroll-x-contain px-4 sm:mx-0 sm:scroll-pl-0 sm:px-0"
            :class="dragging ? 'cursor-grabbing select-none *:cursor-grabbing' : ''"
            :style="snapOff ? { scrollSnapType: 'none' } : undefined"
            @scroll.passive="readScroll"
            @pointerdown="startDrag"
            @pointermove="moveDrag"
            @click.capture="guardClick"
            @dragstart.prevent
        >
            <slot />
        </div>

        <!-- Touch scrolls the row by hand, so the arrows are a
             pointer affordance only and stay out of the way below
             `md`, where the row bleeds to the screen edges. -->
        <button
            type="button"
            :aria-label="trans('common.next')"
            :disabled="!canScrollRight"
            class="border-line-subtle bg-surface text-ink-muted enabled:hover:border-brand enabled:hover:text-ink focus-visible:ring-brand focus-visible:ring-offset-surface-base hidden size-9 shrink-0 place-items-center rounded-full border transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-35 sm:grid"
            @click="scrollByTile(1)"
        >
            <ChevronRight class="size-5" :stroke-width="2.5" />
        </button>
    </div>
</template>
