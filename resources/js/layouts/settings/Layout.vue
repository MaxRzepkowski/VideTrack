<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { navHref, settingsNav } from '@/lib/navigation';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const page = usePage();

// Labels in a computed: they follow a language switched on this very page.
const sidebarNavItems = computed(() => settingsNav().map((entry) => ({ title: entry.label(), href: navHref(entry) })));

const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <div class="max-w-content mx-auto w-full flex-1 px-4 pt-8 pb-20 md:px-10">
        <Heading :title="trans('common.settings')" :description="trans('settings.manage_your_profile_and_account_settings')" />

        <div class="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1 space-x-0">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['h-10 w-full justify-start py-2', { 'bg-surface-raised': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href" :aria-current="currentPath === item.href ? 'page' : undefined">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
