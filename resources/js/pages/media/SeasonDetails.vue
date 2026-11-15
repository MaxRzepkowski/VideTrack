<script setup lang="ts">
import CardBusyOverlay from '@/components/media/CardBusyOverlay.vue';
import CastSection from '@/components/media/CastSection.vue';
import DetailsModal from '@/components/media/DetailsModal.vue';
import EpisodeOverview from '@/components/media/EpisodeOverview.vue';
import MediaActionBar from '@/components/media/MediaActionBar.vue';
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import NeighbourArrow from '@/components/media/NeighbourArrow.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import SpoilerBlur from '@/components/media/SpoilerBlur.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import WatchNextPanel from '@/components/media/WatchNextPanel.vue';
import WatchProvidersSection from '@/components/media/WatchProvidersSection.vue';
import MetaLine from '@/components/MetaLine.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { usePreferences } from '@/composables/usePreferences';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import { episodeBadge } from '@/lib/badges';
import { episodeCode, formatCalendarDate, formatRuntime, formatTime, formatTotalRuntime } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { mediaUrl } from '@/lib/urls';
import type { SeasonExtra, SeasonNeighbour } from '@/types/extras';
import type { DetailRow, MediaDetails, MediaSummary, WatchAvailability } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps<{
    season: MediaDetails<SeasonExtra>;
    /** Watch time of the whole season — every episode's runtime summed. */
    totalRuntime: number | null;
    watchProviders: WatchAvailability | null;
    // Deferred TVmaze broadcast times keyed by episode number; absent until
    // the follow-up request resolves, empty for streaming / unmatched shows.
    episodeTimes?: Record<number, string>;
    /** The provider did not answer; this page was rebuilt from the local library. */
    fromLibrary?: boolean;
    removedAtSource?: boolean;
}>();

// Marking the season reaches every episode on the page.
useReloadAfterWrites(['userStates']);

const preferences = usePreferences();
const { stateOf } = useMediaActions();

const totalRuntimeText = computed(() => formatTotalRuntime(props.totalRuntime));

// Details modal rows from the data the season already carries, so every media
// page exposes the same "Details" surface (and its Refresh action).
const detailRows = computed<DetailRow[]>(() => [
    { label: trans('media.season'), value: props.season.number != null ? String(props.season.number) : null },
    { label: trans('media.episodes'), value: String(props.season.children.length) },
    { label: trans('common.total_runtime'), value: totalRuntimeText.value },
    { label: trans('common.release_date'), value: formatCalendarDate(props.season.releaseDate) },
]);

const showTitle = computed(() => props.season.extra.showTitle ?? '');
const showRef = computed(() => props.season.extra.showRef ?? '');
const poster = computed(() => mediaImage(props.season.posterPath, 'w500'));

const prevSeason = computed(() => props.season.extra.prevSeason ?? null);
const nextSeason = computed(() => props.season.extra.nextSeason ?? null);

function seasonHref(neighbour: SeasonNeighbour): string {
    return route('season.details', { ref: showRef.value, number: neighbour.number });
}

function code(episode: MediaSummary): string {
    return episodeCode(Number(episode.extra.seasonNumber ?? props.season.number ?? 0), episode.number ?? 0);
}

// Episode still, falling back to the show backdrop (carried on the season)
// when the provider has no still for the episode.
function episodeStillPath(episode: MediaSummary): string | null {
    return episode.backdropPath ?? props.season.backdropPath;
}

function episodeStill(episode: MediaSummary): string | null {
    return mediaImage(episodeStillPath(episode), 'w500');
}

// Exact broadcast time (viewer's timezone) when TVmaze reports a real airing
// slot; streaming premieres and unmatched shows show only the date.
function airTime(episode: MediaSummary): string | null {
    const stamp = episode.number === null ? undefined : props.episodeTimes?.[episode.number];

    return stamp ? formatTime(stamp) : null;
}

// Spoiler protection: stills and overviews of unwatched episodes stay
// blurred until clicked; marking as watched reveals them automatically.
const spoilersOn = computed(() => preferences.value.spoiler_protection);
const revealed = ref(new Set<string>());

function isBlurred(episode: MediaSummary): boolean {
    return spoilersOn.value && !revealed.value.has(episode.ref) && !stateOf(episode).value.watched;
}

// What each episode row shows beside its own fields, worked out once per
// render rather than at every place the template reads it.
const episodeRows = computed(() =>
    props.season.children.map((episode) => ({
        episode,
        still: episodeStill(episode),
        badge: episodeBadge(episode),
        rating: stateOf(episode).value.rating,
    })),
);
</script>

