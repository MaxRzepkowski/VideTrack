<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

// The user rating as a progress ring in the poster's bottom-right: a dark
// disc, the arc fills proportionally to the 1–10 rating in the rating
// yellow, the number stays white. A perfect 10 goes gold: gradient ring
// with a pulsing glow. Parent must be positioned.
//
// `positioned` (default) pins it to the poster's bottom-right; set it false
// to drop it inline anywhere the caller lays it out itself.
const props = withDefaults(
    defineProps<{
        rating: number;
        positioned?: boolean;
        /** Outer diameter in px; the ring and number scale with it. */
        size?: number;
    }>(),
    { positioned: true, size: 40 },
);

const RADIUS = 17;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

// On appear (or when the rating changes) the number counts up and the ring
// fills to the value — easeOutCubic over ~550ms — instead of snapping there.
const displayed = ref(0);
let raf = 0;

function tweenTo(target: number): void {
    cancelAnimationFrame(raf);

    // A reader who asked for less motion gets the value at once.
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        displayed.value = target;

        return;
    }

    const start = displayed.value;
    const startTime = performance.now();
    const duration = 550;

    const frame = (now: number): void => {
        const t = Math.min(1, (now - startTime) / duration);
        displayed.value = start + (target - start) * (1 - Math.pow(1 - t, 3));

        if (t < 1) {
            raf = requestAnimationFrame(frame);
        } else {
            displayed.value = target;
        }
    };

    raf = requestAnimationFrame(frame);
}

onMounted(() => tweenTo(props.rating));
watch(
    () => props.rating,
    (value) => tweenTo(value),
);
onBeforeUnmount(() => cancelAnimationFrame(raf));

const dashOffset = computed(() => CIRCUMFERENCE * (1 - Math.min(10, Math.max(0, displayed.value)) / 10));
const shownNumber = computed(() => Math.round(displayed.value));
const perfect = computed(() => props.rating >= 10);

// A per-instance gradient id: the badge renders more than once per page
// (the mobile `lg:hidden` action panel duplicates it), and a shared id would
// make `url(#…)` resolve to the first match — which lives in a display:none
// subtree — so the gold arc paints as nothing. Unique ids keep each ring
// referencing its own, in-tree gradient.
const goldGradientId = `vt-rating-gold-${useId()}`.replace(/[^a-zA-Z0-9-]/g, '');
const boxStyle = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }));
const numberStyle = computed(() => ({ fontSize: `${props.size * 0.35}px` }));
</script>

<template>
    <div
        class="bg-surface-base/90 shadow-card flex items-center justify-center rounded-full"
        :class="[{ 'rating-ring-glow': perfect }, positioned ? 'absolute right-3 bottom-3' : 'relative']"
        :style="boxStyle"
    >
        <svg class="absolute inset-0 -rotate-90" viewBox="0 0 40 40">
            <defs>
                <linearGradient :id="goldGradientId" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="var(--color-rating-gold-light)" />
                    <stop offset="50%" stop-color="var(--color-rating-gold)" />
                    <stop offset="100%" stop-color="var(--color-rating-gold-light)" />
                </linearGradient>
            </defs>
            <circle
                cx="20"
                cy="20"
                :r="RADIUS"
                fill="none"
                stroke="color-mix(in oklab, var(--color-action-rating) 25%, transparent)"
                stroke-width="3"
            />
            <circle
                cx="20"
                cy="20"
                :r="RADIUS"
                fill="none"
                :class="perfect ? '' : 'stroke-action-rating'"
                :stroke="perfect ? `url(#${goldGradientId})` : undefined"
                stroke-width="3"
                stroke-linecap="round"
                :stroke-dasharray="CIRCUMFERENCE"
                :stroke-dashoffset="dashOffset"
            />
        </svg>
        <span class="text-on-photo leading-none font-extrabold tabular-nums" :style="numberStyle">{{ shownNumber }}</span>
    </div>
</template>

<style scoped>
@keyframes rating-ring-glow {
    0%,
    100% {
        filter: drop-shadow(0 0 1.5px color-mix(in oklab, var(--color-rating-glow) 55%, transparent))
            drop-shadow(0 0 4px color-mix(in oklab, var(--color-rating-glow) 35%, transparent));
    }
    50% {
        filter: drop-shadow(0 0 2.5px color-mix(in oklab, var(--color-rating-glow) 85%, transparent))
            drop-shadow(0 0 8px color-mix(in oklab, var(--color-rating-glow) 55%, transparent));
    }
}

@media (prefers-reduced-motion: no-preference) {
    .rating-ring-glow {
        animation: rating-ring-glow 2.2s ease-in-out infinite;
    }
}
</style>
