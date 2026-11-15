<script setup lang="ts">
import CalendarDayHeading from '@/components/calendar/CalendarDayHeading.vue';
import CalendarEntryCard from '@/components/calendar/CalendarEntryCard.vue';
import CalendarEventCard from '@/components/calendar/CalendarEventCard.vue';
import CalendarPreview from '@/components/calendar/CalendarPreview.vue';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { usePreferences } from '@/composables/usePreferences';
import { type CalendarEntryGroup, dayDate, dayLabel, groupCalendarEntries, monthCells, weekStartOf } from '@/lib/calendar';
import { type CalendarPreview as PreviewContent, eventPreview, releasePreview } from '@/lib/calendarPreview';
import { pageLocale } from '@/lib/pageContext';
import type { CalendarEvent } from '@/types/generated';
import type { CalendarMonth } from '@/types/media';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * The month as a wall calendar: seven columns with Monday under Monday, the 1st
 * standing on its own weekday and the weeks before and after it filled in with
 * quiet cells so no row is ever short.
 *
 * Seven legible columns need room, so the alignment is only kept from `3xl` up.
 * Below that the month gives it up rather than shrink a day to a sliver: the
 * days simply flow 1, 2, 3 … left to right, and the filler cells drop out
 * (there is no week to square up when a row holds five days).
 *
 * Each day is the dashboard's own column: the same header, the same release
 * cards, grouped per show the same way. Nothing is capped here — the grid has
 * room to say everything a day holds.
 *
 * The whole month stands on ONE panel, named by its own heading. Left to
 * themselves the days read as thirty-one unrelated lists; the panel is what
 * makes them a calendar again.
 */
const props = defineProps<{ grid: CalendarMonth; today: string; label: string }>();

const locale = pageLocale();
const desktopHover = useMediaQuery('(min-width: 48rem) and (hover: hover) and (pointer: fine)');
const mobile = useMediaQuery('(max-width: 47.999rem)');

// What a card opens: which card (to mark it and to tell a repeated tap) and what the box shows of it.
interface PreviewSelection {
    id: string;
    content: PreviewContent;
}

const preview = ref<PreviewSelection | null>(null);
let hideTimer: ReturnType<typeof setTimeout> | null = null;

const releaseId = (date: string, group: CalendarEntryGroup): string => `${date}:${group.key}`;
const eventId = (item: CalendarEvent): string => `event:${item.event.provider}:${item.event.ref}`;

const releaseSelection = (date: string, group: CalendarEntryGroup): PreviewSelection => ({
    id: releaseId(date, group),
    content: releasePreview(date, group),
});
const eventSelection = (item: CalendarEvent): PreviewSelection => ({ id: eventId(item), content: eventPreview(item) });

const cancelPreviewHide = (): void => {
    if (hideTimer !== null) {
        clearTimeout(hideTimer);
        hideTimer = null;
    }
};

const hidePreview = (): void => {
    cancelPreviewHide();
    preview.value = null;
};

const schedulePreviewHide = (): void => {
    cancelPreviewHide();
    hideTimer = setTimeout(hidePreview, 160);
};

const showPreview = (selection: PreviewSelection): void => {
    cancelPreviewHide();
    preview.value = selection;
};

const previewOnDesktop = (selection: PreviewSelection): void => {
    if (desktopHover.value) {
        showPreview(selection);
    }
};

// A phone has no hover: the first tap selects and previews the row, while a
// repeated tap follows the row's normal link. The preview itself also links
// directly to the item, so the destination remains one action away.
const previewOnTap = (event: MouseEvent, selection: PreviewSelection): void => {
    if (!mobile.value || preview.value?.id === selection.id) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    showPreview(selection);
};

// The short close delay bridges the physical gap between the calendar row and
// the fixed preview. Without it the box would disappear just before the cursor
// reached the preview itself.
const scheduleDesktopHide = (): void => {
    if (desktopHover.value) {
        schedulePreviewHide();
    }
};

watch([desktopHover, mobile], ([canHover, isMobile]) => {
    if (!canHover && !isMobile) {
        hidePreview();
    }
});

watch(
    () => props.grid.month,
    () => {
        hidePreview();
    },
);

