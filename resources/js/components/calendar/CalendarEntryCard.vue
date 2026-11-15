<script setup lang="ts">
import ReleaseStatusChip from '@/components/media/ReleaseStatusChip.vue';
import MetaLine from '@/components/MetaLine.vue';
import { usePreferences } from '@/composables/usePreferences';
import { episodeBadge } from '@/lib/badges';
import { episodeCode, formatTime } from '@/lib/format';
import { mediaImage } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { batchHref, compactFollowedVia, releaseBackdrop, releaseFacts, releaseStatusChip } from '@/lib/releases';
import { mediaUrl } from '@/lib/urls';
import type { EpisodeImage, NonEmpty } from '@/types';
import type { CalendarEntry } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

// `noImage` renders the compact, thumbnail-less card the dashboard widget and
// the month grid use; with an image the same card gains a backdrop on top.
//
// `siblings` are every episode of the same show landing on the same day (a
// season drop). With more than one the card becomes ONE record for the show
// with each episode listed inside it, instead of a stack of near-identical
// cards. Callers that never group simply omit it.
const props = withDefaults(defineProps<{ entry: CalendarEntry; noImage?: boolean; siblings?: CalendarEntry[] }>(), {
    siblings: undefined,
});

// Episodes listed in show order, not air order — a batch drop shares one
// timestamp, so sorting by it would leave the list shuffled.
const batch = computed<NonEmpty<CalendarEntry> | null>(() => {
    if (!props.siblings || props.siblings.length < 2) {
        return null;
    }

    const [first, ...rest] = [...props.siblings].sort(
        (a, b) => Number(a.media.extra.seasonNumber ?? 0) - Number(b.media.extra.seasonNumber ?? 0) || (a.media.number ?? 0) - (b.media.number ?? 0),
    );

    return first === undefined ? null : [first, ...rest];
});

/** "S01E04" for one entry of a batch — the row label in the episode list. */
const codeOf = (item: CalendarEntry): string => episodeCode(Number(item.media.extra.seasonNumber ?? 0), item.media.number ?? 0);

// Episode thumbnail source preference: the show backdrop or the episode's own still.
const preferences = usePreferences();
const imageMode = (): EpisodeImage => preferences.value.calendar_episode_image;

// Landscape thumbnail — a real backdrop only. Episodes pick still vs show
// backdrop by preference (the other falling in behind it); other types use
// their own backdrop. When there is none, a generated colour stands in.
const image = computed<string | null>(() => {
    if (props.noImage) {
        return null;
    }

    const path = releaseBackdrop(props.entry, imageMode());

    return path ? mediaImage(path, 'w342') : null;
});

// An episode belongs to its show, whatever the row's own media type says.
const typeKey = computed<string>(() => (props.entry.showTitle !== null ? 'show' : props.entry.media.type));

// The type is said by a glyph in the type's own colour, parked in the bottom
// right corner of the text block — out of the reading path, found by the eye
// only when it goes looking. A coloured top edge or a coloured spine down
// the left would shout across a column of cards.
// The corner is the corner of the TEXT, not of the card, so a merged card marks
// it in the same place instead of over its episode list.
const typeColor = computed<string>(() => mediaTypeMeta(typeKey.value).color);

// Backdrop-less types (books, manga, comics) fall back to the same generated
// hero the rest of the app uses: the item's own cover colour when known,
// otherwise the media type's tint, faded into the page background.
const fallbackColor = computed<string>(() => props.entry.media.extra.coverColor ?? typeColor.value);

// A lone episode is set exactly like one row of a batch: the code in bold, the
// episode title straight after it, no rule between the two — the weight does
// the separating. A day that merged its episodes and a day that had only one
// must read the same; a hairline would make the merged card look like another
// kind of card. Handled in the template, so it never reaches `subtitle()`.
const episode = computed<{ code: string; title: string } | null>(() =>
    batch.value === null && props.entry.showTitle !== null ? { code: codeOf(props.entry), title: props.entry.media.title } : null,
);

// The facts under the title, in order — `MetaLine` sets them apart with the
// hairline rule.
const subtitle = (): (string | undefined)[] => {
    const entry = props.entry;

    // A batch names the count in the header; every episode still gets its own
    // row underneath.
    if (batch.value !== null) {
        return [transChoice('common.count_episode', batch.value.length, { count: String(batch.value.length) })];
    }

    return releaseFacts(entry.media);
};

// TMDB's note on the release row — usually the platform ("Prime Video"),
// sometimes a format, most often nothing at all. Its own line rather than
// another hairline-separated fact: it answers a different question from the
// rest of the subtitle ("where", not "what kind of release"), and it is the
// one line on this card a viewer scans for.
const releaseNote = computed<string | null>(() => props.entry.media.extra.releaseNote ?? null);

