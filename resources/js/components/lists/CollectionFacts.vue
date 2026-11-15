<script setup lang="ts">
import DetailsModal from '@/components/media/DetailsModal.vue';
import { countryName, formatMoney, formatNumber, formatTotalRuntime } from '@/lib/format';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import type { CollectionOverview } from '@/types/generated';
import type { DetailRow } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// The "i" beside a collection's title: the saga as a whole, the same window a
// title's page opens, with the refresh at its foot.
const props = defineProps<{
    overview: CollectionOverview;
    collection: { provider: string; ref: string };
    /** The type the parts are, which their keywords browse on Discover. */
    partType: string | null;
}>();

// A sum over some of the movies must not pass for the whole saga's.
function amount(sum: number | null, counted: number): string | null {
    const formatted = formatMoney(sum);

    if (formatted === null || counted === props.overview.titleCount) {
        return formatted;
    }

    return trans('lists.amount_from_movies', { amount: formatted, counted: String(counted), total: String(props.overview.titleCount) });
}

const partCount = computed(() => {
    const { titleCount, releasedCount, upcomingCount } = props.overview;

    if (upcomingCount === 0) {
        return formatNumber(titleCount);
    }

    return trans('lists.released_and_announced', { released: formatNumber(releasedCount), announced: formatNumber(upcomingCount) });
});

// Named after what the parts are: a saga counts movies, a series games.
const countHeading = computed(
    () => (props.partType ? MEDIA_TYPE_META[props.partType]?.countHeading?.() : undefined) ?? trans('lists.number_of_titles'),
);

const rows = computed<DetailRow[]>(() => [
    { label: countHeading.value, value: props.overview.titleCount > 0 ? partCount.value : null },
    { label: trans('lists.total_budget'), value: amount(props.overview.budget, props.overview.budgetCounted) },
    { label: trans('lists.total_revenue'), value: amount(props.overview.revenue, props.overview.revenueCounted) },
    { label: trans('common.total_runtime'), value: formatTotalRuntime(props.overview.totalRuntime) },
    { label: trans('media.production_countries'), value: props.overview.productionCountries.map(countryName).join(', ') || null },
    { label: trans('common.original_language'), value: props.overview.originalLanguages.map((code) => code.toUpperCase()).join(', ') || null },
    { label: trans('common.certification'), value: props.overview.certifications.join(', ') || null },
]);
</script>

<template>
    <DetailsModal
        :title="trans('media.details')"
        :rows="rows"
        :studios="overview.studios"
        :keywords="overview.keywords"
        :keyword-type="partType ?? undefined"
        :refresh="{ url: route('collections.refresh', { provider: collection.provider, ref: collection.ref }) }"
    />
</template>
