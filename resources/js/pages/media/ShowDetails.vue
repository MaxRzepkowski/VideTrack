<script setup lang="ts">
import AdaptationSources from '@/components/media/AdaptationSources.vue';
import AiringEpisodesSection from '@/components/media/AiringEpisodesSection.vue';
import CastSection from '@/components/media/CastSection.vue';
import EpisodeRatingsSection from '@/components/media/EpisodeRatingsSection.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import PhotosSection from '@/components/media/PhotosSection.vue';
import RecentlyViewed from '@/components/media/RecentlyViewed.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import VideosSection from '@/components/media/VideosSection.vue';
import WatchNextPanel from '@/components/media/WatchNextPanel.vue';
import WatchProvidersSection from '@/components/media/WatchProvidersSection.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { useDeferredUserStates } from '@/composables/useMediaActions';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import { countryName, episodeCode, formatCalendarDate, formatRuntime, formatTotalRuntime } from '@/lib/format';
import { showTypeLabel } from '@/lib/showTypes';
import { titleStatusLabel } from '@/lib/titleStatus';
import type { ShowExtra } from '@/types/extras';
import type { Chip, DetailRow, MediaDetails, MediaSummary, SeasonRatings, TitleTie, UserStatesMap, WatchAvailability } from '@/types/media';
import { Deferred, Head } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    show: MediaDetails<ShowExtra>;
    watchProviders: WatchAvailability | null;
    /** Deferred: every episode's runtime summed; absent until the follow-up request lands. */
    totalRuntime?: number | null;
    /** Deferred: absent until the follow-up request lands. */
    episodeRatings?: SeasonRatings[];
    /** Deferred (Wikidata bridge): source books resolved via our book provider. */
    sourceBooks?: MediaSummary[] | null;
    relatedTitles: TitleTie[];
    /** Deferred (Wikidata bridge): source manga (for anime) via our manga provider. */
    sourceManga?: MediaSummary[] | null;
    /** Deferred with the sources: the viewer's state for their cards. */
    deferredUserStates?: UserStatesMap;
    /** Deferred TVmaze broadcast times for the next/last aired cards, keyed by episode ref. */
    episodeTimes?: Record<string, string>;
    recentlyViewed?: MediaSummary[];
    /** The provider did not answer; this page was rebuilt from the local library. */
    fromLibrary?: boolean;
    removedAtSource?: boolean;
}>();

// Marking the show or a season reaches every season and episode on the page.
useReloadAfterWrites(['userStates']);

useDeferredUserStates(() => props.deferredUserStates);

const seasonCount = computed(() => props.show.extra.seasonCount ?? props.show.children.length);

// Most recently aired and upcoming episode (provider-mapped episode summaries),
// shown as compact cards above the cast.
const lastEpisodeToAir = computed(() => props.show.extra.lastEpisodeToAir ?? null);
const nextEpisodeToAir = computed(() => props.show.extra.nextEpisodeToAir ?? null);

const statusLabel = computed(() => titleStatusLabel(props.show.status));

