<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import MetaLine from '@/components/MetaLine.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useNotificationFeed } from '@/composables/useNotificationFeed';
import { discoverTypeLook } from '@/lib/discover';
import { formatTimestampDate, getInitials } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { mediaImage } from '@/lib/images';
import { MAIN_NAV, navHref, type NavEntry } from '@/lib/navigation';
import { clickTarget, markRead } from '@/lib/notifications';
import { signedInUser } from '@/lib/pageContext';
import type { SharedData } from '@/types';
import type { NotificationEntry } from '@/types/notifications';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Bell, ChevronDown, Menu, Search, X } from 'lucide-vue-next';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    DialogTrigger,
    HoverCardContent,
    HoverCardPortal,
    HoverCardRoot,
    HoverCardTrigger,
    VisuallyHidden,
} from 'radix-vue';
import { computed, ref, watch } from 'vue';

const page = usePage<SharedData>();
// The header is part of the app layout, which only a signed-in user sees.
const viewer = computed(() => signedInUser());

const mainNavItems = computed(() => MAIN_NAV.map((entry) => ({ ...entry, title: entry.label(), href: navHref(entry) })));

const isActive = (item: NavEntry & { href: string }) =>
    page.url === item.href || (item.prefix !== undefined && (page.url.startsWith(`${item.prefix}/`) || page.url.startsWith(`${item.prefix}?`)));

// Discover mega-menu: every discoverable type the instance offers (shared from
// the backend, in the registry's order, people last), drawn from the one
// vocabulary of media types. Each row takes its type's accent through `--tint`.
const discoverTypes = computed(() =>
    (page.props.discoverTypes ?? []).flatMap((code) => {
        const look = discoverTypeLook(code);

        return look ? [{ code, ...look }] : [];
    }),
);

const TINT = {
    row: 'group-hover:bg-(color:--tint)/10 group-hover:text-ink',
    icon: 'group-hover:border-(color:--tint)/50 group-hover:bg-(color:--tint)/15 group-hover:text-(color:--tint)',
    iconText: 'group-hover:text-(color:--tint)',
    activeRow: 'text-ink',
    activeIcon: 'border-(color:--tint)/50 bg-(color:--tint)/15 text-(color:--tint)',
    activeIconText: 'text-(color:--tint)',
};

