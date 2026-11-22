<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import NotificationRow from '@/components/notifications/NotificationRow.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import { confirm } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonFetch } from '@/lib/http';
import { clickTarget, markRead, notificationDays } from '@/lib/notifications';
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
const days = computed(() => notificationDays(props.notifications));

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
            <!-- The bell's list at full length: the same rows by day, each with
                 its own delete beside the link. -->
            <div v-if="notifications.length" class="border-line bg-surface max-w-3xl overflow-hidden rounded-xl border pb-2">
                <section v-for="day in days" :key="day.key" :aria-label="day.heading">
                    <h2 class="text-ink-muted px-5 pt-4 pb-0.5 text-sm font-medium">{{ day.heading }}</h2>
                    <NotificationRow v-for="item in day.items" :key="item.id" :item="item" @open="open(item, $event)">
                        <template #actions>
                            <button
                                v-tooltip="trans('notifications.delete_notification')"
                                type="button"
                                :aria-label="trans('notifications.delete_notification')"
                                class="text-ink-faint hover:text-ink hover:bg-surface-raised relative z-10 flex size-8 shrink-0 cursor-pointer items-center justify-center self-center rounded-lg transition-colors duration-150"
                                @click="destroy(item)"
                            >
                                <X class="size-4" :stroke-width="2.2" />
                            </button>
                        </template>
                    </NotificationRow>
                </section>
            </div>

            <EmptyState v-else :message="trans('common.no_notifications_yet')" />

            <div v-if="pagination.totalPages > 1" class="mt-8">
                <Pagination :current-page="pagination.currentPage" :total-pages="pagination.totalPages" @goto="goto" />
            </div>
        </div>
    </AppLayout>
</template>