function scrollToSeasons(): void {
    document.getElementById('seasons')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Whole-series watch time (every episode summed) — a Details row, not a chip.
// Arrives with the deferred request, so it fills in a beat after the page.
const totalRuntimeText = computed(() => formatTotalRuntime(props.totalRuntime ?? null));

const certificationChip = computed<Chip | null>(() => {
    if (props.show.certification === null) {
        return null;
    }

    return { label: props.show.certification, certification: true };
});

const chips = computed<Chip[]>(() => {
    const seasons = transChoice('media.count_season', seasonCount.value, { count: String(seasonCount.value) });

    return [
        statusLabel.value,
        formatRuntime(props.show.runtime),
        certificationChip.value,
        // The seasons chip scrolls to the seasons section when there is one.
        props.show.children.length > 0 ? { label: seasons, onClick: scrollToSeasons } : seasons,
    ].filter((c): c is Chip => c !== null);
});

// Creators (with person refs) — shown as the block under the overview and, as
// text, in the Details modal.
const createdBy = computed(() => props.show.extra.createdBy ?? []);

const crewPeople = computed(() => createdBy.value.map((person) => ({ ...person, roles: [trans('media.creator')] })));

// Episodes that also played in cinemas, as their codes ("S02E05, S04E01").
// An anniversary special or a feature-length finale — a handful of shows have
// any, so the row is absent unless there is something to name.
const screenedTheatrically = computed<string | null>(() => {
    const episodes = props.show.extra.screenedTheatrically ?? [];

    return episodes.map((e) => episodeCode(e.seasonNumber, e.episodeNumber)).join(', ') || null;
});

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('common.status'), value: statusLabel.value },
    { label: trans('media.first_air_date'), value: formatCalendarDate(props.show.releaseDate) },
    { label: trans('media.original_title'), value: props.show.extra.originalTitle ?? null },
    { label: trans('common.original_language'), value: props.show.originalLanguage?.toUpperCase() ?? null },
    {
        label: trans('common.country_of_origin'),
        value: props.show.extra.originCountry?.map(countryName).join(', ') || null,
    },
    { label: trans('media.languages'), value: props.show.extra.spokenLanguages?.join(', ') || null },
    // TMDB's own shape of the production (Scripted, Miniseries, Reality…),
    // translated where we have a word for it.
    { label: trans('common.series_type'), value: showTypeLabel(props.show.extra.showType) },
    // Rare enough that the row simply is not there for most shows.
    { label: trans('media.screened_theatrically'), value: screenedTheatrically.value },
    // Same demotion as on the film page: trivia sits below the facts.
    { label: trans('media.alternative_titles'), value: props.show.extra.alternativeTitles?.slice(0, 8).join(', ') || null },
    { label: trans('media.created_by'), value: createdBy.value.map((person) => person.name).join(', ') || null },
    { label: trans('media.seasons'), value: String(seasonCount.value) },
    { label: trans('media.episodes'), value: props.show.extra.episodeCount ? String(props.show.extra.episodeCount) : null },
    { label: trans('common.total_runtime'), value: totalRuntimeText.value },
]);

function episodeCountOf(season: MediaSummary): string {
    const count = Number(season.extra.episodeCount ?? 0);

    return transChoice('common.count_episode', count, { count: String(count) });
}
</script>

<template>
    <Head :title="show.title" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource">
        <template #hero>
            <MediaHero :backdrop-path="show.backdropPath" :videos="show.videos" />
        </template>

        <template #header>
            <MediaTitleHeader :details="show" :chips="chips" :detail-rows="detailRows" />
        </template>

        <template #panels>
            <MediaStatusPanel :media="show" />
            <StickyActionsPanel :media="show" />
            <WatchNextPanel :media="show" />
        </template>

        <OverviewSection :overview="show.overview" :people="crewPeople" />

        <AiringEpisodesSection :last="lastEpisodeToAir" :next="nextEpisodeToAir" :show-backdrop="show.backdropPath" :episode-times="episodeTimes" />
        <CastSection :cast="show.cast" :crew="show.crew" />
        <WatchProvidersSection :providers="watchProviders" />
        <VideosSection :videos="show.videos" />
        <PhotosSection :backdrops="show.backdrops" :posters="show.posters" :logos="show.logos" />

        <template #below>
            <section v-if="show.children.length" id="seasons" class="scroll-mt-24 pt-16">
                <SectionHeading :title="trans('media.seasons')" />
                <MediaGrid>
                    <MediaCard
                        v-for="season in show.children"
                        :key="season.ref"
                        :media="season"
                        :subtitle="episodeCountOf(season)"
                        :show-type-badge="false"
                    />
                </MediaGrid>
            </section>

            <RelatedTitles :titles="relatedTitles" />

            <AdaptationSources data="sourceBooks" :sources="sourceBooks" :title="transChoice('media.based_on_the_book', sourceBooks?.length ?? 0)" />

            <AdaptationSources data="sourceManga" :sources="sourceManga" :title="transChoice('media.based_on_the_manga', sourceManga?.length ?? 0)" />

            <Deferred data="episodeRatings">
                <template #fallback>
                    <section v-if="show.children.length" class="pt-16">
                        <div class="border-line-subtle bg-surface h-90 animate-pulse rounded-2xl border"></div>
                    </section>
                </template>
                <EpisodeRatingsSection :seasons="episodeRatings ?? []" />
            </Deferred>
        </template>

        <template #after>
            <RecommendationsBand
                :related="show.related"
                :related-title="trans('media.related_shows')"
                :similar="show.similar"
                :similar-title="trans('media.similar_shows')"
            />
        </template>

        <template #recent>
            <RecentlyViewed
                :title="show.title"
                :type="show.type"
                :backdrop-path="show.backdropPath"
                :logo-path="show.extra.logoPath ?? null"
                :items="recentlyViewed ?? []"
            />
        </template>
    </TitlePageLayout>
</template>
