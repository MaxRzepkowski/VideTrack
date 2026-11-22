<script setup lang="ts">
import CastSection from '@/components/media/CastSection.vue';
import CollectionBanner from '@/components/media/CollectionBanner.vue';
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import PhotosSection from '@/components/media/PhotosSection.vue';
import RecentlyViewed from '@/components/media/RecentlyViewed.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import ReleasesSection from '@/components/media/ReleasesSection.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import VideosSection from '@/components/media/VideosSection.vue';
import WatchProvidersSection from '@/components/media/WatchProvidersSection.vue';
import { isDirector, keyCrew, wroteScreenplay } from '@/lib/credits';
import { alternativeTitlesLine, countryName, formatCalendarDate, formatMoney, formatRuntime } from '@/lib/format';
import { titleStatusLabel } from '@/lib/titleStatus';
import type { MovieExtra } from '@/types/extras';
import type { Chip, CollectionBannerData, DetailRow, MediaDetails, MediaSummary, TitleTie, WatchAvailability } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    movie: MediaDetails<MovieExtra>;
    collection: CollectionBannerData | null;
    watchProviders: WatchAvailability | null;
    /** Countries with a JustWatch offer, worldwide — a dateless second source. */
    offerRegions?: string[];
    relatedTitles: TitleTie[];
    otherMedia: TitleTie[];
    recentlyViewed?: MediaSummary[];
    /** The provider did not answer; this page was rebuilt from the local library. */
    fromLibrary?: boolean;
    removedAtSource?: boolean;
}>();

const certificationChip = computed<Chip | null>(() => {
    if (props.movie.certification === null) {
        return null;
    }

    return { label: props.movie.certification, certification: true };
});

const chips = computed<Chip[]>(() => [formatRuntime(props.movie.runtime), certificationChip.value].filter((c): c is Chip => c !== null));

// Directors and writers, each with their real job(s), in the reader's language.
const crewPeople = computed(() => keyCrew(props.movie.crew));

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('common.status'), value: titleStatusLabel(props.movie.status) },
    { label: trans('common.release_date'), value: formatCalendarDate(props.movie.releaseDate) },
    { label: trans('media.original_title'), value: props.movie.extra.originalTitle ?? null },
    { label: trans('common.original_language'), value: props.movie.originalLanguage?.toUpperCase() ?? null },
    // Everything actually spoken on screen, and who paid for it — the two
    // facts the payload already carried and nothing read.
    { label: trans('media.languages'), value: props.movie.extra.spokenLanguages?.join(', ') || null },
    {
        label: trans('media.production_countries'),
        value: props.movie.extra.productionCountries?.map(countryName).join(', ') || null,
    },
    // Low-value trivia, so it sits below the facts people actually came for.
    {
        label: trans('media.alternative_titles'),
        value: alternativeTitlesLine(props.movie.extra.alternativeTitles),
    },
    {
        label: trans('media.director'),
        value:
            props.movie.crew
                .filter(isDirector)
                .map((c) => c.name)
                .join(', ') || null,
    },
    {
        label: trans('media.writers'),
        value:
            props.movie.crew
                .filter(wroteScreenplay)
                .map((c) => c.name)
                .join(', ') || null,
    },
    { label: trans('media.budget'), value: formatMoney(props.movie.extra.budget ?? null) },
    { label: trans('media.revenue'), value: formatMoney(props.movie.extra.revenue ?? null) },
]);
</script>

<template>
    <Head :title="movie.title" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource">
        <template #hero>
            <MediaHero :backdrop-path="movie.backdropPath" :videos="movie.videos" />
        </template>

        <template #header>
            <MediaTitleHeader :details="movie" :chips="chips" :detail-rows="detailRows" />
        </template>

        <template #panels>
            <MediaStatusPanel :media="movie" />
            <StickyActionsPanel :media="movie" />
        </template>

        <OverviewSection :overview="movie.overview" :people="crewPeople" />

        <CastSection :cast="movie.cast" :crew="movie.crew" />
        <!-- One unit, two questions: the tiles say when the film
             came out anywhere, the card beneath says where this
             viewer can actually play it. Tighter than the page's
             section rhythm so they read together. -->
        <div class="space-y-5">
            <ReleasesSection :releases="movie.releases" :release-date="movie.releaseDate" :offer-regions="offerRegions ?? []" />
            <WatchProvidersSection :providers="watchProviders" />
        </div>
        <VideosSection :videos="movie.videos" />
        <PhotosSection :backdrops="movie.backdrops" :posters="movie.posters" :logos="movie.logos" />

        <template #below>
            <RelatedTitles :titles="relatedTitles" />
            <RelatedTitles :titles="otherMedia" :heading="trans('media.in_other_media')" />

            <CollectionBanner v-if="collection" :collection="collection" :fallback-backdrop="movie.backdropPath" />
        </template>

        <template #after>
            <RecommendationsBand
                :related="movie.related"
                :related-title="trans('media.recommended_movies')"
                :similar="movie.similar"
                :similar-title="trans('media.similar_movies')"
            />
        </template>

        <template #recent>
            <RecentlyViewed
                :title="movie.title"
                :type="movie.type"
                :backdrop-path="movie.backdropPath"
                :logo-path="movie.extra.logoPath ?? null"
                :items="recentlyViewed ?? []"
            />
        </template>
    </TitlePageLayout>
</template>
