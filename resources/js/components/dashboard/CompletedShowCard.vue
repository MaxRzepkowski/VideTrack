<script setup lang="ts">
import EpisodeProgressBar from '@/components/dashboard/EpisodeProgressBar.vue';
import CardBusyOverlay from '@/components/media/CardBusyOverlay.vue';
import CardCaption from '@/components/media/CardCaption.vue';
import MediaStateMarkers from '@/components/media/MediaStateMarkers.vue';
import ProgressCaption from '@/components/media/ProgressCaption.vue';
import ProgressHint from '@/components/media/ProgressHint.vue';
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import type { CompletedShow } from '@/composables/useCompletedShows';
import { useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import { mediaUrl } from '@/lib/urls';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Star } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ entry: CompletedShow }>();

const { openRate } = useMediaModals();
const { stateOf } = useMediaActions();

const state = stateOf(() => props.entry.show);

const poster = computed(() => mediaImage(props.entry.show.posterPath, 'w500'));
const showHref = computed(() => mediaUrl(props.entry.show));
</script>

<template>
    <div data-tooltip-group class="group flex h-full flex-col">
        <!-- Permanently in the colour the other cards only reach on hover:
             the one card in the grid that is not a queue entry. -->
        <div class="border-brand bg-surface xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border">
            <Link :href="showHref" class="block">
                <div class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
                    <img
                        v-if="poster"
                        :src="poster"
                        :srcset="mediaImageSrcSet(entry.show.posterPath, [185, 342, 500])"
                        :sizes="POSTER_CARD_SIZES"
                        :alt="entry.show.title"
                        width="500"
                        height="750"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line h-full w-full bg-gradient-to-br"></div>
                    <!-- Where the episode code sits on an Up Next card: there
                         is no episode left to point at, the show is done. -->
                    <div
                        class="text-ink shadow-card xs:top-3 xs:left-3 xs:px-2.5 xs:py-1 bg-scrim/80 absolute top-2 left-2 rounded-md px-1.5 py-0.5 text-sm font-extrabold backdrop-blur-sm"
                    >
                        {{ trans('common.completed') }}
                    </div>
                    <MediaStateMarkers :media="entry.show" />
                    <!-- The rating lands where it lands on every other card:
                         the ring fills, which is what confirms the click
                         before the card retires. -->
                    <UserRatingBadge
                        v-if="state.rating !== null"
                        :rating="state.rating"
                        class="xs:right-3! xs:bottom-3! xs:scale-100 right-2! bottom-2! origin-bottom-right scale-80"
                    />
                    <CardBusyOverlay :media="entry.show" />
                </div>
            </Link>
            <!-- The action bar of a finished show is a single action: the one
                 thing left to do with it. -->
            <div class="border-surface-overlay bg-surface-raised flex items-stretch border-y">
                <button
                    type="button"
                    class="text-ink hover:bg-highlight/5 flex min-w-0 flex-1 items-center justify-center gap-2 px-3 py-2.5 text-base font-bold transition-colors duration-150"
                    @click="openRate(entry.show)"
                >
                    <Star class="fill-action-rating text-action-rating size-6 shrink-0" :stroke-width="1" />
                    <span class="truncate">{{ trans('dashboard.rate_this_series') }}</span>
                </button>
            </div>
            <!-- Ran out of episodes without watching all of them (a season
                 skipped along the way): the show is done, "all watched" it
                 is not. -->
            <CardCaption
                :title="entry.show.title"
                :subtitle="
                    entry.watchedEpisodes < entry.airedEpisodes ? trans('dashboard.nothing_left_to_watch') : trans('dashboard.all_episodes_watched')
                "
                :href="showHref"
            />
        </div>
        <div class="mt-auto pt-5">
            <ProgressHint :progress="entry">
                <EpisodeProgressBar :segments="entry.segments" />
                <ProgressCaption
                    :text="
                        trans('common.current_of_total_episodes_watched', {
                            current: String(entry.watchedEpisodes),
                            total: String(entry.airedEpisodes),
                        })
                    "
                    :mobile-text="trans('common.current_of_total', { current: String(entry.watchedEpisodes), total: String(entry.airedEpisodes) })"
                />
            </ProgressHint>
        </div>
    </div>
</template>
