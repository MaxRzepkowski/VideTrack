<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { usePreferences } from '@/composables/usePreferences';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { TimeFormat, UpNextSort, UpNextTarget, WeekStart } from '@/types/generated';
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

const props = defineProps<{
    regions: Record<string, string>;
    defaultRegion: string;
}>();

const preferences = usePreferences().value;

const form = useForm({
    spoiler_protection: preferences.spoiler_protection,
    // null = automatic, the select's empty option.
    timezone: preferences.timezone ?? '',
    time_format: preferences.time_format,
    week_start: preferences.week_start,
    region: preferences.region ?? '',
    up_next_target: preferences.up_next_target,
    up_next_keep_finished: preferences.up_next_keep_finished,
    up_next_sort: preferences.up_next_sort,
});

const defaultRegionLabel = props.regions[props.defaultRegion] ?? props.defaultRegion;

// Chromium leaves 'UTC' out of the list: a zone saved earlier stays a choice.
const supportedZones = Intl.supportedValuesOf('timeZone');
const timezones = preferences.timezone && !supportedZones.includes(preferences.timezone) ? [preferences.timezone, ...supportedZones] : supportedZones;
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

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

// @view The choices of each select, typed by the server's own enums: a value it
// does not know, or one it added, is a compile error rather than a dead option.
type Choice<T extends string> = { value: T; label: () => string };

const TIME_FORMATS: Choice<TimeFormat>[] = [
    { value: 'auto', label: () => trans('settings.automatic_by_language') },
    { value: '24', label: () => trans('settings.24_hour_21_00') },
    { value: '12', label: () => trans('settings.12_hour_9_00_pm') },
];

const WEEK_STARTS: Choice<WeekStart>[] = [
    { value: 'auto', label: () => trans('settings.automatic_by_language') },
    { value: 'monday', label: () => trans('settings.monday') },
    { value: 'sunday', label: () => trans('settings.sunday') },
];

const UP_NEXT_TARGETS: Choice<UpNextTarget>[] = [
    { value: 'episode', label: () => trans('settings.the_episode') },
    { value: 'season', label: () => trans('settings.the_season') },
    { value: 'show', label: () => trans('settings.the_show') },
];

const UP_NEXT_SORTS: Choice<UpNextSort>[] = [
    { value: 'activity', label: () => trans('common.last_activity') },
    { value: 'remaining', label: () => trans('settings.least_left_to_finish') },
    { value: 'released', label: () => trans('settings.newest_release') },
    { value: 'title', label: () => trans('settings.title_a_z') },
];
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.preferences')" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="submit">
                <fieldset :disabled="form.processing" class="min-w-0 space-y-6">
                    <HeadingSmall :title="trans('common.preferences')" :description="trans('settings.display_and_time_settings')" />

                    <div :class="[SETTINGS_CARD_CLASS, 'flex items-center justify-between gap-6']">
                        <div class="min-w-0">
                            <div class="text-ink text-base font-semibold">{{ trans('settings.spoiler_protection') }}</div>
                            <div class="text-ink-muted mt-0.5 text-sm">{{ trans('settings.blur_episode_descriptions_until_watched') }}</div>
                        </div>
                        <ToggleSwitch v-model="form.spoiler_protection" :label="trans('settings.spoiler_protection')" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="timezone" class="text-ink text-base font-semibold">{{ trans('settings.timezone') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                            {{ trans('settings.watch_dates_and_times_are_shown') }}
                        </p>
                        <select id="timezone" v-model="form.timezone" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option value="">{{ trans('settings.automatic_zone', { zone: browserZone }) }}</option>
                            <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.timezone" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="time_format" class="text-ink text-base font-semibold">{{ trans('settings.time_format') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                            {{ trans('settings.how_times_of_day_are_shown') }}
                        </p>
                        <select id="time_format" v-model="form.time_format" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option v-for="choice in TIME_FORMATS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.time_format" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="week_start" class="text-ink text-base font-semibold">{{ trans('settings.first_day_of_the_week') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                            {{ trans('settings.the_day_a_week_starts_on') }}
                        </p>
                        <select id="week_start" v-model="form.week_start" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option v-for="choice in WEEK_STARTS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.week_start" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="up-next-target" class="text-ink text-base font-semibold">{{ trans('settings.up_next_opens') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                            {{ trans('settings.which_page_an_up_next_card') }}
                        </p>
                        <select id="up-next-target" v-model="form.up_next_target" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option v-for="choice in UP_NEXT_TARGETS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.up_next_target" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="up-next-sort" class="text-ink text-base font-semibold">{{ trans('settings.card_order') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                            {{ trans('settings.how_the_dashboard_sorts_up_next') }}
                        </p>
                        <select id="up-next-sort" v-model="form.up_next_sort" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option v-for="choice in UP_NEXT_SORTS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                        </select>
                        <p class="text-ink-muted mt-2 text-sm">
                            {{ trans('settings.least_left_to_finish_counts_the') }}
                        </p>
                        <InputError class="mt-2" :message="form.errors.up_next_sort" />
                    </div>

                    <div :class="[SETTINGS_CARD_CLASS, 'flex items-center justify-between gap-6']">
                        <div class="min-w-0">
                            <div class="text-ink text-base font-semibold">{{ trans('settings.keep_finished_shows_on_screen') }}</div>
                            <div class="text-ink-muted mt-0.5 text-sm">
                                {{ trans('settings.after_a_finale_the_show_gets') }}
                            </div>
                        </div>
                        <ToggleSwitch v-model="form.up_next_keep_finished" :label="trans('settings.keep_finished_shows_on_screen')" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="region" class="text-ink text-base font-semibold">{{ trans('settings.region') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                            {{ trans('settings.region_description') }}
                        </p>
                        <select id="region" v-model="form.region" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                            <option value="">{{ trans('settings.automatic_region', { region: defaultRegionLabel }) }}</option>
                            <option v-for="(name, code) in regions" :key="code" :value="code">{{ name }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.region" />
                    </div>
                </fieldset>
                <SettingsSaveBar :dirty="form.isDirty" :processing="form.processing" @save="submit" @discard="discard" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