<template>
    <Head :title="trans('media.page_title_in_show', { show: showTitle, title: season.title })" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource" content-class="pb-20">
        <template #hero>
            <MediaHero :backdrop-path="season.backdropPath" :videos="season.videos" />

            <!-- Side arrows: previous / next season. Pinned to the viewport sides,
                 they expand to the season name on hover. -->
            <NeighbourArrow v-if="prevSeason" direction="previous" :href="seasonHref(prevSeason)" :title="prevSeason.title" />
            <NeighbourArrow v-if="nextSeason" direction="next" :href="seasonHref(nextSeason)" :title="nextSeason.title" />
        </template>

        <template #header>
            <!-- Header: poster overlapping the hero + season meta.
                 Below md the poster stacks above the title. -->
            <div class="flex flex-col items-start gap-8 md:flex-row">
                <div
                    class="bg-surface shadow-scrim/60 relative z-20 -mt-24 h-60 w-40 shrink-0 overflow-hidden rounded-2xl shadow-2xl sm:-mt-37.5 sm:h-93.75 sm:w-62.5"
                >
                    <img
                        v-if="poster"
                        :src="poster"
                        :srcset="mediaImageSrcSet(season.posterPath, [185, 342, 500])"
                        sizes="(min-width: 640px) 250px, 160px"
                        :alt="season.title"
                        width="500"
                        height="750"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                </div>
                <div class="w-full min-w-0 md:flex-1 md:pt-10">
                    <!-- The details button sits beside the heading, not in it: a
                         screen reader would read its label as part of the title. -->
                    <div class="mb-1 flex flex-wrap items-center gap-3">
                        <h1 class="m-0 flex flex-wrap items-center gap-3 leading-tight">
                            <span class="sm:text-hero text-3xl font-semibold wrap-break-word">{{ season.title }}</span>
                        </h1>
                        <DetailsModal
                            :title="trans('media.details')"
                            :rows="detailRows"
                            :refresh="{ url: route('media.refresh'), data: { type: 'season', ref: season.ref } }"
                        />
                    </div>
                    <!-- Badges sit under the title, as on every media title page. -->
                    <div class="text-ink-secondary mt-3 flex flex-wrap items-center gap-3 text-sm font-semibold">
                        <MediaTypeBadge type="show" />
                        <span class="border-line-strong bg-scrim/30 rounded-md border px-2.5 py-1">
                            {{
                                transChoice('common.count_episode', season.children.length, {
                                    count: String(season.children.length),
                                })
                            }}
                        </span>
                        <span v-if="season.year" class="border-line-strong bg-scrim/30 rounded-md border px-2.5 py-1">
                            {{ season.year }}
                        </span>
                    </div>
                    <Link
                        :href="route('show.details', { ref: showRef })"
                        class="text-ink-muted hover:text-brand-soft mt-3 inline-block text-lg font-semibold transition-colors duration-150"
                    >
                        ← {{ showTitle }}
                    </Link>
                </div>
            </div>
        </template>

        <template #panels>
            <MediaStatusPanel :media="season" />
            <StickyActionsPanel :media="season" :rate-label="trans('media.rate_this_season')" />
            <WatchNextPanel :media="season" />
        </template>

        <OverviewSection :overview="season.overview" />

        <CastSection :cast="season.cast" :crew="season.crew" />
        <WatchProvidersSection :providers="watchProviders" />

        <section>
            <SectionHeading :title="trans('media.episodes')" />
            <div class="flex flex-col gap-5">
                <div
                    v-for="{ episode, still, badge, rating } in episodeRows"
                    :key="episode.ref"
                    class="border-line-subtle bg-surface flex flex-col overflow-hidden rounded-2xl border md:flex-row"
                >
                    <div class="flex w-full shrink-0 flex-col self-stretch md:w-80">
                        <!-- The still box is the anchor for the busy
                             overlay, so it owns the aspect and the
                             blur wrapper only fills it. -->
                        <div class="relative aspect-video w-full shrink-0 grow basis-auto overflow-hidden">
                            <SpoilerBlur :active="isBlurred(episode)" class="h-full w-full" @reveal="revealed.add(episode.ref)">
                                <Link :href="mediaUrl(episode)" class="relative block h-full w-full">
                                    <img
                                        v-if="still"
                                        :src="still"
                                        :srcset="mediaImageSrcSet(episodeStillPath(episode), [342, 500])"
                                        sizes="(min-width: 768px) 320px, 100vw"
                                        alt=""
                                        width="500"
                                        height="281"
                                        loading="lazy"
                                        class="absolute inset-0 h-full w-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="absolute inset-0"
                                        style="background: linear-gradient(135deg, var(--color-surface-raised), var(--color-line))"
                                    ></div>
                                    <div class="relative h-full w-full">
                                        <div
                                            class="text-ink shadow-card bg-scrim/80 absolute top-3 left-3 rounded-md px-3 py-1.5 text-sm font-extrabold backdrop-blur-sm"
                                        >
                                            {{ code(episode) }}
                                        </div>
                                        <UserRatingBadge v-if="rating !== null" :rating="rating" />
                                    </div>
                                </Link>
                            </SpoilerBlur>
                            <CardBusyOverlay :media="episode" />
                        </div>
                        <MediaActionBar :media="episode" class="mt-auto" />
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col p-6">
                        <h3 class="text-ink m-0 mb-2 flex flex-wrap items-center gap-2.5 text-lg font-semibold">
                            <Link :href="mediaUrl(episode)" class="hover:text-brand-soft transition-colors duration-150">
                                {{ episode.title }}
                            </Link>
                            <span
                                v-if="badge"
                                class="text-on-accent rounded-md px-2 py-0.5 text-sm font-extrabold"
                                :style="{ background: badge.color }"
                            >
                                {{ badge.label }}
                            </span>
                        </h3>
                        <MetaLine
                            :parts="[
                                formatCalendarDate(episode.releaseDate) ?? trans('media.air_date_unknown'),
                                airTime(episode),
                                episode.extra.runtime ? formatRuntime(Number(episode.extra.runtime)) : null,
                            ]"
                            class="text-ink-muted mb-3 font-semibold"
                        />
                        <EpisodeOverview
                            :text="episode.extra.overview ?? trans('media.no_overview_available')"
                            :blurred="isBlurred(episode)"
                            @reveal="revealed.add(episode.ref)"
                        />
                    </div>
                </div>
            </div>
        </section>
    </TitlePageLayout>
</template>
