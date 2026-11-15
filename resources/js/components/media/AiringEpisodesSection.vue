<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import CardBusyOverlay from '@/components/media/CardBusyOverlay.vue';
import MediaActionBar from '@/components/media/MediaActionBar.vue';
import SpoilerBlur from '@/components/media/SpoilerBlur.vue';
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { usePreferences } from '@/composables/usePreferences';
import { episodeBadge } from '@/lib/badges';
import { episodeCode, formatCalendarDate, formatTime, relativeTime } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

// The show's most recently aired and upcoming episode, rendered like a season's
// episode card but stripped of the overview panel. A small heading labels each;
// the still carries the code chip and a "how far off" stamp, and the premiere /
// finale badge rides next to the title exactly as on the season list.
const props = defineProps<{
    last: MediaSummary | null;
    next: MediaSummary | null;
    /** Show backdrop, used when an episode has no still of its own. */
    showBackdrop?: string | null;
    /** Deferred TVmaze broadcast times keyed by episode ref; absent until the follow-up request resolves. */
    episodeTimes?: Record<string, string>;
}>();

const preferences = usePreferences();
const { stateOf } = useMediaActions();

// Latest (past) on the left, next (future) on the right — the row reads as a
// timeline; either side is dropped when the show has no such episode.
const entries = computed(() =>
    [
        { role: 'last' as const, episode: props.last },
        { role: 'next' as const, episode: props.next },
    ].flatMap(({ role, episode }) => (episode === null ? [] : [{ role, episode, still: still(episode), rating: stateOf(episode).value.rating }])),
);

function code(episode: MediaSummary): string {
    return episodeCode(Number(episode.extra.seasonNumber ?? 0), episode.number ?? 0);
}

// Episode still, falling back to the show backdrop when TMDB has no still.
function stillPath(episode: MediaSummary): string | null {
    return episode.backdropPath ?? props.showBackdrop ?? null;
}

function still(episode: MediaSummary): string | null {
    return mediaImage(stillPath(episode), 'w500');
}

// Exact broadcast instant (TVmaze) when known — carries the time, so both the
// displayed hour and the "how far off" countdown account for it; otherwise the
// date-only value is used and the countdown falls back to day granularity.
function airStamp(episode: MediaSummary): string | null {
    return props.episodeTimes?.[episode.ref] ?? null;
}

function airTime(episode: MediaSummary): string | null {
    return formatTime(airStamp(episode));
}

function countdown(episode: MediaSummary): string | null {
    return relativeTime(airStamp(episode) ?? episode.releaseDate);
}

// Spoiler protection: the still stays blurred until clicked; marking the
// episode watched reveals it automatically — same behaviour as the season list.
const spoilersOn = computed(() => preferences.value.spoiler_protection);
const revealed = ref(new Set<string>());

function isBlurred(episode: MediaSummary): boolean {
    return spoilersOn.value && !revealed.value.has(episode.ref) && !stateOf(episode).value.watched;
}
</script>

<template>
    <section v-if="entries.length" class="grid grid-cols-1 gap-x-5 gap-y-6 sm:grid-cols-2">
        <div v-for="{ role, episode, still: image, rating } in entries" :key="episode.ref">
            <h3 class="text-ink mb-3 text-lg font-semibold">
                {{ role === 'next' ? trans('media.next_episode') : trans('media.latest_episode') }}
            </h3>
            <article
                class="border-line-subtle bg-surface hover:border-brand flex flex-col overflow-hidden rounded-2xl border transition-colors duration-150"
            >
                <div class="relative aspect-video w-full overflow-hidden">
                    <SpoilerBlur :active="isBlurred(episode)" class="h-full w-full" @reveal="revealed.add(episode.ref)">
                        <Link :href="mediaUrl(episode)" class="relative block h-full w-full">
                            <img
                                v-if="image"
                                :src="image"
                                :srcset="mediaImageSrcSet(stillPath(episode), [342, 500])"
                                sizes="(min-width: 640px) 340px, 100vw"
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
                            <UserRatingBadge v-if="rating !== null" :rating="rating" />
                        </Link>
                    </SpoilerBlur>
                    <!-- Code + air-countdown chips ride above the blur: the still is
                         the spoiler, the episode's identity and timing are not.
                         pointer-events-none lets clicks fall through beneath. -->
                    <div class="pointer-events-none absolute inset-0 z-20">
                        <div
                            class="text-ink shadow-card bg-scrim/80 absolute top-3 left-3 rounded-md px-3 py-1.5 text-sm font-extrabold backdrop-blur-sm"
                        >
                            {{ code(episode) }}
                        </div>
                        <span
                            v-if="episodeBadge(episode)"
                            class="shadow-card text-on-photo absolute bottom-3 left-3 rounded-md px-2 py-0.5 text-sm font-extrabold"
                            :style="{ background: episodeBadge(episode)?.color }"
                        >
                            {{ episodeBadge(episode)?.label }}
                        </span>
                    </div>
                    <CardBusyOverlay :media="episode" />
                </div>
                <div class="px-4 py-2.5">
                    <h4 class="text-ink m-0 font-semibold">
                        <Link :href="mediaUrl(episode)" class="hover:text-brand-soft transition-colors duration-150">
                            {{ episode.title }}
                        </Link>
                    </h4>
                    <MetaLine
                        :parts="[formatCalendarDate(episode.releaseDate) ?? trans('media.air_date_unknown'), airTime(episode), countdown(episode)]"
                        class="text-ink-muted mt-0.5 text-sm font-semibold"
                    />
                </div>
                <MediaActionBar :media="episode" class="mt-auto" />
            </article>
        </div>
    </section>
</template>
