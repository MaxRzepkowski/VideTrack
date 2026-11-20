<script setup lang="ts">
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * How the calendar or the countdowns show what they show: the same three
 * choices for both, each worded for its own view.
 */
const props = defineProps<{
    view: 'calendar' | 'countdown';
    releaseModes: { value: string; label: string }[];
}>();

const gamePlatforms = defineModel<boolean>('gamePlatforms', { required: true });
const releaseMode = defineModel<string>('releaseMode', { required: true });
const episodeImage = defineModel<string>('episodeImage', { required: true });

const words = computed(() =>
    props.view === 'calendar'
        ? {
              title: trans('common.calendar'),
              platforms: trans('settings.show_every_platform_premiere_of_your'),
              releases: trans('settings.which_of_a_films_dates_the'),
              images: trans('settings.which_image_episodes_use_in_the'),
          }
        : {
              title: trans('common.countdowns'),
              platforms: trans('settings.count_down_every_platform_premiere_of'),
              releases: trans('settings.which_of_a_films_dates_you'),
              images: trans('settings.which_image_episodes_use_in_countdowns'),
          },
);
</script>

<template>
    <SettingsCard :title="words.title">
        <SettingsRow :title="trans('settings.game_platform_releases')" :description="words.platforms" layout="inline">
            <ToggleSwitch
                v-model="gamePlatforms"
                :label="trans('settings.setting_in_group', { group: words.title, setting: trans('settings.game_platform_releases') })"
            />
        </SettingsRow>
        <SettingsRow :title="trans('settings.film_releases')" :description="words.releases" :for="`${view}-release-mode`">
            <select :id="`${view}-release-mode`" v-model="releaseMode" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                <option v-for="mode in releaseModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
            </select>
        </SettingsRow>
        <SettingsRow :title="trans('settings.episode_thumbnails')" :description="words.images" :for="`${view}-episode-image`">
            <select :id="`${view}-episode-image`" v-model="episodeImage" :class="[SETTINGS_FIELD_CLASS, 'block w-full']">
                <option value="backdrop">{{ trans('settings.show_backdrop') }}</option>
                <option value="still">{{ trans('settings.episode_still') }}</option>
            </select>
        </SettingsRow>
    </SettingsCard>
</template>
