<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ExportDownload, ExportShelf } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    calendarFeedUrl: string;
    calendarWebcalUrl: string;
    /** Every download this instance offers, in the order the page shows them. */
    formats: ExportDownload[];
}>();

const onShelf = (shelf: ExportShelf) => computed(() => props.formats.filter((format) => format.shelf === shelf));
const backups = onShelf('backup');
const spreadsheets = onShelf('spreadsheet');
const services = onShelf('service');

const { copied, copy } = useCopyToClipboard(trans('common.could_not_copy_the_link'));

async function copyFeedUrl(): Promise<void> {
    await copy(props.calendarFeedUrl);
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.export_data')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.export_data')" :description="trans('import.download_your_watch_history_ratings_favorites')" />

                <div v-if="backups.length > 0" class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('import.full_backup_json') }}</div>
                    <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                        {{ trans('import.everything_in_one_file_history_ratings') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <a
                            v-for="entry in backups"
                            :key="entry.code"
                            :href="route('settings.export.download', { format: entry.code })"
                            class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold"
                        >
                            {{ entry.label }}
                        </a>
                    </div>
                </div>

                <div v-if="spreadsheets.length > 0" class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('import.spreadsheets_csv') }}</div>
                    <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                        {{ trans('import.flat_per_section_files_for_spreadsheet') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <a
                            v-for="entry in spreadsheets"
                            :key="entry.code"
                            :href="route('settings.export.download', { format: entry.code })"
                            class="border-line-subtle bg-surface-base text-ink hover:border-brand inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium"
                        >
                            {{ entry.label }}
                        </a>
                    </div>
                </div>

                <div v-if="services.length > 0" class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('import.other_services') }}</div>
                    <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                        {{ trans('import.your_ratings_and_watch_history_each') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <a
                            v-for="entry in services"
                            :key="entry.code"
                            :href="route('settings.export.download', { format: entry.code })"
                            class="border-line-subtle bg-surface-base text-ink hover:border-brand inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium"
                        >
                            {{ entry.label }}
                        </a>
                    </div>
                </div>

                <div class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('import.calendar_subscription') }}</div>
                    <p class="text-ink-muted mt-0.5 mb-3 text-sm">
                        {{ trans('import.this_link_can_be_used_to') }}
                    </p>
                    <div class="flex flex-col gap-3">
                        <div class="flex gap-2">
                            <label for="calendar-feed-url" class="sr-only">{{ trans('import.calendar_subscription') }}</label>
                            <input
                                id="calendar-feed-url"
                                readonly
                                :value="calendarFeedUrl"
                                :class="[SETTINGS_FIELD_CLASS, 'text-ink-secondary min-w-0 flex-1']"
                                @focus="($event.target as HTMLInputElement).select()"
                            />
                            <button
                                type="button"
                                class="border-line-subtle bg-surface-base text-ink hover:border-brand shrink-0 rounded-md border px-4 py-2 text-sm font-medium"
                                @click="copyFeedUrl"
                            >
                                {{ copied ? trans('common.copied') : trans('common.copy') }}
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                :href="calendarWebcalUrl"
                                class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold"
                            >
                                {{ trans('common.subscribe') }}
                            </a>
                            <a
                                :href="calendarFeedUrl"
                                class="border-line-subtle bg-surface-base text-ink hover:border-brand inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium"
                            >
                                {{ trans('common.download_ics') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
