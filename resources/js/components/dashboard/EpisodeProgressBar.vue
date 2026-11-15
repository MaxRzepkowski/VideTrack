<script setup lang="ts">
import type { EpisodeSegment } from '@/types/media';
import { computed } from 'vue';

const props = defineProps<{ segments: EpisodeSegment[] }>();

const total = computed(() => props.segments.reduce((sum, segment) => sum + segment.episodes, 0));

/** Pending stays uncolored and lets the track show through as the hole. */
const color = (state: EpisodeSegment['state']): string =>
    ({
        watched: 'bg-brand',
        // Settled without being seen: on the bar, visibly not counted.
        skipped: 'bg-line-strong',
        pending: '',
    })[state];

/**
 * A run is sized by its share of the aired episodes, so an unbroken watch
 * draws one smooth bar and only real holes break it. One episode skipped out
 * of 1213 is 0.08% — under a tenth of a pixel — so every run also claims a
 * 2px floor and the neighbours shrink to pay for it: a hole never rounds
 * itself away. The floor is capped at an equal share of the bar, which keeps
 * the runs inside it however many of them there are.
 */
const styleFor = (segment: EpisodeSegment) => ({
    flexBasis: `${(segment.episodes / total.value) * 100}%`,
    minWidth: `min(2px, calc(100% / ${props.segments.length}))`,
});
</script>

<template>
    <div class="bg-line flex h-1.5 w-full overflow-hidden rounded-full">
        <div v-for="(segment, index) in segments" :key="index" :class="color(segment.state)" :style="styleFor(segment)"></div>
    </div>
</template>
