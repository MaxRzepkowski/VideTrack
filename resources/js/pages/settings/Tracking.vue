<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { usePreferences } from '@/composables/usePreferences';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

// The page's settings as they stand — the server fills in every default.
const preferences = usePreferences().value;

const form = useForm({
    calendar_active: preferences.calendar_active,
    countdown_active: preferences.countdown_active,
    notifications_active: preferences.notifications_active,
    calendar_watchlist: preferences.calendar_watchlist,
    countdown_watchlist: preferences.countdown_watchlist,
    notifications_watchlist: preferences.notifications_watchlist,
    calendar_on_hold: preferences.calendar_on_hold,
    countdown_on_hold: preferences.countdown_on_hold,
    notifications_on_hold: preferences.notifications_on_hold,
    calendar_dropped: preferences.calendar_dropped,
    countdown_dropped: preferences.countdown_dropped,
    notifications_dropped: preferences.notifications_dropped,
    calendar_followed: preferences.calendar_followed,
    countdown_followed: preferences.countdown_followed,
    notifications_followed: preferences.notifications_followed,
    calendar_creators: preferences.calendar_creators,
    countdown_creators: preferences.countdown_creators,
    notifications_creators: preferences.notifications_creators,
    calendar_game_platforms: preferences.calendar_game_platforms,
    countdown_game_platforms: preferences.countdown_game_platforms,
    calendar_episode_image: preferences.calendar_episode_image,
    countdown_episode_image: preferences.countdown_episode_image,
    notify_episodes: preferences.notify_episodes,
    notify_premieres: preferences.notify_premieres,
    notify_date_changes: preferences.notify_date_changes,
    notify_show_status: preferences.notify_show_status,
    notify_person_titles: preferences.notify_person_titles,
    notify_company_titles: preferences.notify_company_titles,
    notify_collection_items: preferences.notify_collection_items,
    person_follow_scope: preferences.person_follow_scope,
    company_follow_scope: preferences.company_follow_scope,
    calendar_release_mode: preferences.calendar_release_mode,
    countdown_release_mode: preferences.countdown_release_mode,
    notifications_release_mode: preferences.notifications_release_mode,
});

// A film has up to two moments worth announcing, and people want different
// ones: a cinemagoer the premiere, someone who never goes the day it lands at
// home. "Cinema" announces the premiere only.
const releaseModes = [
    { value: 'both', label: trans('settings.premiere_and_availability') },
    { value: 'cinema', label: trans('settings.premiere_only') },
    { value: 'digital', label: trans('settings.availability_only') },
] as const;

// The kinds of events that create notifications.
const notificationEvents = [
    { field: 'notify_episodes', label: trans('settings.new_episodes'), description: trans('settings.an_episode_of_a_tracked_show') },
    { field: 'notify_premieres', label: trans('settings.premieres'), description: trans('settings.a_tracked_movie_game_or_book') },
    { field: 'notify_date_changes', label: trans('settings.release_date_changes'), description: trans('settings.a_tracked_title_moves_its_release') },
    {
        field: 'notify_show_status',
        label: trans('common.show_status'),
        description: trans('settings.a_tracked_show_gets_a_new'),
    },
    {
        field: 'notify_person_titles',
        label: trans('settings.new_titles_from_followed_people'),
        description: trans('settings.a_person_you_follow_gets_a'),
    },
    {
        field: 'notify_company_titles',
        label: trans('settings.new_titles_from_followed_companies'),
        description: trans('settings.a_studio_publisher_or_network_you'),
    },
    {
        field: 'notify_collection_items',
        label: trans('settings.new_parts_of_followed_collections'),
        description: trans('settings.a_collection_you_follow_with_the'),
    },
] as const;

const matrixColumns = [
    { field: 'calendar', label: trans('common.calendar') },
    { field: 'countdown', label: trans('common.countdowns') },
    { field: 'notifications', label: trans('common.notifications') },
] as const;

