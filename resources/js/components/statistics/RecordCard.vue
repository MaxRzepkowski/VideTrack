<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

/**
 * One record of the period: what it is, the record itself, when it happened
 * and, below, the picture of it with a line saying what the picture shows.
 */
defineProps<{
    icon: LucideIcon;
    title: string;
    record: string;
    when: string;
    caption?: string | null;
}>();
</script>

<template>
    <div class="border-line-subtle bg-surface flex min-w-0 flex-col gap-4 rounded-xl border px-6 pt-6 pb-7 md:px-7">
        <h3 class="text-ink-secondary m-0 flex items-center gap-2 text-base font-semibold">
            <component :is="icon" class="text-brand-soft size-5 shrink-0" aria-hidden="true" />
            {{ title }}
        </h3>
        <div class="flex flex-col gap-1">
            <p class="text-ink m-0 text-lg font-semibold">{{ record }}</p>
            <p class="text-ink-muted m-0 text-sm">{{ when }}</p>
        </div>
        <slot />
        <p v-if="caption" class="text-ink-faint m-0 mt-auto text-sm">{{ caption }}</p>
    </div>
</template>
