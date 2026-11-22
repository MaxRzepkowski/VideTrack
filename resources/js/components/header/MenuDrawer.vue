<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { currentMenuRow, DASHBOARD, groupItemHref, NAV_GROUPS, navHref } from '@/lib/navigation';
import { signedInUser } from '@/lib/pageContext';
import { rowTint } from '@/lib/ui';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Menu, X } from '@lucide/vue';
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, DialogTrigger, VisuallyHidden } from 'radix-vue';
import { computed, ref } from 'vue';

/**
 * The main menu where the bar does not fit: the same groups, flat, each under
 * its word. Here the row of the page you are on is lit, as nothing else in the
 * drawer says where you are.
 */

const page = usePage();
const viewer = computed(() => signedInUser());

// Navigating from the drawer closes it.
const open = ref(false);

const current = computed(() => currentMenuRow(page.url, viewer.value.username));

const rows = computed(() =>
    NAV_GROUPS.map((group) => ({
        group,
        items: group.items.map((item) => ({ entry: item.entry, href: groupItemHref(item, viewer.value.username) })),
    })),
);

const dashboardHref = navHref(DASHBOARD);

const ROW = 'flex items-center gap-3 rounded-xl px-3 py-2.5 text-base font-semibold transition-colors duration-150';
const rowState = (lit: boolean): string => (lit ? 'bg-surface-overlay text-ink' : 'text-ink-secondary hover:bg-surface-raised hover:text-ink');
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogTrigger
            :aria-label="trans('app.open_menu')"
            class="text-ink hover:bg-surface flex size-11 shrink-0 items-center justify-center rounded-xl transition-colors duration-150 xl:hidden"
        >
            <Menu class="size-5.5" />
        </DialogTrigger>
        <DialogPortal>
            <DialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in data-[state=closed]:fade-out bg-scrim/60 fixed inset-0 z-40 backdrop-blur-sm"
            />
            <DialogContent
                class="border-line-subtle bg-surface shadow-float data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-left data-[state=closed]:slide-out-to-left fixed inset-y-0 left-0 z-50 flex w-72 flex-col gap-1 overflow-y-auto border-r p-4 duration-300"
            >
                <VisuallyHidden>
                    <DialogTitle>{{ trans('app.menu') }}</DialogTitle>
                </VisuallyHidden>

                <div class="mb-2 flex items-center justify-between pl-2">
                    <Link :href="dashboardHref" class="shrink-0" @click="open = false">
                        <AppLogo />
                    </Link>
                    <DialogClose
                        :aria-label="trans('common.close')"
                        class="text-ink-muted hover:bg-surface-raised hover:text-ink flex size-9 items-center justify-center rounded-lg transition-colors duration-150"
                    >
                        <X class="size-5" />
                    </DialogClose>
                </div>

                <Link :href="dashboardHref" :class="[ROW, rowState(current === DASHBOARD.id)]" @click="open = false">
                    <component :is="DASHBOARD.icon" class="size-5" />
                    <span>{{ DASHBOARD.label() }}</span>
                </Link>

                <section
                    v-for="{ group, items } in rows"
                    :key="group.id"
                    class="mt-3"
                    :aria-labelledby="`drawer-${group.id}`"
                    :style="rowTint(group.id)"
                >
                    <h2 :id="`drawer-${group.id}`" class="text-ink-muted px-3 pb-1 text-sm font-semibold">{{ group.label() }}</h2>
                    <Link
                        v-for="item in items"
                        :key="item.entry.id"
                        :href="item.href"
                        :class="[ROW, rowState(current === item.entry.id)]"
                        :aria-current="current === item.entry.id ? 'page' : undefined"
                        @click="open = false"
                    >
                        <component :is="item.entry.icon" class="size-5 text-(color:--tint)" />
                        <span>{{ item.entry.label() }}</span>
                    </Link>
                </section>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
