<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { NAV_WORD_CLASS, navWordState } from '@/lib/ui';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/** @view A tab of the Instance page, each at its own address. */
export type InstanceTab = 'overview' | 'tasks' | 'maintenance';

defineProps<{ active: InstanceTab }>();

const tabs: { key: InstanceTab; route: string; label: () => string }[] = [
    { key: 'overview', route: 'settings.instance.index', label: () => trans('tasks.overview') },
    { key: 'tasks', route: 'settings.instance.tasks', label: () => trans('common.tasks') },
    { key: 'maintenance', route: 'settings.instance.maintenance', label: () => trans('tasks.maintenance') },
];
</script>

<template>
    <div class="space-y-4">
        <HeadingSmall :title="trans('common.instance')" :description="trans('tasks.the_server_its_background_tasks_and')" />

        <nav
            :aria-label="trans('common.instance')"
            class="scroll-subtle -mx-4 flex flex-nowrap items-center gap-2 overflow-x-auto px-4 md:mx-0 md:px-0"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="route(tab.route)"
                :aria-current="active === tab.key ? 'page' : undefined"
                :class="[NAV_WORD_CLASS, navWordState(active === tab.key)]"
            >
                {{ tab.label() }}
            </Link>
        </nav>
    </div>
</template>
