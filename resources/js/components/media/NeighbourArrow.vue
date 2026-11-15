<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

/*
 * The way to the season or episode beside this one: pinned to the viewport's
 * side on wide screens, it expands to the neighbour's name on hover.
 */
const props = withDefaults(
    defineProps<{
        href: string;
        direction: 'previous' | 'next';
        /** The neighbour's name, under the caption. */
        title: string;
        /** What follows "Previous" / "Next" in the caption (an episode's code). */
        code?: string | null;
    }>(),
    { code: null },
);

const isNext = computed(() => props.direction === 'next');
const caption = computed(() => [isNext.value ? trans('common.next') : trans('common.previous'), props.code]);
const hint = computed(() => (props.code ? `${props.code} – ${props.title}` : props.title));
</script>

<template>
    <Link
        :href="href"
        :title="hint"
        class="group fixed top-1/2 z-40 hidden -translate-y-1/2 items-center 2xl:flex"
        :class="isNext ? 'right-3 flex-row-reverse' : 'left-3'"
    >
        <span
            class="bg-surface/90 text-ink-secondary group-hover:border-brand group-hover:text-ink border-line-strong flex size-12 shrink-0 items-center justify-center rounded-full border backdrop-blur-sm transition-colors duration-150"
        >
            <ChevronRight v-if="isNext" class="size-6" :stroke-width="2.4" />
            <ChevronLeft v-else class="size-6" :stroke-width="2.4" />
        </span>
        <span
            class="group-hover:max-w-menu max-w-0 overflow-hidden whitespace-nowrap opacity-0 transition-all duration-200 group-hover:opacity-100"
            :class="isNext ? 'mr-0 text-right group-hover:mr-3' : 'ml-0 group-hover:ml-3'"
        >
            <MetaLine :parts="caption" class="text-ink-muted text-sm font-semibold" :class="{ 'justify-end': isNext }" />
            <span class="text-ink block truncate font-semibold">{{ title }}</span>
        </span>
    </Link>
</template>
