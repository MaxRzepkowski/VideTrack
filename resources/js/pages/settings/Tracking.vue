<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import ViewOptionsCard from '@/components/settings/tracking/ViewOptionsCard.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { usePreferences } from '@/composables/usePreferences';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { TRACKING_TABS, trackingSources, type TrackingTab } from '@/lib/tracking';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Bell, CalendarDays, Users, type LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
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
const releaseModes = computed(() => [
    { value: 'both', label: trans('settings.premiere_and_availability') },
    { value: 'cinema', label: trans('settings.premiere_only') },
    { value: 'digital', label: trans('settings.availability_only') },
]);

// Labels in computeds: a page opened straight from its address renders before
// the translations arrive, and a label read once would stay a bare key.

// The kinds of events that create notifications about the titles themselves.
const notificationEvents = computed(
    () =>
        [
            { field: 'notify_episodes', label: trans('settings.new_episodes'), description: trans('settings.an_episode_of_a_tracked_show') },
            { field: 'notify_premieres', label: trans('settings.premieres'), description: trans('settings.a_tracked_movie_game_or_book') },
            {
                field: 'notify_date_changes',
                label: trans('settings.release_date_changes'),
                description: trans('settings.a_tracked_title_moves_its_release'),
            },
            {
                field: 'notify_show_status',
                label: trans('common.show_status'),
                description: trans('settings.a_tracked_show_gets_a_new'),
            },
        ] as const,
);

// …and the ones about what the people, companies and collections you follow add.
const followedEvents = computed(
    () =>
        [
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
        ] as const,
);

const viewColumns = computed(
    () =>
        [
            { field: 'calendar', label: trans('common.calendar') },
            { field: 'countdown', label: trans('common.countdowns') },
        ] as const,
);

const sources = computed(trackingSources);

const VIEWS = ['calendar', 'countdown'] as const;

const TAB_ICONS: Record<TrackingTab, LucideIcon> = { views: CalendarDays, notifications: Bell, creators: Users };

const tabLabels = computed<Record<TrackingTab, string>>(() => ({
    views: trans('settings.calendar_and_countdowns'),
    notifications: trans('common.notifications'),
    creators: trans('settings.followed_creators'),
}));

// The open tab lives in the address (`?tab=notifications`), so another page can link straight to it.
const page = usePage();
const requested = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
const tab = ref<TrackingTab>(TRACKING_TABS.find((candidate) => candidate === requested) ?? 'views');

// Arrow keys move along the tabs and Home/End jump to either end, the open tab
// being the row's one stop on the TrackingTab key.
function onTabKey(event: KeyboardEvent, from: TrackingTab): void {
    const index = TRACKING_TABS.indexOf(from);
    const moves: Record<string, number> = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: TRACKING_TABS.length - 1 };
    const target = moves[event.key];
    // Past either end the row wraps round.
    const next = target === undefined ? undefined : TRACKING_TABS[(target + TRACKING_TABS.length) % TRACKING_TABS.length];

    if (next === undefined) {
        return;
    }

    event.preventDefault();
    openTab(next);
    document.getElementById(`tracking-tab-${next}`)?.focus();
}

