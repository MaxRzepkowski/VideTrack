<script setup lang="ts">
import CountdownTimer from '@/components/countdown/CountdownTimer.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import ReleaseStatusChip from '@/components/media/ReleaseStatusChip.vue';
import MetaLine from '@/components/MetaLine.vue';
import { useNow } from '@/composables/useNow';
import { usePreferences } from '@/composables/usePreferences';
import { cardBadge } from '@/lib/badges';
import { episodeCode, formatReleaseDate, formatTime } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import { compactFollowedVia, releaseBackdrop, releaseFacts, releaseStatusChip } from '@/lib/releases';
import { mediaUrl } from '@/lib/urls';
import type { EpisodeImage } from '@/types';
import type { CountdownEntry } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { CalendarDays } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{ entry: CountdownEntry }>();

const now = useNow();

// The countdown target: the exact broadcast instant when TVmaze gave us one,
// otherwise the release date at UTC midnight. Server dates are timezone-agnostic
// calendar days — the rest of the app (relativeTime, the calendar day keys)
// reads them in UTC, so the countdown must too, or a viewer ahead of UTC sees a
// still-upcoming premiere as "Out now".
const target = computed<number>(() => {
    const media = props.entry.media;

    if (media.releaseTime) {
        return new Date(media.releaseTime).getTime();
    }

    return media.releaseDate ? new Date(`${media.releaseDate}T00:00:00Z`).getTime() : Number.POSITIVE_INFINITY;
});

// A release known only to its month, quarter or year ("2028") has no day to
// count down to: the date the server sends is a stand-in, so the card names
// the period instead of ticking towards it.
const approximate = computed<boolean>(() => (props.entry.media.extra.releasePrecision ?? 'day') !== 'day');

const released = computed<boolean>(() => !approximate.value && target.value - now.value <= 0);

// Episode hero source preference: the episode's own still or the show backdrop.
// The show backdrop is always the fallback for the other, whichever is chosen.
const preferences = usePreferences();
const imageMode = (): EpisodeImage => preferences.value.countdown_episode_image;

// Landscape hero path — a real backdrop only (no portrait poster stretched to
// fill the wide hero). Episodes pick still vs show backdrop by preference, the
// other falling in behind it. When there is none, a generated colour stands in.
const heroPath = computed<string | null>(() => {
    return releaseBackdrop(props.entry, imageMode());
});

const image = computed<string | null>(() => mediaImage(heroPath.value, 'w780'));
const srcset = computed<string | undefined>(() => mediaImageSrcSet(heroPath.value, [342, 500, 780]));

// Backdrop-less types (books, manga, comics) fall back to the same generated
// hero the rest of the app uses (RecentlyViewed banner): the item's own cover
// colour when known, otherwise the media type's tint, faded into the page.

const fallbackColor = computed<string>(
    () => props.entry.media.extra.coverColor ?? MEDIA_TYPE_META[props.entry.filterType]?.color ?? 'var(--color-line)',
);

const badge = computed(() => cardBadge(props.entry.media));

// The facts under the title, in order — `MetaLine` sets them apart with the
// hairline rule.
const subtitle = computed<(string | undefined)[]>(() => {
    const entry = props.entry;

    if (entry.showTitle !== null) {
        return [episodeCode(entry.media.extra.seasonNumber ?? 0, entry.media.number ?? 0), entry.media.title];
    }

    return releaseFacts(entry.media);
});

// The platform TMDB names on the release row, on its own line — see the twin
// comment in CalendarEntryCard.
const releaseNote = computed<string | null>(() => props.entry.media.extra.releaseNote ?? null);

const releaseStatus = computed<string | null>(() => releaseStatusChip(props.entry.media));

const followedVia = computed(() => compactFollowedVia(props.entry.media));

const releaseDay = computed(() => formatReleaseDate(props.entry.media.releaseDate, props.entry.media.extra.releasePrecision));
</script>

<template>
    <Link
        :href="mediaUrl(entry.media)"
        data-tooltip-group
        class="border-line-subtle bg-surface hover:border-brand group flex flex-col overflow-hidden rounded-2xl border transition-colors duration-150"
    >
        <!-- Hero: backdrop with a bottom gradient carrying the title + subtitle. -->
        <div class="relative aspect-video w-full overflow-hidden">
            <img
                v-if="image"
                :src="image"
                :srcset="srcset"
                sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
                alt=""
                width="780"
                height="439"
                loading="lazy"
                class="absolute inset-0 h-full w-full object-cover"
            />
            <div v-else class="absolute inset-0" :style="{ background: `linear-gradient(120deg, ${fallbackColor}, var(--color-surface))` }"></div>

            <!-- Deep bottom gradient — the hero carries three text lines
                 (title, subtitle, followed-via), which need real contrast. -->
            <div class="from-scrim/95 via-scrim/60 absolute inset-0 bg-gradient-to-t via-35% to-transparent"></div>

            <div class="absolute top-3 left-3 flex flex-wrap items-center gap-2">
                <MediaTypeBadge :type="entry.filterType" />
                <ReleaseStatusChip v-if="releaseStatus" :status="releaseStatus" />
            </div>

            <div class="absolute inset-x-0 bottom-0 p-3.5">
                <div v-tooltip.truncated="entry.showTitle ?? entry.media.title" class="text-on-photo truncate text-lg font-bold">
                    {{ entry.showTitle ?? entry.media.title }}
                </div>
                <div class="flex items-center gap-2">
                    <!-- This line lies on the backdrop, not on the card: the
                         rule takes the tone that rides a photograph. -->
                    <MetaLine :parts="subtitle" tone="media" class="text-brand-soft text-sm font-semibold" />
                    <span
                        v-if="badge"
                        class="text-on-photo inline-block shrink-0 rounded px-1.5 py-0.5 text-sm font-extrabold"
                        :style="{ background: badge.color }"
                    >
                        {{ badge.label }}
                    </span>
                </div>
                <div v-if="releaseNote" class="text-on-photo/80 truncate text-sm font-semibold">{{ releaseNote }}</div>
                <div
                    v-if="followedVia"
                    v-tooltip="trans('calendar.because_you_follow_name', { name: followedVia.full })"
                    class="text-on-photo/60 truncate text-sm"
                >
                    {{ trans('calendar.because_you_follow_name', { name: followedVia.compact }) }}
                </div>
            </div>
        </div>

        <!-- Timer: four animated units. `min-h-20.5` is the height the four timer units come to on their
             own; "Out now" declares the same one so the block does not shrink
             when the countdown ends. -->
        <div v-if="approximate" class="border-line-subtle text-ink flex min-h-20.5 items-center justify-center border-t text-3xl font-bold">
            {{ releaseDay }}
        </div>
        <CountdownTimer v-else-if="!released" :target="target" />
        <div v-else class="border-line-subtle text-brand-soft flex min-h-20.5 items-center justify-center border-t text-lg font-bold">
            {{ trans('calendar.out_now') }}
        </div>

        <!-- Footer: the concrete release moment. -->
        <div class="border-line-subtle text-ink-muted flex items-center gap-2 border-t px-3.5 py-2.5 text-sm">
            <CalendarDays class="size-4 shrink-0" />
            <MetaLine :parts="[releaseDay ? trans('calendar.releases_date', { date: releaseDay }) : null, formatTime(entry.media.releaseTime)]" />
        </div>
    </Link>
</template>
