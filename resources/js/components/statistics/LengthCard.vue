<script setup lang="ts">
import AmountTerms from '@/components/AmountTerms.vue';
import PosterThumb from '@/components/statistics/PosterThumb.vue';
import ChartCard from '@/components/statistics/ChartCard.vue';
import TypeYear from '@/components/statistics/TypeYear.vue';
import { durationTerms, formatRuntime } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { mediaUrl } from '@/lib/urls';
import type { WholeLength } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/** How long the films of the period were: the longest one and the average. */
const props = defineProps<{ length: WholeLength; period: string }>();

const meta = computed(() => mediaTypeMeta(props.length.type));

const subtitle = computed(() =>
    trans('statistics.length_subtitle', {
        period: props.period,
        titles: meta.value.count(props.length.titles),
        time: formatRuntime(props.length.total) ?? '',
    }),
);
</script>

<template>
    <ChartCard v-if="meta.length" :title="meta.length.title()" :subtitle="subtitle">
        <Link
            :href="mediaUrl(length.longest)"
            class="group focus-visible:ring-brand flex items-center gap-4 rounded-lg focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <PosterThumb :path="length.longest.posterPath" size="w185" :width="96" :height="144" class="h-36 w-24 rounded-lg" />
            <span class="flex min-w-0 flex-col gap-1">
                <span class="text-ink-muted text-sm">{{ meta.length.longest() }}</span>
                <span class="text-ink text-base font-semibold group-hover:underline">{{ length.longest.title }}</span>
                <TypeYear :type="length.type" :year="length.longest.year" />
                <AmountTerms :terms="durationTerms(length.longestRuntime)" class="text-ink text-lg" value-class="font-bold" />
            </span>
        </Link>
        <div class="border-line-subtle flex flex-col gap-1.5 border-t pt-4">
            <span class="text-ink-muted text-sm">{{ meta.length.average() }}</span>
            <AmountTerms :terms="durationTerms(length.average)" class="text-ink text-lg" value-class="font-bold" />
        </div>
    </ChartCard>
</template>
