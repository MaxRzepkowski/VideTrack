<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatTimestampDate } from '@/lib/format';
import type { MediaSummary } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

defineProps<{ titles: { media: MediaSummary; goneAt: string | null }[] }>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('media.archived_titles')" />

        <div class="max-w-content mx-auto w-full flex-1 px-4 pt-8 pb-20 md:px-10">
            <h1 class="text-ink text-title m-0 mb-2 font-semibold">{{ trans('media.archived_titles') }}</h1>
            <p class="text-ink-muted max-w-reading m-0 mb-8 text-base">
                {{ trans('media.their_data_source_no_longer_has') }}
            </p>

            <MediaGrid v-if="titles.length">
                <MediaCard
                    v-for="title in titles"
                    :key="`${title.media.type}-${title.media.ref}`"
                    :media="title.media"
                    :subtitle="title.goneAt ? trans('media.gone_since_date', { date: formatTimestampDate(title.goneAt) ?? '' }) : null"
                    show-type-badge
                    with-actions
                />
            </MediaGrid>

            <EmptyState v-else :message="trans('media.nothing_in_your_library_is_archived')" />
        </div>
    </AppLayout>
</template>
