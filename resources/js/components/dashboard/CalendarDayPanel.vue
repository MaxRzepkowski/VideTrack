<script setup lang="ts">
import CalendarDayHeading from '@/components/calendar/CalendarDayHeading.vue';
import CalendarEntryCard from '@/components/calendar/CalendarEntryCard.vue';
import type { CalendarEntryGroup } from '@/lib/calendar';
import { mediaImage } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import type { CalendarEntry } from '@/types/media';
import { computed, ref, watch } from 'vue';

/**
 * The nearest days of the dashboard calendar, given room: one portrait poster
 * standing next to the day's releases, the same 2:3 art a title page leads
 * with. Pointing at a release swaps the poster to it, so a day holding several
 * titles can still be looked through without leaving the dashboard.
 *
 * The days further out stay as bare columns — the poster is what marks these
 * two as the ones at hand, and every day carrying one would say nothing.
 */
const props = defineProps<{ label: string; date: string; groups: CalendarEntryGroup[] }>();

// The release the poster is showing: the day's first until a pointer (or the
// keyboard, through focus) names another, and then THAT one stays. Leaving the
// list does not put the first one back — the poster is a thing you were just
// looking at, not a state the day snaps out of.
const active = ref(0);

watch(
    () => props.groups,
    () => {
        active.value = 0;
    },
);

const shown = computed<CalendarEntry | null>(() => props.groups[active.value]?.entries[0] ?? props.groups[0]?.entries[0] ?? null);

// Portrait art: an episode borrows its show's poster (it has none of its own),
// everything else owns one.
const posterPath = (entry: CalendarEntry): string | null => entry.media.extra.showPoster ?? entry.media.posterPath;

const poster = computed<string | null>(() => (shown.value ? mediaImage(posterPath(shown.value), 'w342') : null));

const posterTitle = computed<string>(() => (shown.value ? (shown.value.showTitle ?? shown.value.media.title) : ''));

// Same generated stand-in the cards use when a title has no art of its own:
// the cover colour when the provider knew one, else the media type's tint;
// an empty day has no type, so it stays neutral.
const fallbackColor = computed<string>(() => {
    const entry = shown.value;

    if (entry === null) {
        return 'var(--color-line)';
    }

    const type = entry.showTitle !== null ? 'show' : entry.media.type;

    return entry.media.extra.coverColor ?? mediaTypeMeta(type).color;
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <CalendarDayHeading :label="label" :date="date" />

        <!-- `items-start`, or flex would stretch the poster to the height of the
             list beside it and the 2:3 ratio would go with it: a day holding
             five releases would get a poster twice as tall as the day holding one. -->
        <div class="flex items-start gap-3">
            <!-- The poster is a picture OF the list beside it, so it carries no
                 link of its own — everything it shows is one row away. -->
            <!-- 100px wide, so 150px tall. The width is fixed on purpose: the
                 section sizes its columns off it (see `CalendarSection`).
                 Below `wide` the page has no room to spare and the poster goes
                 entirely — the day then reads like any other. -->
            <div class="border-line-subtle bg-surface wide:block relative hidden aspect-2/3 w-25 shrink-0 overflow-hidden rounded-lg border">
                <img
                    v-if="poster"
                    :src="poster"
                    :alt="posterTitle"
                    width="342"
                    height="513"
                    loading="lazy"
                    class="absolute inset-0 h-full w-full object-cover"
                />
                <div v-else class="absolute inset-0" :style="{ background: `linear-gradient(160deg, ${fallbackColor}, var(--color-surface))` }"></div>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <CalendarEntryCard
                    v-for="(group, index) in groups"
                    :key="group.key"
                    :entry="group.entries[0]"
                    :siblings="group.entries"
                    no-image
                    @mouseenter="active = index"
                    @focusin="active = index"
                />
            </div>
        </div>
    </div>
</template>
