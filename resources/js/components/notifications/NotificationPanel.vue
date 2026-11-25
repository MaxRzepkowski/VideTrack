<script setup lang="ts">
import NotificationRow from '@/components/notifications/NotificationRow.vue';
import { notificationDays } from '@/lib/notifications';
import type { NotificationEntry } from '@/types/notifications';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ArrowRight, Bell, CheckCheck } from '@lucide/vue';
import { computed } from 'vue';

/**
 * What the bell holds: the latest notifications by day, a way to mark them
 * all read, and the ways on to every notification and to what you follow.
 * The same panel drops from the bell on a desktop and rises as a sheet on a
 * phone; the sheet brings its close button through the `close` slot. Until
 * the bell has asked for the list once, rows of placeholders hold its place.
 */
const props = defineProps<{
    unread: number;
    /** The latest few, newest first; null while they are on their way. */
    recent: NotificationEntry[] | null;
    followingHref: string;
}>();

const emit = defineEmits<{
    markAllRead: [];
    open: [item: NotificationEntry, event: MouseEvent];
    /** A link out of the panel was taken; whoever holds the panel closes it first. */
    leave: [];
}>();

const days = computed(() => notificationDays(props.recent ?? []));

// About the height of the few rows a bell usually holds, so the panel does not
// jump when they arrive.
const PLACEHOLDER_ROWS = 3;

const FOOTER_BUTTON =
    'bg-surface-raised hover:bg-surface-overlay text-ink flex h-10 flex-1 items-center justify-center gap-2 rounded-[10px] text-[15px] font-semibold transition-colors duration-150';
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <div class="flex items-center gap-3 pt-4 pr-4 pb-1 pl-5">
            <h2 class="text-ink flex-1 text-xl font-semibold">{{ trans('common.notifications') }}</h2>
            <button
                v-if="unread > 0"
                type="button"
                class="bg-surface-raised text-ink-secondary hover:text-ink flex h-9 cursor-pointer items-center gap-2 rounded-lg pr-3.5 pl-3 text-sm font-medium transition-colors duration-150"
                @click="emit('markAllRead')"
            >
                <CheckCheck class="size-4" aria-hidden="true" />
                {{ trans('common.mark_all_as_read') }}
            </button>
            <slot name="close" />
        </div>

        <!-- Taller than the bell's few rows: the list scrolls inside, the
             heading and the way out stay put. -->
        <div v-if="recent === null" role="status" class="pb-2" aria-busy="true" :aria-label="trans('common.loading')">
            <!-- The poster and two lines of a row, in its sizes. -->
            <div v-for="row in PLACEHOLDER_ROWS" :key="row" class="flex gap-3.5 px-5 py-3" aria-hidden="true">
                <span class="skeleton h-18 w-12 shrink-0 rounded-md" />
                <span class="flex flex-1 flex-col gap-2 pt-1">
                    <span class="skeleton h-4 w-3/5 rounded" />
                    <span class="skeleton h-3.5 w-2/5 rounded" />
                </span>
            </div>
        </div>

        <div v-else-if="days.length" class="scroll-subtle min-h-0 flex-1 overflow-y-auto overscroll-contain pb-2 md:max-h-[min(34rem,70vh)]">
            <section v-for="day in days" :key="day.key" :aria-label="day.heading">
                <h3 class="text-ink-muted px-5 pt-3 pb-0.5 text-sm font-medium">{{ day.heading }}</h3>
                <NotificationRow v-for="item in day.items" :key="item.id" :item="item" @open="emit('open', item, $event)" />
            </section>
        </div>

        <div v-else class="flex flex-col items-center px-8 pt-6 pb-8 text-center">
            <span class="bg-surface-overlay text-brand-soft flex size-10 items-center justify-center rounded-lg" aria-hidden="true">
                <Bell class="size-5" />
            </span>
            <p class="text-ink mt-4 text-base font-semibold">{{ trans('app.no_notifications') }}</p>
            <p class="text-ink-muted mt-2 text-[15px]">{{ trans('app.no_notifications_hint') }}</p>
        </div>

        <div class="border-line flex gap-2 border-t px-5 pt-3 pb-4">
            <Link v-if="days.length" :href="route('notifications.index')" :class="FOOTER_BUTTON" @click="emit('leave')">
                {{ trans('app.all_notifications') }}
                <ArrowRight class="text-ink-secondary size-4" aria-hidden="true" />
            </Link>
            <Link :href="followingHref" :class="FOOTER_BUTTON" @click="emit('leave')">
                {{ trans('common.followed') }}
                <Bell class="text-ink-secondary size-4" aria-hidden="true" />
            </Link>
        </div>
    </div>
</template>
