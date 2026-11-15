<script setup lang="ts">
import { SELECT_CLASS_ON_CARD } from '@/lib/ui';
import { trans } from 'laravel-vue-i18n';

// How a rating section draws: a heatmap of tiles or a chart of the trajectory.
defineProps<{
    /** The section it switches, naming the control. */
    label: string;
}>();

const mode = defineModel<'heatmap' | 'chart'>({ required: true });
</script>

<template>
    <select
        :value="mode"
        :class="SELECT_CLASS_ON_CARD"
        :aria-label="label"
        @change="mode = ($event.target as HTMLSelectElement).value === 'chart' ? 'chart' : 'heatmap'"
    >
        <option value="heatmap">{{ trans('media.heatmap') }}</option>
        <option value="chart">{{ trans('media.chart') }}</option>
    </select>
</template>