// A single release links to itself; a batch's HEADER links to the season it
// fills (or the show when it straddles two) — the episode rows below keep
// their own links.
const href = computed<string>(() => {
    if (batch.value === null) {
        return mediaUrl(props.entry.media);
    }

    return batchHref(batch.value);
});

const releaseStatus = computed(() => releaseStatusChip(props.entry.media));

const followedVia = computed(() => compactFollowedVia(props.entry.media));
</script>

<template>
    <!-- The release card shared by the dashboard widget and the calendar page. -->
    <component
        :is="batch === null ? Link : 'div'"
        :href="batch === null ? href : undefined"
        data-tooltip-group
        class="border-line-subtle bg-surface flex flex-col overflow-hidden rounded-lg border transition-colors duration-150"
        :class="batch === null ? 'hover:border-brand' : 'hover:border-line-strong'"
    >
        <div v-if="!noImage" class="relative aspect-video w-full overflow-hidden">
            <img v-if="image" :src="image" alt="" width="342" height="192" loading="lazy" class="absolute inset-0 h-full w-full object-cover" />
            <div v-else class="absolute inset-0" :style="{ background: `linear-gradient(120deg, ${fallbackColor}, var(--color-surface))` }"></div>
        </div>
        <!-- `pr-9` keeps the corner glyph a gutter of its own: text is a block,
             it does not flow around an absolute box, so the room has to be made. -->
        <div class="relative min-w-0 p-2.5 pr-9">
            <!-- A glyph says nothing to a screen reader — the type says it here. -->
            <span class="sr-only">{{ mediaTypeMeta(typeKey).label() }}</span>
            <component
                :is="mediaTypeMeta(typeKey).icon"
                class="absolute right-2.5 bottom-2.5"
                :class="typeKey === 'game' ? 'size-5' : 'size-4.5'"
                :style="{ color: typeColor }"
                aria-hidden="true"
            />
            <!-- Grouped: the show name is its own link (the season page). -->
            <Link v-if="batch !== null" :href="href" class="text-ink hover:text-brand-soft block text-base font-semibold wrap-break-word">
                {{ entry.showTitle }}
            </Link>
            <div v-else class="text-ink text-base font-semibold wrap-break-word">{{ entry.showTitle ?? entry.media.title }}</div>
            <div v-if="episode" class="text-ink-muted text-sm wrap-break-word">
                <span class="text-ink-secondary font-semibold">{{ episode.code }}</span>{{ ' ' + episode.title }}
            </div>
            <MetaLine v-else :parts="subtitle()" wrap class="text-ink-muted text-sm" />
            <div v-if="releaseNote" class="text-ink-secondary text-sm font-semibold wrap-break-word">{{ releaseNote }}</div>
            <div v-if="entry.media.releaseTime" class="text-ink-secondary text-sm font-semibold">
                {{ formatTime(entry.media.releaseTime) }}
            </div>
            <!-- Person-sourced tracking: say WHY this release is here. -->
            <div
                v-if="followedVia"
                v-tooltip="trans('calendar.because_you_follow_name', { name: followedVia.full })"
                class="text-ink-faint text-sm wrap-break-word"
            >
                {{ trans('calendar.because_you_follow_name', { name: followedVia.compact }) }}
            </div>
            <span
                v-if="batch === null && episodeBadge(entry.media)"
                class="text-on-photo mt-1 inline-block rounded px-1.5 py-0.5 text-sm font-extrabold"
                :style="{ background: episodeBadge(entry.media)?.color }"
            >
                {{ episodeBadge(entry.media)?.label }}
            </span>
            <ReleaseStatusChip v-if="releaseStatus" :status="releaseStatus" class="mt-1" />
        </div>

        <!-- Every episode of the batch, each still reachable on its own. -->
        <div v-if="batch !== null" class="border-line-subtle flex flex-col border-t">
            <Link
                v-for="item in batch"
                :key="item.media.ref"
                :href="mediaUrl(item.media)"
                class="hover:bg-surface-raised flex items-baseline gap-2 px-2.5 py-1.5 transition-colors duration-150"
            >
                <span class="text-ink-secondary shrink-0 text-sm font-semibold">{{ codeOf(item) }}</span>
                <!-- The badge sits UNDER the title, never beside it: sharing the
                     row would squeeze the title into a sliver on a narrow day
                     column. Same shape a single episode's card gives it. -->
                <span class="flex min-w-0 flex-1 flex-col items-start gap-1">
                    <span class="text-ink-muted text-sm wrap-break-word">{{ item.media.title }}</span>
                    <span
                        v-if="episodeBadge(item.media)"
                        class="text-on-photo rounded px-1.5 py-0.5 text-sm font-extrabold"
                        :style="{ background: episodeBadge(item.media)?.color }"
                    >
                        {{ episodeBadge(item.media)?.label }}
                    </span>
                </span>
            </Link>
        </div>
    </component>
</template>
