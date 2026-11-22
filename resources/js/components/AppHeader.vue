<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import MainMenu from '@/components/header/MainMenu.vue';
import MenuDrawer from '@/components/header/MenuDrawer.vue';
import NotificationPanel from '@/components/notifications/NotificationPanel.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useNotificationFeed } from '@/composables/useNotificationFeed';
import { getInitials } from '@/lib/format';
import { FOLLOWING, navHref } from '@/lib/navigation';
import { jsonFetch } from '@/lib/http';
import { clickTarget, markRead } from '@/lib/notifications';
import { signedInUser } from '@/lib/pageContext';
import type { NotificationEntry } from '@/types/notifications';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Bell, Search, X } from '@lucide/vue';
import { DialogClose } from 'radix-vue';
import { computed, ref, watch } from 'vue';

// The header is part of the app layout, which only a signed-in user sees.
const viewer = computed(() => signedInUser());

// Header bell: unread counter + latest notifications (null for guests). Kept
// current while the tab stays open — the shared prop alone would age until the
// next navigation.
const bellOpen = ref(false);
const sheetOpen = ref(false);
const notificationsOpen = computed(() => bellOpen.value || sheetOpen.value);
const { feed: notifications, refresh: refreshNotifications } = useNotificationFeed(notificationsOpen);

// Opening is the one moment the list is read closely; ask for it fresh.
watch(notificationsOpen, (open) => {
    if (open) {
        refreshNotifications();
    }
});

const bellLabel = computed(() =>
    notifications.value && notifications.value.unread > 0
        ? trans('common.notifications_unread', { count: String(notifications.value.unread) })
        : trans('common.notifications'),
);
const followingHref = navHref(FOLLOWING);

const BELL =
    'bg-surface hover:bg-surface-raised data-[state=open]:bg-surface-overlay relative size-10 cursor-pointer items-center justify-center rounded-[10px] transition-colors duration-150 flex';
// Something unread is a dot, not a count; the bell's name still says how many.
const UNREAD_MARK = 'bg-brand-soft absolute top-2 right-2 size-2.25 rounded-full';

/**
 * Every route out of the dropdown closes it first. Left open, an Inertia visit
 * tears the whole header down mid-animation, and radix never gets to undo the
 * `pointer-events: none` it puts on <body> for a modal menu — the app looks
 * frozen. The visit is async, so closing here always lands first.
 */
function leaveDropdown(): void {
    bellOpen.value = false;
    sheetOpen.value = false;
}

// Read marks go through the JSON endpoints: a POST answered with `back()`
// would re-render the whole page under the bell for a counter.
function markAllRead(): void {
    void jsonFetch(route('ui.notifications.read-all'), { method: 'POST' })
        .then(refreshNotifications)
        .catch(() => undefined);
}

/** Open a notification: mark an unread one read first, then go to its page; a new tab goes on its own. */
async function openNotification(item: NotificationEntry, event: MouseEvent): Promise<void> {
    const target = clickTarget(event);

    if (target === 'elsewhere') {
        void markRead(item).then(refreshNotifications);
    }

    if (target !== 'here') {
        return;
    }

    event.preventDefault();
    leaveDropdown();
    await markRead(item);
    router.visit(item.href);
}
</script>