function openTab(next: TrackingTab): void {
    tab.value = next;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', next);
    // Same history entry and Inertia state: switching a tab is not a visit.
    window.history.replaceState(window.history.state, '', url);
}

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
                <HeadingSmall :title="trans('common.tracking')" :description="trans('settings.what_feeds_your_calendar_countdowns_and')" />

                <div role="tablist" class="scroll-subtle -mx-4 flex flex-nowrap items-center gap-1 overflow-x-auto px-4 md:mx-0 md:px-0">
                    <button
                        v-for="name in TRACKING_TABS"
                        :id="`tracking-tab-${name}`"
                        :key="name"
                        type="button"
                        role="tab"
                        :aria-selected="tab === name"
                        :aria-controls="`tracking-panel-${name}`"
                        :tabindex="tab === name ? 0 : -1"
                        class="flex shrink-0 items-center gap-2 rounded-lg px-4 py-2.5 text-base font-semibold transition-colors duration-150"
                        :class="tab === name ? 'bg-brand text-on-accent' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        @click="openTab(name)"
                        @keydown="onTabKey($event, name)"
                    >
                        <component :is="TAB_ICONS[name]" class="size-4" aria-hidden="true" />
                        {{ tabLabels[name] }}
                    </button>
                </div>

                <fieldset :disabled="form.processing" class="min-w-0">
                    <div v-show="tab === 'views'" id="tracking-panel-views" role="tabpanel" aria-labelledby="tracking-tab-views" class="space-y-8">
                        <SettingsCard
                            :title="trans('settings.sources')"
                            :description="trans('settings.which_titles_the_calendar_and_countdowns_show')"
                        >
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr>
                                        <th class="pb-3 text-left">
                                            <span class="sr-only">{{ trans('settings.sources') }}</span>
                                        </th>
                                        <th
                                            v-for="column in viewColumns"
                                            :key="column.field"
                                            scope="col"
                                            class="text-ink-muted w-24 pb-3 text-center text-sm font-medium sm:w-32"
                                        >
                                            {{ column.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="source in sources" :key="source.key" class="border-line-subtle border-t">
                                        <th scope="row" class="py-3.5 pr-4 text-left font-normal">
                                            <div class="text-ink text-base font-semibold">{{ source.label }}</div>
                                            <div class="text-ink-muted mt-0.5 text-sm">{{ source.description }}</div>
                                        </th>
                                        <td v-for="column in viewColumns" :key="column.field" class="py-3.5 text-center">
                                            <input
                                                v-model="form[source[column.field]]"
                                                type="checkbox"
                                                :aria-label="inGroup(column.label, source.label)"
                                                class="border-line-subtle bg-surface-base accent-brand size-5 rounded border align-middle"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </SettingsCard>

                        <ViewOptionsCard
                            v-for="view in VIEWS"
                            :key="view"
                            v-model:game-platforms="form[`${view}_game_platforms`]"
                            v-model:release-mode="form[`${view}_release_mode`]"
                            v-model:episode-image="form[`${view}_episode_image`]"
                            :view="view"
                            :release-modes="releaseModes"
                        />
                    </div>

                    <div
                        v-show="tab === 'notifications'"
                        id="tracking-panel-notifications"
                        role="tabpanel"
                        aria-labelledby="tracking-tab-notifications"
                        class="space-y-8"
                    >
                        <SettingsCard :title="trans('settings.sources')" :description="trans('settings.which_titles_can_notify_you')">
                            <SettingsRow
                                v-for="source in sources"
                                :key="source.key"
                                :title="source.label"
                                :description="source.description"
                                layout="inline"
                            >
                                <ToggleSwitch v-model="form[source.notifications]" :label="inGroup(trans('common.notifications'), source.label)" />
                            </SettingsRow>
                        </SettingsCard>

                        <SettingsCard
                            :title="trans('settings.notification_events')"
                            :description="trans('settings.which_kinds_of_events_create_notifications')"
                        >
                            <SettingsRow
                                v-for="event in notificationEvents"
                                :key="event.field"
                                :title="event.label"
                                :description="event.description"
                                layout="inline"
                            >
                                <ToggleSwitch v-model="form[event.field]" :label="event.label" />
                            </SettingsRow>
                            <SettingsRow
                                :title="trans('settings.film_releases')"
                                :description="trans('settings.which_of_a_films_dates_pings')"
                                for="notifications-release-mode"
                            >
                                <select
                                    id="notifications-release-mode"
                                    v-model="form.notifications_release_mode"
                                    :class="[SETTINGS_FIELD_CLASS, 'block w-full']"
                                >
                                    <option v-for="mode in releaseModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                </select>
                            </SettingsRow>
                        </SettingsCard>

                        <SettingsCard :title="trans('settings.followed_creators')">
                            <SettingsRow
                                v-for="event in followedEvents"
                                :key="event.field"
                                :title="event.label"
                                :description="event.description"
                                layout="inline"
                            >
                                <ToggleSwitch v-model="form[event.field]" :label="event.label" />
                            </SettingsRow>
                        </SettingsCard>
                    </div>

                    <div
                        v-show="tab === 'creators'"
                        id="tracking-panel-creators"
                        role="tabpanel"
                        aria-labelledby="tracking-tab-creators"
                        class="space-y-8"
                    >
                        <SettingsCard :description="trans('settings.what_counts_as_a_new_title_from')">
                            <SettingsRow
                                :title="trans('settings.followed_people_cover')"
                                :description="trans('settings.which_departments_of_a_followed_person')"
                                for="person-follow-scope"
                            >
                                <select id="person-follow-scope" v-model="form.person_follow_scope" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                    <option value="known_for">{{ trans('settings.their_main_field_acting_for_actors') }}</option>
                                    <option value="all">{{ trans('settings.acting_directing_and_writing') }}</option>
                                </select>
                            </SettingsRow>
                            <SettingsRow
                                :title="trans('settings.followed_companies_cover')"
                                :description="trans('settings.which_games_of_a_followed_studio')"
                                for="company-follow-scope"
                            >
                                <select id="company-follow-scope" v-model="form.company_follow_scope" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                    <option value="both">{{ trans('settings.games_it_developed_or_published') }}</option>
                                    <option value="developed">{{ trans('settings.only_games_it_developed') }}</option>
                                    <option value="published">{{ trans('settings.only_games_it_published') }}</option>
                                </select>
                            </SettingsRow>
                        </SettingsCard>
                    </div>
                </fieldset>
                <SettingsSaveBar :dirty="form.isDirty" :processing="form.processing" @save="submit" @discard="discard" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
