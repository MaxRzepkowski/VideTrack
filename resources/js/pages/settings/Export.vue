<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import CalendarSubscriptionCard from '@/components/settings/CalendarSubscriptionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { ExportDownload, ExportShelf, SubscriptionFeed } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    calendarFeed: SubscriptionFeed;
    rssFeed: SubscriptionFeed;
    /** Every download this instance offers, in the order the page shows them. */
    formats: ExportDownload[];
}>();

const onShelf = (shelf: ExportShelf) => computed(() => props.formats.filter((format) => format.shelf === shelf));
const backups = onShelf('backup');
const spreadsheets = onShelf('spreadsheet');
const services = onShelf('service');
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

                <CalendarSubscriptionCard :calendar-feed="calendarFeed" :rss-feed="rssFeed" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
