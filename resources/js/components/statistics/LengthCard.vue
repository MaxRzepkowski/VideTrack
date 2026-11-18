<script setup lang="ts">
import ChartCard from '@/components/statistics/ChartCard.vue';
import { durationTerms, formatRuntime } from '@/lib/format';
import { mediaImage } from '@/lib/images';
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
            <img
                v-if="mediaImage(length.longest.posterPath, 'w185')"
                :src="mediaImage(length.longest.posterPath, 'w185') ?? undefined"
                alt=""
                width="96"
                height="144"
                loading="lazy"
                class="bg-surface-raised h-36 w-24 shrink-0 rounded-lg object-cover"
            />
            <span class="flex min-w-0 flex-col gap-1">
                <span class="text-ink-muted text-sm">{{ meta.length.longest() }}</span>
                <span class="text-ink text-base font-semibold group-hover:underline">{{ length.longest.title }}</span>
                <span class="text-ink-muted flex items-center gap-1.5 text-sm">
                    <component :is="meta.icon" class="size-4 shrink-0" :style="{ color: meta.color }" aria-hidden="true" />
                    {{
                        length.longest.year === null
                            ? meta.label()
                            : trans('statistics.type_and_year', { type: meta.label(), year: String(length.longest.year) })
                    }}
                </span>
                <span class="text-ink flex flex-wrap gap-x-2 text-lg">
                    <span v-for="term in durationTerms(length.longestRuntime)" :key="term.unit"
                        ><b>{{ term.value }}</b> {{ term.unit }}</span
                    >
                </span>
            </span>
        </Link>
        <div class="border-line-subtle flex flex-col gap-1.5 border-t pt-4">
            <span class="text-ink-muted text-sm">{{ meta.length.average() }}</span>
            <span class="text-ink flex flex-wrap gap-x-2 text-lg">
                <span v-for="term in durationTerms(length.average)" :key="term.unit"
                    ><b>{{ term.value }}</b> {{ term.unit }}</span
                >
            </span>
        </div>
    </ChartCard>
</template>
