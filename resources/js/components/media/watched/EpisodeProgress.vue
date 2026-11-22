<script setup lang="ts">
import { episodeShare } from '@/lib/progress';
import { STATUS_VISUALS } from '@/lib/status';
import { transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * How far a show, a season or one run of a show got: episodes watched of those
 * out, as a count and a bar in the colour of where it stands.
 */
const props = withDefaults(defineProps<{ watched: number; aired: number; color?: string }>(), { color: STATUS_VISUALS.inProgress.color });

const percent = computed(() => episodeShare(props.watched, props.aired));
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-3 text-sm">
            <span class="text-ink-secondary tabular-nums">{{
                transChoice('media.watched_of_aired_episodes', aired, { watched: String(watched), aired: String(aired) })
            }}</span>
            <span class="text-ink-muted tabular-nums">{{ percent }}%</span>
        </div>
        <div class="bg-surface-overlay h-1.5 overflow-hidden rounded-full">
            <div class="h-full rounded-full transition-[width] duration-300" :style="{ width: `${percent}%`, background: color }" />
        </div>
    </div>
</template>
