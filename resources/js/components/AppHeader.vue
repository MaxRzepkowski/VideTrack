<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import MainMenu from '@/components/header/MainMenu.vue';
import MenuDrawer from '@/components/header/MenuDrawer.vue';
import MetaLine from '@/components/MetaLine.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { PALETTE_SHORTCUT, useCommandPalette } from '@/composables/useCommandPalette';
import { useNotificationFeed } from '@/composables/useNotificationFeed';
import { formatTimestampDate, getInitials } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { mediaImage } from '@/lib/images';
import { clickTarget, markRead } from '@/lib/notifications';
import { signedInUser } from '@/lib/pageContext';
import type { NotificationEntry } from '@/types/notifications';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Bell, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

// The header is part of the app layout, which only a signed-in user sees.
const viewer = computed(() => signedInUser());

// The search box is the command palette's: it finds your titles and places,
// and its last row searches everywhere.
const palette = useCommandPalette();

// Header bell: unread counter + latest notifications (null for guests). Kept
// current while the tab stays open — the shared prop alone would age until the
// next navigation.
const notificationsOpen = ref(false);
const { feed: notifications, refresh: refreshNotifications } = useNotificationFeed(notificationsOpen);

// Opening is the one moment the list is read closely; ask for it fresh.
watch(notificationsOpen, (open) => {
    if (open) {
        refreshNotifications();
    }
});

/**
 * Every route out of the dropdown closes it first. Left open, an Inertia visit
 * tears the whole header down mid-animation, and radix never gets to undo the
 * `pointer-events: none` it puts on <body> for a modal menu — the app looks
 * frozen. The visit is async, so closing here always lands first.
 */
function leaveDropdown(): void {
    notificationsOpen.value = false;
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
                <button
                    type="button"
                    class="border-line-subtle bg-surface hover:bg-surface-raised hidden h-10 w-60 cursor-pointer items-center gap-2.5 rounded-[10px] border pr-1.5 pl-3 transition-colors duration-150 xl:flex"
                    :aria-keyshortcuts="PALETTE_SHORTCUT"
                    @click="palette.open.value = true"
                >
                    <Search class="text-ink-muted size-4.5 shrink-0" />
                    <span class="text-ink-muted flex-1 text-left text-[15px]">{{ trans('common.search') }}</span>
                    <kbd class="bg-surface-overlay text-ink-muted rounded-md px-2 py-0.5 font-sans text-sm font-medium" aria-hidden="true">
                        {{ trans('app.search_shortcut') }}
                    </kbd>
                </button>
                <button
                    type="button"
                    :aria-label="trans('common.search')"
                    class="bg-surface hover:bg-surface-raised flex size-10 cursor-pointer items-center justify-center rounded-[10px] transition-colors duration-150 xl:hidden"
                    @click="palette.open.value = true"
                >
                    <Search class="text-ink size-4.5" />
                </button>

                <DropdownMenu v-if="notifications" v-model:open="notificationsOpen">
                    <DropdownMenuTrigger
                        v-tooltip="trans('common.notifications')"
                        :aria-label="
                            notifications.unread > 0
                                ? trans('common.notifications_unread', { count: String(notifications.unread) })
                                : trans('common.notifications')
                        "
                        class="bg-surface hover:bg-surface-raised relative flex size-10 cursor-pointer items-center justify-center rounded-[10px] transition-colors duration-150"
                    >
                        <Bell class="text-ink size-4.5" />
                        <span
                            v-if="notifications.unread > 0"
                            aria-hidden="true"
                            class="bg-danger-strong text-on-accent absolute -right-1.5 -bottom-1.5 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-sm font-semibold"
                        >
                            {{ notifications.unread > 9 ? '9+' : notifications.unread }}
                        </span>
                    </DropdownMenuTrigger>
                    <!-- 360px of panel does not fit a 360px phone: it keeps its
                         width where there is room and shrinks to the viewport
                         where there is not. -->
                    <DropdownMenuContent
                        align="end"
                        :collision-padding="12"
                        class="border-line-subtle bg-surface text-ink w-90 max-w-[calc(100vw-1.5rem)] rounded-xl p-2 shadow-xl"
                    >
                        <div class="flex items-baseline justify-between gap-3 px-2 pt-1 pb-2">
                            <span class="text-ink text-sm font-semibold">{{ trans('common.notifications') }}</span>
                            <button
                                v-if="notifications.unread > 0"
                                type="button"
                                class="text-brand-soft hover:text-ink cursor-pointer text-sm font-semibold transition-colors duration-150"
                                @click="markAllRead"
                            >
                                {{ trans('common.mark_all_as_read') }}
                            </button>
                        </div>

                        <!-- Taller than the bell's few rows: the list scrolls
                             inside the menu, header and footer stay put. -->
                        <div v-if="notifications.recent.length" class="scroll-subtle flex max-h-96 flex-col overflow-y-auto overscroll-contain">
                            <a
                                v-for="item in notifications.recent"
                                :key="item.id"
                                :href="item.href"
                                class="hover:bg-surface flex w-full cursor-pointer items-start gap-3 rounded-lg px-2 py-2 text-left transition-colors duration-150"
                                @click="openNotification(item, $event)"
                                @auxclick="openNotification(item, $event)"
                            >
                                <img
                                    v-if="mediaImage(item.imagePath, 'w342')"
                                    :src="mediaImage(item.imagePath, 'w342') ?? undefined"
                                    :alt="item.mediaTitle"
                                    width="36"
                                    height="54"
                                    loading="lazy"
                                    class="bg-surface-overlay aspect-poster w-9 shrink-0 rounded-md object-cover"
                                />
                                <span v-else class="bg-surface-overlay aspect-poster w-9 shrink-0 rounded-md" aria-hidden="true" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-ink line-clamp-2 block text-sm font-semibold"
                                        ><span v-if="!item.readAt" class="sr-only">{{ trans('common.unread') }}, </span>{{ item.title }}</span
                                    >
                                    <MetaLine v-if="item.detail.length" :parts="item.detail" class="text-ink-muted text-sm" />
                                    <span class="text-ink-faint block text-sm">{{ formatTimestampDate(item.createdAt) }}</span>
                                </span>
                                <span v-if="!item.readAt" class="bg-brand mt-1.5 size-2 shrink-0 rounded-full" aria-hidden="true" />
                            </a>
                        </div>

                        <p v-else class="text-ink-muted px-4 py-8 text-center text-sm">{{ trans('common.no_notifications_yet') }}</p>

                        <div class="border-line-subtle mt-1 flex items-center justify-between border-t pt-1">
                            <Link
                                :href="route('notifications.index')"
                                class="text-brand-soft hover:bg-surface hover:text-ink block rounded-lg px-2 py-2 text-center text-sm font-semibold transition-colors duration-150"
                                @click="leaveDropdown"
                            >
                                {{ trans('app.view_all_notifications') }}
                            </Link>
                            <Link
                                :href="route('users.following', { user: viewer.username })"
                                class="text-brand-soft hover:bg-surface hover:text-ink block rounded-lg px-2 py-2 text-center text-sm font-semibold transition-colors duration-150"
                                @click="leaveDropdown"
                            >
                                {{ trans('common.followed') }}
                            </Link>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

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
