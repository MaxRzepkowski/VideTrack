<script setup lang="ts">
import PosterRow from '@/components/statistics/PosterRow.vue';
import { formatRuntime } from '@/lib/format';
import { leafType, mediaTypeMeta } from '@/lib/mediaTypes';
import { formatUnitAmount, type ReadUnit } from '@/lib/progress';
import type { TimeTitle } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

/**
 * The titles the most time went to, as a row of posters: each with its time
 * (or what was read of it) and, for a show, how many of its episodes.
 */
defineProps<{
    titles: TimeTitle[];
    /** The unit the amounts are read in, for one type that is read; null when they are time. */
    readIn: ReadUnit | null;
}>();

/**
 * What the title took: its time, or what was read of it. Reading counted as
 * time is an estimate, and says so; read in its own unit, the estimate
 * follows it.
 */
const amount = (title: TimeTitle, readIn: ReadUnit | null): string => {
    if (readIn !== null) {
        return formatUnitAmount(readIn, title.amount);
    }

    const time = formatRuntime(title.amount) ?? '';

    return title.estimatedMinutes === null ? time : trans('statistics.estimated_value', { value: time });
};

const estimate = (title: TimeTitle, readIn: ReadUnit | null): string | null =>
    readIn === null || title.estimatedMinutes === null || title.estimatedMinutes < 1
        ? null
        : trans('statistics.estimated_value', { value: formatRuntime(title.estimatedMinutes) ?? '' });
</script>

<template>
    <PosterRow :titles="titles">
        <template #detail="{ title }">
            <!-- A line each: a poster's column is too narrow to set them apart with a rule. -->
            <span class="flex flex-col gap-0.5 text-sm">
                <span class="text-ink-secondary text-base font-semibold">{{ amount(title, readIn) }}</span>
                <span v-if="estimate(title, readIn)" class="text-ink-muted">{{ estimate(title, readIn) }}</span>
                <span v-if="title.parts !== null" class="text-ink-muted">{{ mediaTypeMeta(leafType(title.media.type)).count(title.parts) }}</span>
            </span>
        </template>
    </PosterRow>
</template>
