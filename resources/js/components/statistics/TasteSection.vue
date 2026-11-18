<script setup lang="ts">
import ChartCard from '@/components/statistics/ChartCard.vue';
import GenresCard from '@/components/statistics/GenresCard.vue';
import LengthCard from '@/components/statistics/LengthCard.vue';
import MakersCard from '@/components/statistics/MakersCard.vue';
import OriginsCard from '@/components/statistics/OriginsCard.vue';
import ReleaseChart from '@/components/statistics/ReleaseChart.vue';
import StatisticsSection from '@/components/statistics/StatisticsSection.vue';
import TopTitles from '@/components/statistics/TopTitles.vue';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { asReadUnit } from '@/lib/progress';
import { periodTitle, scopeName, typeNoun } from '@/lib/statistics';
import type { PeriodSpan, StatisticsOrigins, StatisticsTaste } from '@/types/generated';
import { Shapes } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * What the period's titles were: where the time went, the years they came
 * out in, their genres, how long the films were and where they come from.
 */
const props = defineProps<{
    taste: StatisticsTaste;
    origins: StatisticsOrigins;
    period: PeriodSpan;
    /** The chosen type; null for every type. */
    type: string | null;
}>();

const periodName = computed(() => periodTitle(props.period));
const readIn = computed(() => asReadUnit(props.taste.readIn));
const typePlural = computed(() => (props.type === null ? null : mediaTypeMeta(props.type).plural()));
const scope = computed(() => scopeName(props.type));

const topTitle = computed(() => {
    if (readIn.value !== null) {
        return trans('statistics.top_read_title', { type: scope.value });
    }

    return typePlural.value === null ? trans('statistics.top_time_title') : trans('statistics.top_time_title_of_type', { type: typePlural.value });
});

const topSubtitle = computed(() =>
    trans(readIn.value === null ? 'statistics.top_time_subtitle' : 'statistics.top_read_subtitle', {
        period: periodName.value,
        scope: scope.value,
    }),
);

const releasesTitle = computed(() => trans('statistics.releases_title', { type: typeNoun(props.type) }));
const releasesSubtitle = computed(() =>
    trans(props.type === null ? 'statistics.releases_by_type_subtitle' : 'statistics.releases_by_rating_subtitle', { period: periodName.value }),
);

const genresSubtitle = computed(() => trans('statistics.genres_subtitle', { period: periodName.value, scope: scope.value }));
const hasLengths = computed(() => props.taste.lengths.length > 0);
const originsSubtitle = computed(() => trans('statistics.origins_subtitle', { period: periodName.value, scope: scope.value }));
</script>

<template>
    <StatisticsSection id="taste" :icon="Shapes" :title="trans('statistics.taste_title')" :subtitle="trans('statistics.taste_subtitle')">
        <ChartCard v-if="taste.top.length > 0" :title="topTitle" :subtitle="topSubtitle">
            <TopTitles :titles="taste.top" :read-in="readIn" />
            <p v-if="taste.readingEstimated" class="text-ink-faint m-0 text-sm">{{ trans('statistics.reading_estimated_note') }}</p>
        </ChartCard>

        <ChartCard v-if="taste.releases.length > 0" :title="releasesTitle" :subtitle="releasesSubtitle">
            <ReleaseChart :dots="taste.releases" :type="type" />
        </ChartCard>

        <div
            v-if="taste.genres.length > 0 || hasLengths"
            class="grid grid-cols-1 gap-6"
            :class="hasLengths && taste.genres.length > 0 ? 'lg:grid-cols-[minmax(0,7fr)_minmax(0,6fr)]' : ''"
        >
            <GenresCard
                v-if="taste.genres.length > 0"
                :genres="taste.genres"
                :read-in="readIn"
                :type="type"
                :subtitle="genresSubtitle"
                :reading-estimated="taste.readingEstimated"
            />
            <div v-if="hasLengths" class="flex min-w-0 flex-col gap-6">
                <LengthCard v-for="length in taste.lengths" :key="length.type" :length="length" :period="periodName" />
            </div>
        </div>

        <OriginsCard
            v-if="origins.countries.length > 0 || origins.languages.length > 0"
            :origins="origins"
            :type="type"
            :subtitle="originsSubtitle"
        />

        <MakersCard
            v-if="origins.makerKind !== null"
            :makers="origins.makers"
            :titles="origins.titles"
            :kind="origins.makerKind"
            :type="type"
            :period="periodName"
            :scope="scope"
        />
    </StatisticsSection>
</template>
