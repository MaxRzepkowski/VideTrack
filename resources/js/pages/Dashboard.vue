<script setup lang="ts">
import { CALENDAR, groupOf } from '@/lib/navigation';
import CalendarSection from '@/components/dashboard/CalendarSection.vue';
import CompletedShowCard from '@/components/dashboard/CompletedShowCard.vue';
import PlayingCard from '@/components/dashboard/PlayingCard.vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import UpNextCard from '@/components/dashboard/UpNextCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageSection from '@/components/PageSection.vue';
import ScrollRow from '@/components/ScrollRow.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import SectionMoreLink from '@/components/SectionMoreLink.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { useCompletedShows } from '@/composables/useCompletedShows';
import { usePreferences } from '@/composables/usePreferences';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import { useShelfLimit } from '@/composables/useShelfLimit';
import AppLayout from '@/layouts/AppLayout.vue';
import { inRegistryOrder } from '@/lib/mediaTypes';
import { signedInUser } from '@/lib/pageContext';
import { isPositionCounter } from '@/lib/progress';
import type { CalendarDay, PlayingEntry, UpNextEntry, WatchStats } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { BookOpenText, CalendarDays, ChartNoAxesColumnIncreasing, Gamepad2, ListVideo, Search } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    stats: Record<string, WatchStats>;
    upNext: UpNextEntry[];
    playing: PlayingEntry[];
    calendar: CalendarDay[];
}>();

// Up Next, now playing, the calendar and the stats all move with a write.
useReloadAfterWrites(['stats', 'upNext', 'playing', 'calendar', 'userStates']);

const user = signedInUser();
const preferences = usePreferences();

// A show finished on this page swaps its entry for a completion card until
// the user rates it or leaves, so the section does not empty a slot out from
// under the cursor.
const completed = useCompletedShows(
    () => props.upNext,
    () => preferences.value.up_next_keep_finished,
);

// Both card kinds share the grid; the freshly finished ones lead, matching
// the section's sort by the most recent play.
const upNextRows = computed(() => [
    ...completed.value.map((show) => ({ key: `completed-${show.show.type}-${show.show.ref}`, completed: show, entry: null })),
    ...props.upNext.map((entry) => ({ key: `${entry.show.type}-${entry.show.ref}`, completed: null, entry })),
]);

// Up Next stays scannable: ~10 cards by default, rounded up to a whole row,
// the rest behind a toggle.
const UP_NEXT_LIMIT = useShelfLimit(10);
const upNextExpanded = ref(false);
const visibleUpNext = computed(() => (upNextExpanded.value ? upNextRows.value : upNextRows.value.slice(0, UP_NEXT_LIMIT.value)));
const hiddenUpNextCount = computed(() => upNextRows.value.length - UP_NEXT_LIMIT.value);

// Games and reading types are all "in progress" flat types, but they are
// different activities — playing and reading each get their own section
// (books, manga and comics share the reading shelf).
const playingGames = computed(() => props.playing.filter((entry) => !isPositionCounter(entry.media)));
const readingTitles = computed(() => props.playing.filter((entry) => isPositionCounter(entry.media)));