<template>
    <header class="sticky top-0 z-40 h-14 border-b md:h-18">
        <!-- The blur is a layer of its own: a menu panel inside an element with a
             backdrop filter makes Chrome redraw the blur on every colour change
             in the panel, which flickers. -->
        <div class="bg-surface-base/90 absolute inset-0 backdrop-blur-md" aria-hidden="true" />
        <div class="max-w-content relative mx-auto flex h-full w-full items-center justify-between gap-3 px-3 md:gap-8 md:px-10">
            <div class="flex min-w-0 items-center gap-2 xl:gap-8">
                <!-- The drawer stands in below `xl`: the bar's words beside the logo
                     and the search box need about 1150px. -->
                <MenuDrawer />

                <Link :href="route('dashboard')" class="shrink-0">
                    <AppLogo />
                </Link>
                <MainMenu class="hidden xl:block" />
            </div>

            <div class="flex shrink-0 items-center gap-2.5">
                <!-- The box leads to the search page; the shortcut beside it is the
                     command palette's, which jumps to your own titles and places. -->
                <Link
                    :href="route('search')"
                    class="border-line-subtle bg-surface hover:bg-surface-raised hidden h-10 w-60 items-center gap-2.5 rounded-[10px] border pr-1.5 pl-3 transition-colors duration-150 xl:flex"
                >
                    <Search class="text-ink-muted size-4.5 shrink-0" />
                    <span class="text-ink-muted flex-1 text-[15px]">{{ trans('common.search') }}</span>
                    <kbd class="bg-surface-overlay text-ink-muted rounded-md px-2 py-0.5 font-sans text-sm font-medium" aria-hidden="true">
                        {{ trans('app.search_shortcut') }}
                    </kbd>
                </Link>
                <Link
                    :href="route('search')"
                    :aria-label="trans('common.search')"
                    class="bg-surface hover:bg-surface-raised flex size-10 items-center justify-center rounded-[10px] transition-colors duration-150 xl:hidden"
                >
                    <Search class="text-ink size-4.5" />
                </Link>

                <template v-if="notifications">
                    <!-- One bell, two holders: a panel dropping from it on a desktop,
                         a sheet rising from the bottom on a phone. -->
                    <DropdownMenu v-model:open="bellOpen">
                        <DropdownMenuTrigger v-tooltip="trans('common.notifications')" :aria-label="bellLabel" :class="[BELL, 'hidden md:flex']">
                            <Bell class="text-ink size-4.5" />
                            <span v-if="notifications.unread > 0" :class="UNREAD_MARK" aria-hidden="true" />
                        </DropdownMenuTrigger>
                        <!-- Right edge in line with the avatar; the arrow points back up at the bell. -->
                        <DropdownMenuContent
                            align="end"
                            :align-offset="-50"
                            :side-offset="10"
                            :collision-padding="12"
                            class="border-line-strong bg-surface text-ink shadow-float w-110 max-w-[calc(100vw-1.5rem)] overflow-visible rounded-xl p-0"
                        >
                            <span
                                class="border-line-strong bg-surface absolute -top-1.5 right-16 size-3 rotate-45 border-t border-l"
                                aria-hidden="true"
                            />
                            <NotificationPanel
                                :feed="notifications"
                                :following-href="followingHref"
                                @mark-all-read="markAllRead"
                                @open="openNotification"
                                @leave="leaveDropdown"
                            />
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <button type="button" :aria-label="bellLabel" :class="[BELL, 'md:hidden']" @click="sheetOpen = true">
                        <Bell class="text-ink size-4.5" />
                        <span v-if="notifications.unread > 0" :class="UNREAD_MARK" aria-hidden="true" />
                    </button>
                    <BottomSheet v-model:open="sheetOpen" :title="trans('common.notifications')">
                        <NotificationPanel
                            :feed="notifications"
                            :following-href="followingHref"
                            @mark-all-read="markAllRead"
                            @open="openNotification"
                            @leave="leaveDropdown"
                        >
                            <template #close>
                                <DialogClose
                                    :aria-label="trans('common.close')"
                                    class="bg-surface-raised text-ink-muted hover:text-ink flex size-8 cursor-pointer items-center justify-center rounded-lg"
                                >
                                    <X class="size-4.5" />
                                </DialogClose>
                            </template>
                        </NotificationPanel>
                    </BottomSheet>
                </template>

                <DropdownMenu>
                    <DropdownMenuTrigger
                        :aria-label="trans('app.account')"
                        class="from-brand to-brand-strong text-on-accent flex size-10 cursor-pointer items-center justify-center overflow-hidden rounded-[10px] bg-gradient-to-br text-base font-bold transition-transform duration-150 hover:scale-105"
                    >
                        <img
                            v-if="viewer.avatar"
                            :src="viewer.avatar"
                            :alt="viewer.name"
                            width="44"
                            height="44"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <template v-else>{{ getInitials(viewer.name) }}</template>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        :side-offset="10"
                        class="border-line-strong bg-surface text-ink shadow-float w-max min-w-72 rounded-xl px-0 py-2"
                    >
                        <UserMenuContent :user="viewer" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
