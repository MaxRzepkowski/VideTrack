<script setup lang="ts">
import { TOOLTIP_ALIGN_CLASS, type TooltipAnchor } from '@/lib/ratingChart';
import { Star } from '@lucide/vue';

// A rating chart's tooltip: the value leads, the identity (the slot) follows.
defineProps<{
    anchor: TooltipAnchor;
    /** Null for an unrated mark: the star reads "–". */
    rating: number | null;
}>();
</script>

<template>
    <div
        class="border-line bg-surface-base/95 max-w-narrow pointer-events-none absolute z-10 w-max rounded-lg border px-3 py-2 shadow-lg"
        :class="[anchor.below ? '' : '-translate-y-full', TOOLTIP_ALIGN_CLASS[anchor.align]]"
        :style="{ left: `${anchor.x}px`, top: `${anchor.y}px` }"
    >
        <div class="text-ink flex items-center gap-2 text-xl font-semibold">
            <Star class="fill-action-rating text-action-rating size-4.75 shrink-0" :stroke-width="1" />
            {{ rating !== null ? rating.toFixed(1) : '–' }}
        </div>
        <slot />
    </div>
</template>
