<script setup lang="ts">
import CastSection from '@/components/media/CastSection.vue';
import DetailsModal from '@/components/media/DetailsModal.vue';
import LibraryFallbackNotice from '@/components/media/LibraryFallbackNotice.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import NeighbourArrow from '@/components/media/NeighbourArrow.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import PhotosSection from '@/components/media/PhotosSection.vue';
import SpoilerBlur from '@/components/media/SpoilerBlur.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import VideosSection from '@/components/media/VideosSection.vue';
import MetaLine from '@/components/MetaLine.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { usePreferences } from '@/composables/usePreferences';
import AppLayout from '@/layouts/AppLayout.vue';
import { episodeBadge } from '@/lib/badges';
import { episodeCode, formatCalendarDate, formatRuntime, formatTime } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { ratingPercent } from '@/lib/ratings';
import type { EpisodeExtra, EpisodeNeighbour } from '@/types/extras';
import type { DetailRow, MediaDetails } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Star, Tv } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    episode: MediaDetails<EpisodeExtra>;
    // Deferred TVmaze broadcast instant; absent until the follow-up request
    // resolves, null for streaming / unmatched shows.
    airTime?: string | null;
    /** The provider did not answer; this page was rebuilt from the local library. */
    fromLibrary?: boolean;
    removedAtSource?: boolean;
}>();

const showTitle = computed(() => props.episode.extra.showTitle ?? '');
const showRef = computed(() => props.episode.extra.showRef ?? '');
const seasonNumber = computed(() => Number(props.episode.extra.seasonNumber ?? 0));
const seasonTitle = computed(() => props.episode.extra.seasonTitle ?? trans('common.season_number', { number: String(seasonNumber.value) }));
const code = computed(() => episodeCode(seasonNumber.value, props.episode.number ?? 0));

// An episode is represented by its own still. The parent show's backdrop does
// not belong to this page and would repeat a second, unrelated image above it.
const still = computed(() => mediaImage(props.episode.backdropPath, 'w780'));
const badge = computed(() => episodeBadge(props.episode));

const airDate = computed(() => formatCalendarDate(props.episode.releaseDate) ?? trans('media.air_date_unknown'));
const airInstant = computed(() => (props.airTime ? formatTime(props.airTime) : null));

const prev = computed(() => props.episode.extra.prev ?? null);
const next = computed(() => props.episode.extra.next ?? null);

function neighbourHref(neighbour: EpisodeNeighbour): string {
    return route('episode.details', { ref: showRef.value, number: neighbour.seasonNumber, episode: neighbour.episodeNumber });
}

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('media.air_date'), value: formatCalendarDate(props.episode.releaseDate) },
    { label: trans('common.runtime'), value: formatRuntime(props.episode.runtime) },
    // Only for the handful of episodes that played in cinemas — a row saying
    // "no" on every other episode of every series would be noise.
    { label: trans('media.screened_theatrically'), value: props.episode.extra.screenedTheatrically ? trans('media.yes') : null },
]);

// Promos for a single episode give away its turns, so they follow the same
// rule as the stills in SeasonDetails: hidden until watched or clicked.
const preferences = usePreferences();
const { stateOf } = useMediaActions();
const revealed = ref(false);
const videosBlurred = computed(() => preferences.value.spoiler_protection && !revealed.value && !stateOf(props.episode).value.watched);
</script>