// Every configured root type has a zeroed bucket from the backend, so a new
// account sees the same set of tiles as an active one.
const statKinds = computed(() => inRegistryOrder(Object.keys(props.stats)));
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.dashboard')" />

        <!-- The page is a list of full-width bands; each one holds its own
             container, so a band's background reaches both screen edges. -->
        <div class="flex w-full flex-1 flex-col">
            <PageSection>
                <!-- The stat band names itself like every other section, at the
                     same scale — the page has no separate page-level title. -->
                <SectionHeading
                    :title="trans('dashboard.your_stats')"
                    :subtitle="trans('dashboard.your_viewing_gaming_and_reading_activity')"
                    compact-on-mobile
                >
                    <template #icon><ChartNoAxesColumnIncreasing class="xs:size-6 size-5" /></template>
                    <SectionMoreLink :href="route('statistics')" :label="trans('statistics.title')" placement="heading" />
                </SectionHeading>

                <!-- Optional tiles follow configured modules, including for a
                     fresh account whose counters are all still zero, in the
                     registry's order. -->
                <ScrollRow>
                    <!-- Three to a view from `lg`, two from `sm`, one on a
                         phone: each basis is the row split that many ways,
                         less the gaps in between. The phone keeps 3.5rem
                         back so a sliver of the next tile always shows —
                         there are no arrows down there to say the row goes
                         on. `grow` covers the accounts that track fewer
                         types than the view holds: those fill the width
                         instead of leaving a hole. -->
                    <StatTile
                        v-for="kind in statKinds"
                        :key="kind"
                        :kind="kind"
                        :stats="stats[kind]"
                        class="shrink-0 grow basis-[calc(100%-3.5rem)] snap-start sm:basis-[calc((100%-1rem)/2)] lg:basis-[calc((100%-2rem)/3)]"
                    />
                </ScrollRow>
                <SectionMoreLink :href="route('statistics')" :label="trans('statistics.title')" placement="below" />
            </PageSection>

            <PageSection tone="subtle">
                <SectionHeading :title="trans('dashboard.up_next_to_watch')" :subtitle="trans('dashboard.continue_watching_the_shows_you_have')">
                    <template #icon><ListVideo class="size-6" /></template>
                    <SectionMoreLink :href="route('users.progress', { user: user.username })" :label="trans('common.progress')" placement="heading" />
                </SectionHeading>

                <EmptyState v-if="upNextRows.length === 0" :message="trans('dashboard.nothing_here_yet_mark_an_episode')">
                    <Link
                        :href="route('search')"
                        class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center gap-2 rounded-full px-6 py-3 font-semibold transition-colors"
                    >
                        <Search class="size-5" />
                        {{ trans('common.search_movies_and_tv_shows') }}
                    </Link>
                </EmptyState>
                <template v-else>
                    <MediaGrid>
                        <template v-for="row in visibleUpNext" :key="row.key">
                            <CompletedShowCard v-if="row.completed" :entry="row.completed" />
                            <UpNextCard v-else-if="row.entry" :entry="row.entry" />
                        </template>
                    </MediaGrid>
                    <ShowMoreToggle v-if="hiddenUpNextCount > 0" v-model="upNextExpanded" :hidden-count="hiddenUpNextCount" />
                </template>
                <SectionMoreLink :href="route('users.progress', { user: user.username })" :label="trans('common.progress')" placement="below" />
            </PageSection>

            <!-- Games and books live in their own sections — they are not
                 "up next", they are being played / read right now. Every band
                 that holds media shares one tone; the calendar below is not a
                 shelf of titles and keeps the page background. -->
            <PageSection v-if="playingGames.length > 0" tone="subtle">
                <SectionHeading :title="trans('dashboard.currently_playing')" :subtitle="trans('dashboard.your_games_in_progress')">
                    <template #icon><Gamepad2 class="size-6" /></template>
                    <SectionMoreLink :href="route('users.progress', { user: user.username })" :label="trans('common.progress')" placement="heading" />
                </SectionHeading>
                <MediaGrid>
                    <PlayingCard v-for="entry in playingGames" :key="`playing-${entry.media.type}-${entry.media.ref}`" :entry="entry" />
                </MediaGrid>
                <SectionMoreLink :href="route('users.progress', { user: user.username })" :label="trans('common.progress')" placement="below" />
            </PageSection>

            <PageSection v-if="readingTitles.length > 0" tone="subtle">
                <SectionHeading :title="trans('dashboard.currently_reading')" :subtitle="trans('dashboard.your_books_manga_and_comics_in')">
                    <template #icon><BookOpenText class="size-6" /></template>
                    <SectionMoreLink :href="route('users.progress', { user: user.username })" :label="trans('common.progress')" placement="heading" />
                </SectionHeading>
                <MediaGrid>
                    <PlayingCard v-for="entry in readingTitles" :key="`reading-${entry.media.type}-${entry.media.ref}`" :entry="entry" />
                </MediaGrid>
                <SectionMoreLink :href="route('users.progress', { user: user.username })" :label="trans('common.progress')" placement="below" />
            </PageSection>

            <PageSection>
                <SectionHeading
                    :group="groupOf(CALENDAR)"
                    :title="trans('common.calendar')"
                    :subtitle="trans('common.upcoming_releases_from_the_titles_you')"
                >
                    <template #icon><CalendarDays class="size-6" /></template>
                    <SectionMoreLink :href="route('calendar')" :label="trans('common.calendar')" placement="heading" />
                </SectionHeading>
                <CalendarSection :days="calendar" />
                <SectionMoreLink :href="route('calendar')" :label="trans('common.calendar')" placement="below" />
            </PageSection>
        </div>
    </AppLayout>
</template>