onBeforeUnmount(cancelPreviewHide);

const preferences = usePreferences();

const weekStart = computed<number>(() => weekStartOf(preferences.value.week_start, locale));

interface MonthDay {
    date: string;
    groups: CalendarEntryGroup[];
    events: CalendarEvent[];
    /** A day of the month before or after this one, squaring up a week. */
    filler: boolean;
}

// The server only sends the days that hold something; the month walks its own
// span so the empty days keep their place in it.
const days = computed<MonthDay[]>(() => {
    const byDate = new Map(props.grid.days.map((day) => [day.date, day]));

    return monthCells(props.grid.start, props.grid.end, weekStart.value).map((cell) => ({
        ...cell,
        groups: cell.filler ? [] : groupCalendarEntries(byDate.get(cell.date)?.entries ?? []),
        events: cell.filler ? [] : (byDate.get(cell.date)?.events ?? []),
    }));
});
</script>

<template>
    <!-- The panel sits a step BELOW the page, not above it: the release cards
         are already `bg-surface`, so a lighter frame would swallow them. -->
    <section class="border-line-subtle bg-surface-band rounded-2xl border p-5 md:p-6">
        <h2 class="text-ink mb-5 text-lg font-semibold first-letter:uppercase">{{ label }}</h2>

        <!-- The ladder skips no width: 1, 2, 3, 4, 5, 6, 7, each step taken
             where the next column still leaves a day about 230px — the size the
             dashboard gives one. `3xl` is where seven of those fit, and seven is
             the week: from there up the month is a wall calendar and the filler
             cells join in. `items-start` so a day holding eight releases
             stretches itself, not the whole row it happens to sit in. -->
        <div class="xs:grid-cols-2 3xl:grid-cols-7 grid grid-cols-1 items-start gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
            <div
                v-for="day in days"
                :key="day.date"
                class="flex-col gap-3"
                :class="day.filler ? '3xl:flex hidden' : 'flex'"
                :aria-hidden="day.filler ? 'true' : undefined"
            >
                <!-- A filler day belongs to the month next door: it holds the
                     week's shape and says its date, and stops there. -->
                <CalendarDayHeading :label="dayLabel(day.date, today)" :date="dayDate(day.date)" :today="day.date === today" :filler="day.filler" />

                <!-- The day's showcases first: they are at an hour, the releases all day. -->
                <div v-if="day.events.length" class="flex flex-col gap-2">
                    <CalendarEventCard
                        v-for="item in day.events"
                        :key="`${item.event.provider}:${item.event.ref}`"
                        :entry="item"
                        :class="preview?.id === eventId(item) ? 'ring-line-strong ring-1' : ''"
                        @mouseenter="previewOnDesktop(eventSelection(item))"
                        @mouseleave="scheduleDesktopHide"
                        @focusin="previewOnDesktop(eventSelection(item))"
                        @focusout="scheduleDesktopHide"
                        @click.capture="previewOnTap($event, eventSelection(item))"
                    />
                </div>

                <div v-if="day.groups.length" class="flex flex-col gap-2">
                    <CalendarEntryCard
                        v-for="group in day.groups"
                        :key="group.key"
                        :entry="group.entries[0]"
                        :siblings="group.entries"
                        no-image
                        :class="preview?.id === releaseId(day.date, group) ? 'ring-line-strong ring-1' : ''"
                        @mouseenter="previewOnDesktop(releaseSelection(day.date, group))"
                        @mouseleave="scheduleDesktopHide"
                        @focusin="previewOnDesktop(releaseSelection(day.date, group))"
                        @focusout="scheduleDesktopHide"
                        @click.capture="previewOnTap($event, releaseSelection(day.date, group))"
                    />
                </div>
            </div>
        </div>

        <Teleport to="body">
            <Transition name="calendar-preview">
                <CalendarPreview
                    v-if="(desktopHover || mobile) && preview"
                    :key="preview.id"
                    :preview="preview.content"
                    @mouseenter="cancelPreviewHide"
                    @mouseleave="scheduleDesktopHide"
                    @focusin="cancelPreviewHide"
                    @focusout="scheduleDesktopHide"
                    @close="hidePreview"
                />
            </Transition>
        </Teleport>
    </section>
</template>
