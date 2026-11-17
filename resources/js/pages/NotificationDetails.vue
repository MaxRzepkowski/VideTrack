<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { leafCode, showTitleOf } from '@/lib/badges';
import { formatTimestampDate } from '@/lib/format';
import type { MediaSummary } from '@/types/media';
import type { NotificationEntry } from '@/types/notifications';
import { Head, Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    notification: NotificationEntry;
    items: { media: MediaSummary | null; available: boolean }[];
}>();

const title = props.notification.title;

// A title nobody keeps any more has been deleted since; there is no card to
// lead to, so it is only counted.
const available = computed(() => props.items.flatMap((item) => (item.media === null ? [] : [item.media])));
const deleted = computed(() => props.items.length - available.value.length);
</script>

<template>
    <AppLayout>
        <Head :title="title" />

        <div class="max-w-content mx-auto w-full flex-1 px-4 pt-8 pb-20 md:px-10">
            <Link :href="route('notifications.index')" class="text-brand-soft hover:text-ink text-sm font-semibold transition-colors duration-150">
                {{ trans('common.notifications') }}
            </Link>
            <h1 class="text-ink text-title mt-2 mb-1 font-semibold">{{ title }}</h1>
            <p class="text-ink-faint m-0 mb-8 text-sm">{{ formatTimestampDate(notification.createdAt) }}</p>

            <MediaGrid v-if="available.length">
                <MediaCard
                    v-for="media in available"
                    :key="`${media.type}-${media.ref}`"
                    :media="media"
                    :subtitle="showTitleOf(media)"
                    :corner-badge="leafCode(media)"
                    show-type-badge
                    with-actions
                />
            </MediaGrid>

            <p v-if="deleted > 0" class="text-ink-muted mt-6 text-sm">
                {{ transChoice('notifications.count_of_them_has_been_deleted', deleted) }}
            </p>

            <p v-if="notification.event === 'titles_removed'" class="mt-6 text-sm">
                <Link :href="route('library.archived')" class="text-brand-soft hover:text-ink font-semibold transition-colors duration-150">
                    {{ trans('notifications.all_archived_titles_in_your_library') }}
                </Link>
            </p>
        </div>
    </AppLayout>
</template>
