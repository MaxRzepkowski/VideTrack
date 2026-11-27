<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { isAtOrUnder, navHref } from '@/lib/navigation';
import { settingsNav } from '@/lib/settingsNavigation';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, useTemplateRef } from 'vue';

withDefaults(
    defineProps<{
        /**
         * How much room the page's content takes: `form` keeps a column a form reads well in,
         * `wide` gives tiles and tables the whole width.
         */
        width?: 'form' | 'wide';
    }>(),
    { width: 'form' },
);

const page = usePage();

// Labels in a computed: they follow a language switched on this very page.
// A page with tabs at addresses of its own (the Instance page) is its entry's
// on every tab.
const sidebarNavItems = computed(() =>
    settingsNav().map((entry) => ({
        title: entry.label(),
        href: navHref(entry),
        icon: entry.icon,
        destructive: entry.destructive === true,
        current: isAtOrUnder(page.url, navHref(entry)),
    })),
);

// On a phone the menu is one scrolling row: bring the open page's entry into view.
const nav = useTemplateRef('nav');
onMounted(() => nav.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'center' }));
</script>

<template>
    <div class="max-w-content mx-auto w-full flex-1 px-4 pt-8 pb-20 md:px-10">
        <Heading :title="trans('common.settings')" :description="trans('settings.manage_your_profile_and_account_settings')" />

        <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-12">
            <aside class="border-line-subtle -mx-4 border-b md:-mx-10 lg:sticky lg:top-24 lg:mx-0 lg:w-56 lg:shrink-0 lg:border-0">
                <nav ref="nav" class="scroll-subtle flex gap-1 overflow-x-auto px-4 pb-3 md:px-10 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="[
                            'h-10 shrink-0 justify-start gap-3 py-2 lg:w-full',
                            { 'bg-surface-raised': item.current, 'text-danger hover:text-danger': item.destructive },
                        ]"
                        as-child
                    >
                        <Link :href="item.href" :aria-current="item.current ? 'page' : undefined">
                            <component
                                :is="item.icon"
                                :class="['size-4 shrink-0', item.destructive ? 'text-danger' : 'text-ink-muted']"
                                aria-hidden="true"
                            />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <section :class="['min-w-0 flex-1 space-y-12', width === 'wide' ? 'lg:max-w-5xl' : 'max-w-2xl']">
                <slot />
            </section>
        </div>
    </div>
</template>