const activeDiscoverType = computed(() => {
    const match = page.url.match(/[?&]type=([^&]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
});

// The primary nav collapses into a drawer below md; navigating closes it.
const mobileNavOpen = ref(false);

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
    <header class="bg-surface-base/90 sticky top-0 z-40 h-14 border-b backdrop-blur-md md:h-18">
        <div class="max-w-content mx-auto flex h-full w-full items-center justify-between gap-3 px-3 md:gap-8 md:px-10">
            <div class="flex min-w-0 items-center gap-2 md:gap-10">
                <!-- Hamburger opens the mobile nav drawer (md and below) -->
                <DialogRoot v-model:open="mobileNavOpen">
                    <DialogTrigger
                        :aria-label="trans('app.open_menu')"
                        class="text-ink hover:bg-surface flex size-11 shrink-0 items-center justify-center rounded-xl transition-colors duration-150 md:hidden"
                    >
                        <Menu class="size-5.5" />
                    </DialogTrigger>
                    <DialogPortal>
                        <DialogOverlay
                            class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in data-[state=closed]:fade-out bg-scrim/60 fixed inset-0 z-40 backdrop-blur-sm"
                        />
                        <DialogContent
                            class="border-line-subtle bg-surface shadow-float data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-left data-[state=closed]:slide-out-to-left fixed inset-y-0 left-0 z-50 flex w-72 flex-col gap-2 border-r p-4 duration-300"
                        >
                            <VisuallyHidden>
                                <DialogTitle>{{ trans('app.menu') }}</DialogTitle>
                            </VisuallyHidden>

                            <div class="mb-2 flex items-center justify-between pl-2">
                                <Link :href="route('dashboard')" class="shrink-0" @click="mobileNavOpen = false">
                                    <AppLogo />
                                </Link>
                                <DialogClose
                                    :aria-label="trans('common.close')"
                                    class="text-ink-muted hover:bg-surface hover:text-ink flex size-9 items-center justify-center rounded-lg transition-colors duration-150"
                                >
                                    <X class="size-5" />
                                </DialogClose>
                            </div>

                            <template v-for="item in mainNavItems" :key="item.href">
                                <Link
                                    :href="item.href"
                                    class="flex items-center gap-3 rounded-xl px-3 py-3 text-base font-semibold transition-colors duration-150"
                                    :class="isActive(item) ? 'bg-brand/80 text-on-accent' : 'text-ink-muted hover:bg-surface hover:text-ink'"
                                    @click="mobileNavOpen = false"
                                >
                                    <component :is="item.icon" class="size-5" />
                                    <span>{{ item.title }}</span>
                                </Link>

                                <!-- Discover's types listed inline in the drawer -->
                                <div
                                    v-if="item.id === 'discover' && discoverTypes.length > 0"
                                    class="border-line-subtle mt-0.5 mb-1 ml-6 flex flex-col gap-0.5 border-l pl-3"
                                >
                                    <Link
                                        v-for="entry in discoverTypes"
                                        :key="entry.code"
                                        :href="route('discover', { type: entry.code })"
                                        class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition-colors duration-150"
                                        :class="activeDiscoverType === entry.code ? TINT.activeRow : `text-ink-muted ${TINT.row}`"
                                        :style="{ '--tint': entry.color }"
                                        @click="mobileNavOpen = false"
                                    >
                                        <component
                                            :is="entry.icon"
                                            class="size-4"
                                            :class="activeDiscoverType === entry.code ? TINT.activeIconText : TINT.iconText"
                                        />
                                        <span>{{ entry.label }}</span>
                                    </Link>
                                </div>
                            </template>
                        </DialogContent>
                    </DialogPortal>
                </DialogRoot>

                <Link :href="route('dashboard')" class="shrink-0">
                    <AppLogo />
                </Link>
                <nav class="hidden items-center gap-1 md:flex">
                    <template v-for="item in mainNavItems" :key="item.href">
                        <!-- Discover doubles as a mega-menu: the trigger still navigates to /discover,
                             hovering reveals a panel of every discoverable type. -->
                        <HoverCardRoot v-if="item.id === 'discover' && discoverTypes.length > 0" :open-delay="80" :close-delay="120">
                            <HoverCardTrigger as-child>
                                <Link
                                    :href="item.href"
                                    class="group flex items-center gap-2 rounded-lg px-3.5 py-2 text-base font-semibold transition-colors duration-150"
                                    :class="
                                        isActive(item)
                                            ? 'bg-brand/80 hover:bg-brand/90 text-on-accent'
                                            : 'text-ink-muted hover:bg-surface hover:text-ink'
                                    "
                                >
                                    <component :is="item.icon" class="size-4" />
                                    <span>{{ item.title }}</span>
                                    <ChevronDown class="size-3.5 opacity-60 transition-transform duration-200 group-data-[state=open]:rotate-180" />
                                </Link>
                            </HoverCardTrigger>
                            <HoverCardPortal>
                                <HoverCardContent
                                    :side-offset="10"
                                    align="start"
                                    class="border-line-subtle bg-surface shadow-float data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in data-[state=closed]:fade-out data-[state=open]:zoom-in-95 z-50 w-[30rem] rounded-2xl border p-3"
                                >
                                    <div class="grid grid-cols-2 gap-1">
                                        <Link
                                            v-for="entry in discoverTypes"
                                            :key="entry.code"
                                            :href="route('discover', { type: entry.code })"
                                            class="group flex items-center gap-3 rounded-xl p-2.5 transition-colors duration-150"
                                            :class="activeDiscoverType === entry.code ? TINT.activeRow : `text-ink-secondary ${TINT.row}`"
                                            :style="{ '--tint': entry.color }"
                                        >
                                            <span
                                                class="flex size-10 shrink-0 items-center justify-center rounded-xl border transition-colors duration-150"
                                                :class="
                                                    activeDiscoverType === entry.code
                                                        ? TINT.activeIcon
                                                        : `border-line-subtle bg-surface-raised text-ink-muted ${TINT.icon}`
                                                "
                                            >
                                                <component :is="entry.icon" class="size-5" />
                                            </span>
                                            <span class="min-w-0">
                                                <span class="block truncate text-base font-semibold">{{ entry.label }}</span>
                                                <span class="text-ink-faint block truncate text-sm">{{ entry.description }}</span>
                                            </span>
                                        </Link>
                                    </div>
                                </HoverCardContent>
                            </HoverCardPortal>
                        </HoverCardRoot>

                        <Link
                            v-else
                            :href="item.href"
                            class="flex items-center gap-2 rounded-lg px-3.5 py-2 text-base font-semibold transition-colors duration-150"
                            :class="
                                isActive(item) ? 'bg-brand/80 hover:bg-brand/90 text-on-accent' : 'text-ink-muted hover:bg-surface hover:text-ink'
                            "
                        >
                            <component :is="item.icon" class="size-4" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </template>
                </nav>
            </div>

            <div class="flex shrink-0 items-center gap-3 md:gap-4.5">
                <Link
                    v-tooltip="trans('common.search')"
                    :href="route('search')"
                    class="bg-surface hover:bg-surface-control flex size-10 items-center justify-center rounded-xl border transition-all duration-150 hover:scale-105 md:size-11"
                >
                    <Search class="text-ink-muted size-5" />
                </Link>

                <DropdownMenu v-if="notifications" v-model:open="notificationsOpen">
                    <DropdownMenuTrigger
                        v-tooltip="trans('common.notifications')"
                        :aria-label="
                            notifications.unread > 0
                                ? trans('common.notifications_unread', { count: String(notifications.unread) })
                                : trans('common.notifications')
                        "
                        class="bg-surface hover:bg-surface-control relative flex size-10 cursor-pointer items-center justify-center rounded-xl border transition-all duration-150 hover:scale-105 md:size-11"
                    >
                        <Bell class="text-ink-muted size-5" />
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
                                    class="bg-surface aspect-poster w-9 shrink-0 rounded-md object-cover"
                                />
                                <span v-else class="bg-surface aspect-poster w-9 shrink-0 rounded-md" />
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
                        class="from-brand to-brand-strong text-on-accent flex size-10 cursor-pointer items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br text-base font-bold transition-transform duration-150 hover:scale-105 md:size-11"
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
                    <DropdownMenuContent align="end" class="border-line-subtle bg-surface text-ink w-56 rounded-xl p-2 shadow-xl">
                        <UserMenuContent :user="viewer" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