<template>
    <AppLayout>
        <Head :title="trans('media.page_title_in_show', { show: showTitle, title: code })" />

        <!-- Side arrows: previous / next episode (crossing seasons at the
             edges). Pinned to the viewport sides, they expand to the episode
             title on hover. -->
        <NeighbourArrow
            v-if="prev"
            direction="previous"
            :href="neighbourHref(prev)"
            :title="prev.title"
            :code="episodeCode(prev.seasonNumber, prev.episodeNumber)"
        />
        <NeighbourArrow
            v-if="next"
            direction="next"
            :href="neighbourHref(next)"
            :title="next.title"
            :code="episodeCode(next.seasonNumber, next.episodeNumber)"
        />

        <div class="max-w-content mx-auto w-full flex-1 px-4 pt-6 pb-20 md:px-10 md:pt-8">
            <LibraryFallbackNotice :show="fromLibrary" :removed="removedAtSource" class="mb-8" />

            <div class="grid grid-cols-1 gap-10 lg:grid-cols-3">
                <div class="space-y-8 lg:col-span-2">
                    <!-- Header: the episode's own still beside its metadata. -->
                    <div class="flex flex-col items-start gap-8 md:flex-row">
                        <div class="bg-surface shadow-scrim/60 aspect-video w-full shrink-0 overflow-hidden rounded-2xl shadow-2xl md:w-90">
                            <img
                                v-if="still"
                                :src="still"
                                :srcset="mediaImageSrcSet(episode.backdropPath, [342, 780])"
                                sizes="(min-width: 768px) 360px, 100vw"
                                :alt="episode.title"
                                width="780"
                                height="439"
                                loading="lazy"
                                class="h-full w-full object-cover"
                            />
                            <!-- No still: the episode (TV) icon stands in. -->
                            <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                                <Tv class="text-ink-faint size-12" :stroke-width="1.5" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <!-- The details button sits beside the heading, not in it: a
                                 screen reader would read its label as part of the title. -->
                            <div class="mb-1 flex flex-wrap items-center gap-3">
                                <h1 class="m-0 flex flex-wrap items-center gap-3 leading-tight">
                                    <span class="sm:text-hero text-3xl font-semibold wrap-break-word">{{ episode.title }}</span>
                                </h1>
                                <DetailsModal
                                    :title="trans('media.details')"
                                    :rows="detailRows"
                                    :links="episode.externalLinks"
                                    :refresh="{ url: route('media.refresh'), data: { type: 'episode', ref: episode.ref } }"
                                />
                            </div>
                            <MetaLine
                                :parts="[airDate, airInstant, episode.runtime ? formatRuntime(episode.runtime) : null]"
                                class="text-ink-muted mt-2 font-semibold"
                            />
                            <!-- Badges sit under the title, as on every media title page. -->
                            <div class="text-ink-secondary mt-3 flex flex-wrap items-center gap-3 text-sm font-semibold">
                                <MediaTypeBadge type="episode" />
                                <span class="border-line-strong bg-scrim/30 rounded-md border px-2.5 py-1 font-extrabold">{{ code }}</span>
                                <span v-if="badge" class="text-on-accent rounded-md px-2.5 py-1 font-extrabold" :style="{ background: badge.color }">
                                    {{ badge.label }}
                                </span>
                                <span v-if="episode.communityRating" class="inline-flex items-center gap-1.5">
                                    <Star class="fill-action-rating text-action-rating size-3.75" :stroke-width="1" />
                                    <span class="text-action-rating font-bold">{{ ratingPercent(episode.communityRating) }}</span>
                                </span>
                            </div>
                            <!-- Breadcrumb: show → season, at the bottom (like the season page's back link) -->
                            <nav
                                :aria-label="trans('media.where_this_episode_belongs')"
                                class="text-ink-muted mt-3 flex flex-wrap items-center gap-2 text-lg font-semibold"
                            >
                                <Link :href="route('show.details', { ref: showRef })" class="hover:text-brand-soft transition-colors duration-150">
                                    {{ showTitle }}
                                </Link>
                                <span class="text-ink-faint" aria-hidden="true">›</span>
                                <Link
                                    :href="route('season.details', { ref: showRef, number: seasonNumber })"
                                    class="hover:text-brand-soft transition-colors duration-150"
                                >
                                    {{ seasonTitle }}
                                </Link>
                            </nav>
                        </div>
                    </div>

                    <!-- Mobile action surface: the sticky sidebar is lg-only, so
                         below lg the status / rating / list / favorite actions live here. -->
                    <div class="space-y-5 lg:hidden">
                        <MediaStatusPanel :media="episode" />
                        <StickyActionsPanel :media="episode" :rate-label="trans('media.rate_this_episode')" />
                    </div>

                    <OverviewSection :overview="episode.overview" />

                    <CastSection :cast="episode.cast" :crew="episode.crew" />

                    <!-- Episode stills gallery (TMDB); backdrops = stills, no
                         posters/logos. Auto-hides when the episode has none. -->
                    <PhotosSection :backdrops="episode.backdrops" :posters="[]" :backdrops-label="trans('media.photos')" />

                    <!-- Guarded here as well as inside: the blur wrapper is a
                         real element and would leave a gap on the many
                         episodes without a video. -->
                    <SpoilerBlur v-if="episode.videos.length > 0" :active="videosBlurred" @reveal="revealed = true">
                        <VideosSection :videos="episode.videos" />
                    </SpoilerBlur>
                </div>

                <div class="hidden pt-8 lg:block">
                    <div class="sticky top-24 space-y-5">
                        <MediaStatusPanel :media="episode" />
                        <StickyActionsPanel :media="episode" :rate-label="trans('media.rate_this_episode')" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
