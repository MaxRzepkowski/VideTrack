<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import MetaLine from '@/components/MetaLine.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import { confirm } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatTimestampDate } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { mediaImage } from '@/lib/images';
import { clickTarget, markRead } from '@/lib/notifications';
import type { NotificationEntry } from '@/types/notifications';
import type { PageInfo } from '@/types/pagination';
import { Head, router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Bell, CheckCheck, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    notifications: NotificationEntry[];
    pagination: PageInfo;
}>();

const hasUnread = computed(() => props.notifications.some((item) => item.readAt === null));

function markAllRead(): void {
    void jsonFetch(route('ui.notifications.read-all'), { method: 'POST' })
        .then(() => router.reload({ only: ['notifications'] }))
        .catch(() => undefined);
}

/** Open a notification: mark an unread one read first, then go to its media page; a new tab goes on its own. */
async function open(item: NotificationEntry, event: MouseEvent): Promise<void> {
    const target = clickTarget(event);

    if (target === 'elsewhere') {
        void markRead(item).then(() => router.reload({ only: ['notifications'] }));
    }

    if (target !== 'here') {
        return;
    }

    event.preventDefault();
    await markRead(item);
    router.visit(item.href);
}

function destroy(item: NotificationEntry): void {
    router.delete(route('notifications.destroy', { notification: item.id }), { preserveScroll: true });
}

async function destroyAll(): Promise<void> {
    const confirmed = await confirm({
        message: trans('notifications.delete_all_notifications_this_cannot_be'),
        confirmLabel: trans('notifications.delete_all'),
    });

    if (confirmed) {
        router.delete(route('notifications.destroy-all'), { preserveScroll: true });
    }
}

function goto(page: number): void {
    router.get(route('notifications.index'), page > 1 ? { page } : {}, { preserveScroll: false, preserveState: false });
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.notifications')" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <PageHeading :title="trans('common.notifications')">
                    <template #icon><Bell class="size-6" /></template>
                </PageHeading>
            </div>
        </PageMasthead>

        <PageToolbar variant="band" :count="transChoice('notifications.count_notification', pagination.total)">
            <template #actions>
                <button
                    v-if="hasUnread"
                    type="button"
                    data-toolbar-control
                    class="border-line text-ink hover:border-line-strong flex h-10 items-center gap-2 rounded-lg border px-4 text-base font-semibold transition-colors duration-150"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-4" />
                    {{ trans('common.mark_all_as_read') }}
                </button>
                <button
                    v-if="notifications.length"
                    type="button"
                    data-toolbar-control
                    class="border-line text-danger hover:border-danger-line flex h-10 items-center gap-2 rounded-lg border px-4 text-base font-semibold transition-colors duration-150"
                    @click="destroyAll"
                >
                    <Trash2 class="size-4" />
                    {{ trans('notifications.delete_all') }}
                </button>
            </template>
        </PageToolbar>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <div v-if="notifications.length" class="border-line-subtle bg-surface divide-line-subtle divide-y rounded-2xl border">
                <!-- The title link opens the notification and its ::after
                     stretches over the whole row, so the row clicks as one;
                     delete is a button of its own beside it, never inside. -->
                <div
                    v-for="item in notifications"
                    :key="item.id"
                    class="hover:bg-surface-raised relative flex w-full cursor-pointer items-start gap-4 px-4 py-4 text-left transition-colors duration-150 first:rounded-t-2xl last:rounded-b-2xl"
                    :class="{ 'bg-surface-raised': !item.readAt }"
                >
                    <img
                        v-if="mediaImage(item.imagePath, 'w342')"
                        :src="mediaImage(item.imagePath, 'w342') ?? undefined"
                        :alt="item.mediaTitle"
                        width="48"
                        height="72"
                        loading="lazy"
                        class="bg-surface aspect-poster w-12 shrink-0 rounded-md object-cover"
                    />
                    <span v-else class="bg-surface aspect-poster w-12 shrink-0 rounded-md" />
                    <span class="min-w-0 flex-1">
                        <a
                            :href="item.href"
                            class="text-ink line-clamp-2 block cursor-pointer text-left text-base font-semibold after:absolute after:inset-0"
                            @click="open(item, $event)"
                            @auxclick="open(item, $event)"
                        >
                            <template v-if="!item.readAt"
                                ><span class="sr-only">{{ trans('notifications.unread_title', { title: item.title }) }}</span
                                ><span aria-hidden="true">{{ item.title }}</span></template
                            ><template v-else>{{ item.title }}</template>
                        </a>
                        <MetaLine v-if="item.detail.length" :parts="item.detail" class="text-ink-muted text-sm" />
                        <span class="text-ink-faint mt-0.5 block text-sm">{{ formatTimestampDate(item.createdAt) }}</span>
                    </span>
                    <span v-if="!item.readAt" class="bg-brand mt-2 size-2 shrink-0 rounded-full" aria-hidden="true" />
                    <button
                        v-tooltip="trans('notifications.delete_notification')"
                        type="button"
                        :aria-label="trans('notifications.delete_notification')"
                        class="text-ink-faint hover:text-ink hover:bg-surface-raised relative z-10 mt-0.5 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg transition-colors duration-150"
                        @click="destroy(item)"
                    >
                        <X class="size-4" :stroke-width="2.2" />
                    </button>
                </div>
            </div>

            <EmptyState v-else :message="trans('common.no_notifications_yet')" />

            <div v-if="pagination.totalPages > 1" class="mt-8">
                <Pagination :current-page="pagination.currentPage" :total-pages="pagination.totalPages" @goto="goto" />
            </div>
        </div>
    </AppLayout>
</template>
