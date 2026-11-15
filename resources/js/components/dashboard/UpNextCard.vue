<script setup lang="ts">
import EpisodeProgressBar from '@/components/dashboard/EpisodeProgressBar.vue';
import CardBusyOverlay from '@/components/media/CardBusyOverlay.vue';
import CardCaption from '@/components/media/CardCaption.vue';
import MediaActionBar from '@/components/media/MediaActionBar.vue';
import MediaStateMarkers from '@/components/media/MediaStateMarkers.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import ProgressCaption from '@/components/media/ProgressCaption.vue';
import ProgressHint from '@/components/media/ProgressHint.vue';
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import { useCardBusy, useMediaActions } from '@/composables/useMediaActions';
import { usePreferences } from '@/composables/usePreferences';
import { episodeBadgeView, hasStateMarkers, leafCode } from '@/lib/badges';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import { mediaUrl, seasonUrlOf } from '@/lib/urls';
import type { UpNextEntry } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, shallowRef, watchEffect } from 'vue';

const props = withDefaults(
    defineProps<{
        entry: UpNextEntry;
        /**
         * Name the type on the poster. The card carries an episode, but among
         * games and books it stands for the show — so it wears the show badge,
         * the same word ("TV") the type filter uses. Off where a whole section
         * is shows already (the dashboard's Up Next).
         */
        showTypeBadge?: boolean;
    }>(),
    { showTypeBadge: false },
);

const { stateOf } = useMediaActions();

const preferences = usePreferences();

const busy = useCardBusy(() => props.entry.episode);

// The poster, the episode code and the progress are page props, not state read
// through `stateOf`: the response that marks this episode watched hands the
// card the NEXT episode outright. Held for as long as the loader is up, so the
// card changes once — when the loader lifts — instead of halfway underneath it.
const entry = shallowRef(props.entry);

watchEffect(() => {
    if (!busy.value) {
        entry.value = props.entry;
    }
});

const state = stateOf(() => entry.value.episode);
const markersVisible = computed(() => hasStateMarkers(state.value));

const poster = computed(() => mediaImage(entry.value.poster, 'w500'));
const episodeHref = computed(() => mediaUrl(entry.value.episode));
const showHref = computed(() => mediaUrl(entry.value.show));

const seasonHref = computed(() => seasonUrlOf(entry.value.episode));

// Where the card navigates, per the user's preference (default: the season).
const primaryHref = computed(() => {
    switch (preferences.value.up_next_target) {
        case 'episode':
            return episodeHref.value;
        case 'show':
            return showHref.value;
        default:
            return seasonHref.value;
    }
});

const code = computed(() => leafCode(entry.value.episode));

const badge = computed(() => (entry.value.badge === null ? null : episodeBadgeView(entry.value.badge)));
</script>

<template>
    <div data-tooltip-group class="flex h-full flex-col">
        <!-- The hover colour belongs to the card itself, not to the progress
             below it: reading how far you are is not aiming at the poster. -->
        <div
            class="border-line-subtle bg-surface hover:border-brand xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border transition-colors duration-150"
        >
            <Link :href="primaryHref" class="block">
                <div class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
                    <img
                        v-if="poster"
                        :src="poster"
                        :srcset="mediaImageSrcSet(entry.poster, [185, 342, 500])"
                        :sizes="POSTER_CARD_SIZES"
                        :alt="entry.show.title"
                        width="500"
                        height="750"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line h-full w-full bg-gradient-to-br"></div>
                    <div class="scrim-bottom absolute inset-0"></div>
                    <div
                        v-if="code"
                        class="text-ink shadow-card xs:top-3 xs:left-3 xs:px-2.5 xs:py-1 bg-scrim/80 absolute top-2 left-2 rounded-md px-1.5 py-0.5 text-sm font-extrabold backdrop-blur-sm"
                    >
                        {{ code }}
                    </div>
                    <MediaTypeBadge v-if="showTypeBadge" type="show" compact-on-mobile class="xs:top-3 xs:right-3 absolute top-2 right-2" />
                    <div
                        v-if="badge"
                        class="text-ink shadow-card xs:bottom-3 xs:left-3 xs:px-2.5 xs:py-1 absolute left-2 rounded-md px-1.5 py-0.5 text-sm font-extrabold"
                        :class="markersVisible ? 'bottom-9' : 'bottom-2'"
                        :style="{ background: badge.color }"
                    >
                        {{ badge.label }}
                    </div>
                    <MediaStateMarkers :media="entry.episode" />
                    <UserRatingBadge
                        v-if="state.rating !== null"
                        :rating="state.rating"
                        class="xs:right-3! xs:bottom-3! xs:scale-100 right-2! bottom-2! origin-bottom-right scale-80"
                    />
                    <CardBusyOverlay :media="entry.episode" />
                </div>
            </Link>
            <MediaActionBar :media="entry.episode" />
            <CardCaption :title="entry.episode.title" :subtitle="entry.show.title" :href="primaryHref" :subtitle-href="showHref" />
        </div>
        <div class="mt-auto pt-5">
            <ProgressHint :progress="entry">
                <EpisodeProgressBar :segments="entry.segments" />
                <!-- Which episode of the show this is says something the S06E02
                     code above cannot, so it leads; what is left to watch
                     follows behind the hairline, because with skips in the run
                     the two are different numbers. -->
                <ProgressCaption
                    :text="trans('common.episode_current_of_total', { current: String(entry.position), total: String(entry.airedEpisodes) })"
                    :mobile-text="trans('common.current_of_total', { current: String(entry.position), total: String(entry.airedEpisodes) })"
                    :remaining="entry.remainingEpisodes"
                />
            </ProgressHint>
        </div>
    </div>
</template>
