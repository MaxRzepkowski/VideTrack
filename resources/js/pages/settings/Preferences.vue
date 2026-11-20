<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { usePreferences } from '@/composables/usePreferences';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { mediaTypeMeta, typeCountingIn } from '@/lib/mediaTypes';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ProgressUnit, TimeFormat, UpNextSort, UpNextTarget, WeekStart } from '@/types/generated';
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
    reading_pace_pages: preferences.reading_pace_pages,
    reading_pace_chapters: preferences.reading_pace_chapters,
    reading_pace_issues: preferences.reading_pace_issues,
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

/** A field per unit something is read in, each under the type read in it; a type switched off has none. */
const READING_PACES: {
    unit: Exclude<ProgressUnit, 'minutes'>;
    field: 'reading_pace_pages' | 'reading_pace_chapters' | 'reading_pace_issues';
    perHour: () => string;
}[] = [
    { unit: 'pages', field: 'reading_pace_pages', perHour: () => trans('settings.pages_per_hour') },
    { unit: 'chapters', field: 'reading_pace_chapters', perHour: () => trans('settings.chapters_per_hour') },
    { unit: 'issues', field: 'reading_pace_issues', perHour: () => trans('settings.issues_per_hour') },
];

const readingPaces = READING_PACES.flatMap((pace) => {
    const type = typeCountingIn(pace.unit);

    return type === null ? [] : [{ ...pace, type }];
});

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

                    <SettingsCard>
                        <SettingsRow
                            :title="trans('settings.spoiler_protection')"
                            :description="trans('settings.blur_episode_descriptions_until_watched')"
                            layout="inline"
                        >
                            <ToggleSwitch v-model="form.spoiler_protection" :label="trans('settings.spoiler_protection')" />
                        </SettingsRow>
                    </SettingsCard>

                    <SettingsCard :title="trans('settings.time_and_place')">
                        <SettingsRow
                            :title="trans('settings.timezone')"
                            :description="trans('settings.watch_dates_and_times_are_shown')"
                            for="timezone"
                            :error="form.errors.timezone"
                        >
                            <select id="timezone" v-model="form.timezone" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                <option value="">{{ trans('settings.automatic_zone', { zone: browserZone }) }}</option>
                                <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                            </select>
                        </SettingsRow>
                        <SettingsRow
                            :title="trans('settings.time_format')"
                            :description="trans('settings.how_times_of_day_are_shown')"
                            for="time_format"
                            :error="form.errors.time_format"
                        >
                            <select id="time_format" v-model="form.time_format" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                <option v-for="choice in TIME_FORMATS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                            </select>
                        </SettingsRow>
                        <SettingsRow
                            :title="trans('settings.first_day_of_the_week')"
                            :description="trans('settings.the_day_a_week_starts_on')"
                            for="week_start"
                            :error="form.errors.week_start"
                        >
                            <select id="week_start" v-model="form.week_start" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                <option v-for="choice in WEEK_STARTS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                            </select>
                        </SettingsRow>
                        <SettingsRow
                            :title="trans('settings.region')"
                            :description="trans('settings.region_description')"
                            for="region"
                            :error="form.errors.region"
                        >
                            <select id="region" v-model="form.region" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                <option value="">{{ trans('settings.automatic_region', { region: defaultRegionLabel }) }}</option>
                                <option v-for="(name, code) in regions" :key="code" :value="code">{{ name }}</option>
                            </select>
                        </SettingsRow>
                    </SettingsCard>

                    <SettingsCard :title="trans('settings.up_next_group')">
                        <SettingsRow
                            :title="trans('settings.up_next_opens')"
                            :description="trans('settings.which_page_an_up_next_card')"
                            for="up-next-target"
                            :error="form.errors.up_next_target"
                        >
                            <select id="up-next-target" v-model="form.up_next_target" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                <option v-for="choice in UP_NEXT_TARGETS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                            </select>
                        </SettingsRow>
                        <SettingsRow
                            :title="trans('settings.card_order')"
                            :description="trans('settings.how_the_dashboard_sorts_up_next')"
                            for="up-next-sort"
                            :error="form.errors.up_next_sort"
                        >
                            <select id="up-next-sort" v-model="form.up_next_sort" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                                <option v-for="choice in UP_NEXT_SORTS" :key="choice.value" :value="choice.value">{{ choice.label() }}</option>
                            </select>
                            <p class="text-ink-muted mt-2 text-sm">{{ trans('settings.least_left_to_finish_counts_the') }}</p>
                        </SettingsRow>
                        <SettingsRow
                            :title="trans('settings.keep_finished_shows_on_screen')"
                            :description="trans('settings.after_a_finale_the_show_gets')"
                            layout="inline"
                        >
                            <ToggleSwitch v-model="form.up_next_keep_finished" :label="trans('settings.keep_finished_shows_on_screen')" />
                        </SettingsRow>
                    </SettingsCard>

                    <SettingsCard
                        v-if="readingPaces.length > 0"
                        :title="trans('settings.reading_pace')"
                        :description="trans('settings.reading_pace_description')"
                    >
                        <div v-for="pace in readingPaces" :key="pace.unit" class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                                <Label :for="pace.field" class="text-ink flex items-center gap-2.5 text-base font-semibold">
                                    <component
                                        :is="mediaTypeMeta(pace.type).icon"
                                        class="size-5 shrink-0"
                                        :style="{ color: mediaTypeMeta(pace.type).color }"
                                        aria-hidden="true"
                                    />
                                    {{ mediaTypeMeta(pace.type).plural() }}
                                </Label>
                                <div class="flex items-center gap-3">
                                    <input
                                        :id="pace.field"
                                        v-model.number="form[pace.field]"
                                        type="number"
                                        inputmode="numeric"
                                        min="1"
                                        step="1"
                                        :class="[SETTINGS_FIELD_CLASS, 'w-28 text-right tabular-nums']"
                                    />
                                    <span class="text-ink-muted w-44 text-sm">{{ pace.perHour() }}</span>
                                </div>
                            </div>
                            <InputError class="mt-2" :message="form.errors[pace.field]" />
                        </div>
                    </SettingsCard>
                </fieldset>
                <SettingsSaveBar :dirty="form.isDirty" :processing="form.processing" @save="submit" @discard="discard" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
