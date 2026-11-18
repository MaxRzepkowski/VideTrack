<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
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
            <span class="flex flex-wrap items-center gap-x-2 text-sm">
                <span class="text-ink-secondary text-base font-semibold">{{ amount(title, readIn) }}</span>
                <template v-if="estimate(title, readIn)">
                    <Hairline class="mx-0" />
                    <span class="text-ink-muted">{{ estimate(title, readIn) }}</span>
                </template>
                <template v-if="title.parts !== null">
                    <Hairline class="mx-0" />
                    <span class="text-ink-muted">{{ mediaTypeMeta(leafType(title.media.type)).count(title.parts) }}</span>
                </template>
            </span>
        </template>
    </PosterRow>
</template>