const sources = [
    {
        key: 'active',
        // The row holds anything with a play on it. A series is "in the
        // middle", a film is simply seen — one word has to cover both, and
        // the app already calls that place your history.
        label: trans('settings.in_your_history'),
        description: trans('settings.series_and_games_you_are_in'),
        calendar: 'calendar_active',
        countdown: 'countdown_active',
        notifications: 'notifications_active',
    },
    {
        key: 'watchlist',
        label: trans('common.backlog'),
        description: trans('settings.titles_you_plan_to_get_to'),
        calendar: 'calendar_watchlist',
        countdown: 'countdown_watchlist',
        notifications: 'notifications_watchlist',
    },
    {
        key: 'on_hold',
        label: trans('settings.on_hold_titles'),
        description: trans('settings.paused_titles_a_fresh_season_is'),
        calendar: 'calendar_on_hold',
        countdown: 'countdown_on_hold',
        notifications: 'notifications_on_hold',
    },
    {
        key: 'dropped',
        label: trans('settings.dropped_titles'),
        description: trans('settings.you_gave_up_on_it_but'),
        calendar: 'calendar_dropped',
        countdown: 'countdown_dropped',
        notifications: 'notifications_dropped',
    },
    {
        key: 'followed',
        label: trans('common.followed_manually'),
        description: trans('settings.titles_you_rang_the_bell_on'),
        calendar: 'calendar_followed',
        countdown: 'countdown_followed',
        notifications: 'notifications_followed',
    },
    {
        key: 'creators',
        label: trans('settings.new_titles_from_followed_creators'),
        description: trans('settings.titles_the_bell_picked_up_from'),
        calendar: 'calendar_creators',
        countdown: 'countdown_creators',
        notifications: 'notifications_creators',
    },
] as const;

useUnsavedChanges(() => form.isDirty);

function discard(): void {
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (!form.isDirty || form.processing) {
        return;
    }

    form.patch(route('settings.preferences.update'), {
        preserveScroll: true,
        onSuccess: () => toast.success(trans('settings.saved_2')),
    });
}

