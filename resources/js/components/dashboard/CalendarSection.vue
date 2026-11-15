<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import CalendarDayHeading from '@/components/calendar/CalendarDayHeading.vue';
import CalendarEntryCard from '@/components/calendar/CalendarEntryCard.vue';
import CalendarDayPanel from '@/components/dashboard/CalendarDayPanel.vue';
import { dayDate, dayLabel, groupCalendarEntries } from '@/lib/calendar';
import { todayDateKey } from '@/lib/format';
import type { CalendarDay } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{ days: CalendarDay[] }>();

// One card per release would stack a whole season drop in a single narrow day
// column — the shared grouping collapses those into one card per show.
const grouped = computed(() => props.days.map((day) => ({ date: day.date, groups: groupCalendarEntries(day.entries) })));

// Server dates are plain Y-m-d; "today" follows the viewer's preferred timezone
// (browser fallback), not whichever zone the browser happens to sit in.
const todayKey = todayDateKey();

// The widget is two rows: the days at hand, each with a poster standing beside
// its releases, and everything further out as bare columns.
const FEATURED = 2;

const featured = computed(() => grouped.value.slice(0, FEATURED));

const rest = computed(() => grouped.value.slice(FEATURED));
</script>

<template>
    <EmptyState v-if="days.length === 0" :message="trans('dashboard.no_upcoming_releases_for_your_shows')" />
    <!-- On a full-width page (`wide` = the container's own 1400px) the row runs
         poster, cards, poster, cards, cards, cards, cards — and every column of
         cards comes out the same width: a day with a poster starts from a basis
         of exactly poster + gap (`7rem` = `w-25` + `gap-3`) and then grows by
         the same share as a bare day, so what is left for its cards is
         precisely one bare column. Holds for any number of days, no column
         counting. Below that the posters are gone (see `CalendarDayPanel`) and
         every day is the same plain column, so the grid takes over. -->
    <!-- The step to five days a row waits for `xl`: at `lg` a column would
         be around 180px, which is under what a card needs to stay readable. -->
    <div v-else class="xs:grid-cols-2 wide:flex wide:flex-row grid grid-cols-1 items-start gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        <CalendarDayPanel
            v-for="day in featured"
            :key="day.date"
            :label="dayLabel(day.date, todayKey)"
            :date="dayDate(day.date, todayKey)"
            :groups="day.groups"
            class="wide:min-w-0 wide:flex-[1_1_7rem]"
        />

        <div v-for="day in rest" :key="day.date" class="wide:min-w-0 wide:flex-1 flex flex-col gap-3">
            <CalendarDayHeading :label="dayLabel(day.date, todayKey)" :date="dayDate(day.date, todayKey)" />
            <div class="flex flex-col gap-2">
                <CalendarEntryCard v-for="group in day.groups" :key="group.key" :entry="group.entries[0]" :siblings="group.entries" no-image />
            </div>
        </div>
    </div>
</template>
