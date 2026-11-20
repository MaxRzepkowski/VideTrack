<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import TrackingGrid, { type GridRow } from '@/components/settings/tracking/TrackingGrid.vue';
import TrackingTypeRow from '@/components/settings/tracking/TrackingTypeRow.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { usePreferences } from '@/composables/usePreferences';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    defaultSources,
    offeredOwnSources,
    optionSettings,
    TAB_SURFACES,
    toggledSource,
    TRACKING_TABS,
    trackingSources,
    withOwnSources,
    type TrackingTab,
    type OwnSources,
    type TypeTab,
} from '@/lib/tracking';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { Surface, TrackingSource, TypePanel } from '@/types/generated';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Bell, CalendarDays, Users, type LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    /** Every kind of title, with the settings only it has. */
    types: TypePanel[];
}>();

// The page's settings as they stand — the server fills in every default.
const preferences = usePreferences().value;

const form = useForm({
    // What the kinds of title declare, held apart since the page cannot name
    // them; sent alongside the rest.
    options: optionSettings(props.types, { ...preferences }),
    type_sources: offeredOwnSources(props.types, preferences.type_sources),
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
    notify_premieres: preferences.notify_premieres,
    notify_date_changes: preferences.notify_date_changes,
    notify_person_titles: preferences.notify_person_titles,
    notify_company_titles: preferences.notify_company_titles,
    notify_collection_items: preferences.notify_collection_items,
    person_follow_scope: preferences.person_follow_scope,
    company_follow_scope: preferences.company_follow_scope,
});

// Labels in computeds: a page opened straight from its address renders before
// the translations arrive, and a label read once would stay a bare key.

// What any title's notifications can be about; a kind of title adds its own.
const notificationEvents = computed(
    () =>
        [
            { field: 'notify_premieres', label: trans('settings.premieres'), description: trans('settings.a_tracked_movie_game_or_book') },
            {
                field: 'notify_date_changes',
                label: trans('settings.release_date_changes'),
                description: trans('settings.a_tracked_title_moves_its_release'),
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

const sources = computed(trackingSources);

const defaults = computed(() => defaultSources(form));

const defaultViewRows = computed<GridRow[]>(() =>
    sources.value.map((source) => ({
        key: source.key,
        label: source.label,
        description: source.description,
        cells: Object.fromEntries(TAB_SURFACES.views.map((surface) => [surface, { checked: form[source.preferences[surface]] === true }])),
    })),
);

function toggleDefault(key: string, surface: Surface): void {
    const preference = sources.value.find((source) => source.key === key)?.preferences[surface];

    if (preference !== undefined) {
        form[preference] = !form[preference];
    }
}

/** A setting the page holds by its name: one a kind of title declares, else one of the page's own. */
function readSetting(preference: string): unknown {
    const settings: Readonly<Record<string, unknown>> = form.data();

    return preference in form.options ? form.options[preference] : settings[preference];
}

function setSetting(preference: string, value: boolean | string): void {
    form.options[preference] = value;
}

/** A kind of title's own sources; with none left in any tab, it follows the defaults everywhere. */
function setOwnSources(type: string, own: OwnSources): void {
    if (Object.keys(own).length === 0) {
        delete form.type_sources[type];
    } else {
        form.type_sources[type] = own;
    }
}

function switchOwnSources(type: string, tab: TypeTab, on: boolean): void {
    setOwnSources(type, withOwnSources(form.type_sources[type], tab, on, defaults.value));
}

function toggleTypeSource(type: string, surface: Surface, row: TrackingSource): void {
    const own = form.type_sources[type] ?? {};

    setOwnSources(type, { ...own, [surface]: toggledSource(own[surface] ?? [], row) });
}

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

    form.transform(({ options, ...settings }) => ({ ...settings, ...options })).patch(route('settings.preferences.update'), {
        preserveScroll: true,
        onSuccess: () => toast.success(trans('settings.saved_2')),
        // The page has no field to show a refusal under: say it, rather than leave the bar waiting.
        onError: () => toast.error(trans('common.something_went_wrong_please_try_again')),
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
                            :title="trans('settings.by_default')"
                            :description="trans('settings.which_titles_the_calendar_and_countdowns_show')"
                        >
                            <TrackingGrid
                                id="tracking-defaults"
                                :columns="TAB_SURFACES.views"
                                :rows="defaultViewRows"
                                :heading="trans('settings.sources')"
                                @toggle="toggleDefault"
                            />
                        </SettingsCard>

                        <SettingsCard :title="trans('settings.types')" :description="trans('settings.own_sources_and_settings_of_each_type')">
                            <TrackingTypeRow
                                v-for="panel in types"
                                :key="panel.type"
                                :panel
                                tab="views"
                                :read="readSetting"
                                :own="form.type_sources[panel.type]"
                                :defaults
                                @set="setSetting"
                                @own="switchOwnSources(panel.type, 'views', $event)"
                                @source="(surface, row) => toggleTypeSource(panel.type, surface, row)"
                            />
                        </SettingsCard>
                    </div>

                    <div
                        v-show="tab === 'notifications'"
                        id="tracking-panel-notifications"
                        role="tabpanel"
                        aria-labelledby="tracking-tab-notifications"
                        class="space-y-8"
                    >
                        <SettingsCard :title="trans('settings.by_default')" :description="trans('settings.which_titles_can_notify_you_and')">
                            <SettingsRow
                                v-for="source in sources"
                                :key="source.key"
                                :title="source.label"
                                :description="source.description"
                                layout="inline"
                            >
                                <ToggleSwitch
                                    v-model="form[source.preferences.notifications]"
                                    :label="inGroup(trans('common.notifications'), source.label)"
                                />
                            </SettingsRow>
                            <SettingsRow
                                v-for="event in notificationEvents"
                                :key="event.field"
                                :title="event.label"
                                :description="event.description"
                                layout="inline"
                            >
                                <ToggleSwitch v-model="form[event.field]" :label="event.label" />
                            </SettingsRow>
                        </SettingsCard>

                        <SettingsCard :title="trans('settings.types')" :description="trans('settings.what_each_type_notifies_about')">
                            <TrackingTypeRow
                                v-for="panel in types"
                                :key="panel.type"
                                :panel
                                tab="notifications"
                                :read="readSetting"
                                :own="form.type_sources[panel.type]"
                                :defaults
                                @set="setSetting"
                                @own="switchOwnSources(panel.type, 'notifications', $event)"
                                @source="(surface, row) => toggleTypeSource(panel.type, surface, row)"
                            />
                        </SettingsCard>

                        <SettingsCard :title="trans('settings.followed_creators')" :description="trans('settings.each_also_needs_the_creators_row')">
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