/** A switch's name for a screen reader: the setting within its group. */
const inGroup = (group: string, setting: string): string => trans('settings.setting_in_group', { group, setting });
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.tracking')" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="submit">
                <fieldset :disabled="form.processing" class="min-w-0 space-y-6">
                    <HeadingSmall :title="trans('common.tracking')" :description="trans('settings.what_feeds_your_calendar_countdowns_and')" />

                    <div :class="SETTINGS_CARD_CLASS">
                        <div class="text-ink text-base font-semibold">{{ trans('settings.sources') }}</div>
                        <p class="text-ink-muted mt-0.5 text-sm">
                            {{ trans('settings.each_group_of_titles_can_feed') }}
                        </p>

                        <!-- The settings column is narrow (max-w-xl), so instead of a
                         cramped 4-column grid every source row carries its own
                         captioned toggle trio below the description. -->
                        <div class="divide-line-subtle mt-2 divide-y">
                            <div v-for="source in sources" :key="source.key" class="py-4">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ source.label }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ source.description }}</div>
                                </div>
                                <div class="mt-3 flex flex-wrap items-start gap-x-10 gap-y-3">
                                    <div v-for="column in matrixColumns" :key="column.field" class="flex flex-col items-center gap-1.5">
                                        <span class="text-ink-muted text-sm font-medium">{{ column.label }}</span>
                                        <ToggleSwitch v-model="form[source[column.field]]" :label="inGroup(column.label, source.label)" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <div class="text-ink text-base font-semibold">{{ trans('settings.notification_events') }}</div>
                        <p class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.which_kinds_of_events_create_notifications') }}</p>

                        <div class="mt-4 flex flex-col gap-4">
                            <div v-for="event in notificationEvents" :key="event.field" class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ event.label }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ event.description }}</div>
                                </div>
                                <ToggleSwitch v-model="form[event.field]" :label="event.label" />
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.film_releases') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.which_of_a_films_dates_pings') }}</div>
                                </div>
                                <select
                                    v-model="form.notifications_release_mode"
                                    :aria-label="inGroup(trans('common.notifications'), trans('settings.film_releases'))"
                                    :class="[SETTINGS_FIELD_CLASS, 'shrink-0']"
                                >
                                    <option v-for="mode in releaseModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-2">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.followed_people_cover') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">
                                        {{ trans('settings.which_departments_of_a_followed_person') }}
                                    </div>
                                </div>
                                <select
                                    v-model="form.person_follow_scope"
                                    :aria-label="trans('settings.followed_people_cover')"
                                    :class="[SETTINGS_FIELD_CLASS, 'block w-full']"
                                >
                                    <option value="known_for">{{ trans('settings.their_main_field_acting_for_actors') }}</option>
                                    <option value="all">{{ trans('settings.acting_directing_and_writing') }}</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-2">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.followed_companies_cover') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">
                                        {{ trans('settings.which_games_of_a_followed_studio') }}
                                    </div>
                                </div>
                                <select
                                    v-model="form.company_follow_scope"
                                    :aria-label="trans('settings.followed_companies_cover')"
                                    :class="[SETTINGS_FIELD_CLASS, 'block w-full']"
                                >
                                    <option value="both">{{ trans('settings.games_it_developed_or_published') }}</option>
                                    <option value="developed">{{ trans('settings.only_games_it_developed') }}</option>
                                    <option value="published">{{ trans('settings.only_games_it_published') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <HeadingSmall :title="trans('settings.display_options')" />

                    <div :class="SETTINGS_CARD_CLASS">
                        <div class="text-ink text-base font-semibold">{{ trans('common.calendar') }}</div>
                        <div class="mt-4 flex flex-col gap-4">
                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.game_platform_releases') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">
                                        {{ trans('settings.show_every_platform_premiere_of_your') }}
                                    </div>
                                </div>
                                <ToggleSwitch
                                    v-model="form.calendar_game_platforms"
                                    :label="inGroup(trans('common.calendar'), trans('settings.game_platform_releases'))"
                                />
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.film_releases') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.which_of_a_films_dates_the') }}</div>
                                </div>
                                <select
                                    v-model="form.calendar_release_mode"
                                    :aria-label="inGroup(trans('common.calendar'), trans('settings.film_releases'))"
                                    :class="[SETTINGS_FIELD_CLASS, 'shrink-0']"
                                >
                                    <option v-for="mode in releaseModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                </select>
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.episode_thumbnails') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.which_image_episodes_use_in_the') }}</div>
                                </div>
                                <select
                                    v-model="form.calendar_episode_image"
                                    :aria-label="inGroup(trans('common.calendar'), trans('settings.episode_thumbnails'))"
                                    :class="[SETTINGS_FIELD_CLASS, 'shrink-0']"
                                >
                                    <option value="backdrop">{{ trans('settings.show_backdrop') }}</option>
                                    <option value="still">{{ trans('settings.episode_still') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <div class="text-ink text-base font-semibold">{{ trans('common.countdowns') }}</div>
                        <div class="mt-4 flex flex-col gap-4">
                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.game_platform_releases') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">
                                        {{ trans('settings.count_down_every_platform_premiere_of') }}
                                    </div>
                                </div>
                                <ToggleSwitch
                                    v-model="form.countdown_game_platforms"
                                    :label="inGroup(trans('common.countdowns'), trans('settings.game_platform_releases'))"
                                />
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.film_releases') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.which_of_a_films_dates_you') }}</div>
                                </div>
                                <select
                                    v-model="form.countdown_release_mode"
                                    :aria-label="inGroup(trans('common.countdowns'), trans('settings.film_releases'))"
                                    :class="[SETTINGS_FIELD_CLASS, 'shrink-0']"
                                >
                                    <option v-for="mode in releaseModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                </select>
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="min-w-0">
                                    <div class="text-ink text-sm font-semibold">{{ trans('settings.episode_thumbnails') }}</div>
                                    <div class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.which_image_episodes_use_in_countdowns') }}</div>
                                </div>
                                <select
                                    v-model="form.countdown_episode_image"
                                    :aria-label="inGroup(trans('common.countdowns'), trans('settings.episode_thumbnails'))"
                                    :class="[SETTINGS_FIELD_CLASS, 'shrink-0']"
                                >
                                    <option value="backdrop">{{ trans('settings.show_backdrop') }}</option>
                                    <option value="still">{{ trans('settings.episode_still') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <SettingsSaveBar :dirty="form.isDirty" :processing="form.processing" @save="submit" @discard="discard" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
