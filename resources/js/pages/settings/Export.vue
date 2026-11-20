<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import ServiceLogo from '@/components/ServiceLogo.vue';
import ServiceTile from '@/components/settings/ServiceTile.vue';
import CalendarSubscriptionCard from '@/components/settings/CalendarSubscriptionCard.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { SETTINGS_CARD_CLASS } from '@/lib/ui';
import type { ExportDownload, ExportShelf, SubscriptionFeed } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { Download, FileDown, FileSpreadsheet, DatabaseBackup } from '@lucide/vue';
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

        <SettingsLayout width="wide">
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.export_data')" :description="trans('import.download_your_watch_history_ratings_favorites')" />

                <section v-if="backups.length > 0" :class="[SETTINGS_CARD_CLASS, 'flex flex-wrap items-center gap-x-4 gap-y-3']">
                    <ServiceLogo code="videtrack" :fallback="DatabaseBackup" class="h-12" />
                    <div class="min-w-0 flex-1 basis-64">
                        <h4 class="text-ink text-base font-semibold">{{ trans('import.full_backup_json') }}</h4>
                        <p class="text-ink-muted mt-0.5 text-sm">{{ trans('import.everything_in_one_file_history_ratings') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="entry in backups" :key="entry.code" class="h-10 py-2" as-child>
                            <a :href="route('settings.export.download', { format: entry.code })">
                                <Download class="size-4" aria-hidden="true" />
                                {{ entry.label }}
                            </a>
                        </Button>
                    </div>
                </section>

                <SettingsCard
                    v-if="spreadsheets.length > 0"
                    :title="trans('import.spreadsheets_csv')"
                    :description="trans('import.flat_per_section_files_for_spreadsheet')"
                >
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="entry in spreadsheets" :key="entry.code" variant="outline" class="h-10 py-2" as-child>
                            <a :href="route('settings.export.download', { format: entry.code })">
                                <FileSpreadsheet class="text-ink-muted size-4" aria-hidden="true" />
                                {{ entry.label }}
                            </a>
                        </Button>
                    </div>
                </SettingsCard>

                <SettingsCard
                    v-if="services.length > 0"
                    :title="trans('import.other_services')"
                    :description="trans('import.your_ratings_and_watch_history_each')"
                >
                    <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        <li v-for="entry in services" :key="entry.code">
                            <ServiceTile
                                as="a"
                                :href="route('settings.export.download', { format: entry.code })"
                                :code="entry.code"
                                :label="entry.label"
                                :fallback="FileDown"
                            >
                                <Download class="text-ink-muted absolute top-2.5 right-2.5 size-4" aria-hidden="true" />
                            </ServiceTile>
                        </li>
                    </ul>
                </SettingsCard>

                <CalendarSubscriptionCard :calendar-feed="calendarFeed" :rss-feed="rssFeed" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
