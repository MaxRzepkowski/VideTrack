<script setup lang="ts">
import ChartCard from '@/components/statistics/ChartCard.vue';
import PosterRow from '@/components/statistics/PosterRow.vue';
import RatingGaps from '@/components/statistics/RatingGaps.vue';
import RatingScatter from '@/components/statistics/RatingScatter.vue';
import StatisticsSection from '@/components/statistics/StatisticsSection.vue';
import { formatNumber } from '@/lib/format';
import { RATING_MAX, RATING_MIN } from '@/lib/ratings';
import { communityComparison, countTitles, periodTitle, scopeName } from '@/lib/statistics';
import type { PeriodSpan, StatisticsRatings } from '@/types/generated';
import { Star } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * How the user rated the period's titles against everyone else: a dot per
 * title beside the titles rated furthest apart, then the best rated. The
 * spread of the ratings themselves is on the period card above.
 */
const props = defineProps<{
    ratings: StatisticsRatings;
    period: PeriodSpan;
    /** The chosen type; null for every type. */
    type: string | null;
    /** One type's view: how far the user's ratings sit from everyone else's, on average; null otherwise. */
    communityDelta: number | null;
}>();

const periodName = computed(() => periodTitle(props.period));
const scope = computed(() => scopeName(props.type));
const count = (titles: number): string => countTitles(props.type, titles);

const scatterSubtitle = computed(() => {
    const words = {
        period: periodName.value,
        titles: count(props.ratings.ratedHigher + props.ratings.ratedLower + props.ratings.ratedSame),
        higher: count(props.ratings.ratedHigher),
        lower: count(props.ratings.ratedLower),
    };

    return props.communityDelta === null
        ? trans('statistics.scatter_subtitle', words)
        : trans('statistics.scatter_subtitle_average', { ...words, average: communityComparison(props.communityDelta) });
});

const bestSubtitle = computed(() => {
    const top = props.ratings.best[0]?.rating ?? null;

    return top !== null && props.ratings.bestTied > props.ratings.best.length
        ? trans('statistics.best_rated_subtitle_tied', { period: periodName.value, titles: count(props.ratings.bestTied), rating: String(top) })
        : trans('statistics.best_rated_subtitle', { period: periodName.value, scope: scope.value });
});

const gapsSubtitle = computed(() =>
    trans('statistics.gaps_subtitle', { period: periodName.value, scope: scope.value, min: String(RATING_MIN), max: String(RATING_MAX) }),
);
</script>

<template>
    <StatisticsSection
        v-if="ratings.best.length > 0"
        id="ratings"
        :icon="Star"
        :title="trans('statistics.ratings_title')"
        :subtitle="trans('statistics.ratings_subtitle')"
    >
        <div v-if="ratings.points.length > 0" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
            <ChartCard :title="trans('statistics.scatter_title')" :subtitle="scatterSubtitle">
                <RatingScatter :points="ratings.points" :type="type" />
            </ChartCard>
            <ChartCard :title="trans('statistics.gaps_title')" :subtitle="gapsSubtitle">
                <RatingGaps :heading="trans('statistics.gaps_higher')" :none="trans('statistics.gaps_none_higher')" :titles="ratings.higher" />
                <RatingGaps :heading="trans('statistics.gaps_lower')" :none="trans('statistics.gaps_none_lower')" :titles="ratings.lower" />
            </ChartCard>
        </div>

        <ChartCard :title="trans('statistics.best_rated_title')" :subtitle="bestSubtitle">
            <PosterRow :titles="ratings.best">
                <template #detail="{ title }">
                    <span v-if="title.rating !== null" class="text-ink-secondary flex items-center gap-1.5 text-base font-semibold">
                        <Star class="text-rating-gold fill-rating-gold size-4 shrink-0" aria-hidden="true" />
                        <span class="sr-only">{{ trans('statistics.rating_yours') }}</span>
                        {{ formatNumber(title.rating) }}
                    </span>
                </template>
            </PosterRow>
        </ChartCard>
    </StatisticsSection>
</template>
