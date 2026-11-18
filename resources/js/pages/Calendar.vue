<script setup lang="ts">
import MonthGrid from '@/components/calendar/MonthGrid.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { pageLocale } from '@/lib/pageContext';
import { ICON_BUTTON_CLASS, SELECT_CLASS } from '@/lib/ui';
import { webcalUrl } from '@/lib/urls';
import type { CalendarMonth } from '@/types/media';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { CalendarDays, CalendarPlus, ChevronLeft, ChevronRight, Download, Rss } from '@lucide/vue';
import { computed, onBeforeUnmount } from 'vue';

const props = defineProps<{
    /** The 1st of the month on show — what prev / next / the date jump move. */
    anchor: string;
    today: string;
    prev: string;
    next: string;
    month: CalendarMonth;
    feedUrl: string;
    rssUrl: string;
}>();

const locale = pageLocale();

// Server dates are plain Y-m-d; reading them in UTC keeps a calendar day a
// calendar day, whatever zone the browser sits in.
const monthLabel = computed<string>(() =>
    new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${props.month.month}T00:00:00Z`)),
);

// Typing a date into the field changes it segment by segment — a year on its
// way to 2027 passes through 0002 and 0020 — and every step that makes a
// valid date fires `change`. So the jump waits for the typing to settle, and
// goes at once on Enter or when the field is left.
const JUMP_DELAY_MS = 700;

let pendingDate: string | null = null;
let jumpTimer: ReturnType<typeof setTimeout> | undefined;

function jumpNow(): void {
    clearTimeout(jumpTimer);

    const date = pendingDate;

    pendingDate = null;

    if (date) {
        router.get(route('calendar', { date }));
    }
}

function jumpSoon(event: Event): void {
    pendingDate = (event.target as HTMLInputElement).value || null;
    clearTimeout(jumpTimer);
    jumpTimer = setTimeout(jumpNow, JUMP_DELAY_MS);
}

onBeforeUnmount(() => clearTimeout(jumpTimer));
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.calendar')" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <PageHeading :title="trans('common.calendar')" :subtitle="trans('common.upcoming_releases_from_the_titles_you')">
                        <template #icon><CalendarDays class="size-6" /></template>
                    </PageHeading>

                    <div class="flex items-center gap-3">
                        <a
                            :href="webcalUrl(feedUrl)"
                            class="bg-brand hover:bg-brand/90 text-on-accent inline-flex h-10 items-center gap-1.5 rounded-lg px-4 text-base font-semibold transition-colors"
                        >
                            <CalendarPlus class="size-4" />
                            {{ trans('common.subscribe') }}
                        </a>
                        <a
                            v-tooltip="trans('common.download_ics')"
                            :aria-label="trans('common.download_ics')"
                            :href="feedUrl"
                            :class="ICON_BUTTON_CLASS"
                        >
                            <Download class="size-4" />
                        </a>
                        <a v-tooltip="trans('common.rss_feed')" :aria-label="trans('common.rss_feed')" :href="rssUrl" :class="ICON_BUTTON_CLASS">
                            <Rss class="size-4" />
                        </a>
                    </div>
                </div>
            </div>
        </PageMasthead>

        <!-- Date navigation follows the shared listing-page control strip. The
             calendar itself keeps its wider container below. -->
        <PageToolbar variant="band">
            <template #search>
                <input
                    v-tooltip="trans('calendar.jump_to_date')"
                    data-toolbar-control
                    type="date"
                    :value="anchor"
                    :class="SELECT_CLASS"
                    @change="jumpSoon"
                    @keydown.enter="jumpNow"
                    @blur="jumpNow"
                />
            </template>

            <template #view>
                <div class="flex items-center gap-2">
                    <Link
                        v-tooltip="trans('calendar.previous_month')"
                        data-toolbar-control
                        :href="route('calendar', { date: prev })"
                        :class="ICON_BUTTON_CLASS"
                    >
                        <ChevronLeft class="size-4" />
                    </Link>
                    <Link
                        data-toolbar-control
                        :href="route('calendar', { date: today })"
                        class="border-line bg-surface text-ink hover:border-line-strong flex h-10 items-center rounded-lg border px-4 text-base font-semibold transition-colors duration-150"
                    >
                        {{ trans('common.today') }}
                    </Link>
                    <Link
                        v-tooltip="trans('calendar.next_month')"
                        data-toolbar-control
                        :href="route('calendar', { date: next })"
                        :class="ICON_BUTTON_CLASS"
                    >
                        <ChevronRight class="size-4" />
                    </Link>
                </div>
            </template>
        </PageToolbar>

        <div class="max-w-calendar mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <MonthGrid :grid="month" :today="today" :label="monthLabel" />
        </div>
    </AppLayout>
</template>
