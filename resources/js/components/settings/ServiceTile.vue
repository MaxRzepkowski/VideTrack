<script setup lang="ts">
import ServiceLogo from '@/components/ServiceLogo.vue';
import type { LucideIcon } from '@lucide/vue';

/**
 * One service as a tile: its mark over its name. The caller decides what a
 * tile is (a button that picks, a link that downloads, a label around a
 * radio) and puts the rest in the slot: the radio, a corner icon.
 */
withDefaults(
    defineProps<{
        code: string;
        label: string;
        /** The stand-in for a service without a mark of its own. */
        fallback: LucideIcon;
        as?: 'button' | 'a' | 'label';
        selected?: boolean;
    }>(),
    { as: 'button', selected: false },
);
</script>

<template>
    <component
        :is="as"
        :type="as === 'button' ? 'button' : undefined"
        :class="[
            'bg-surface-raised text-ink focus-visible:ring-brand has-focus-visible:ring-brand ring-offset-surface-base relative flex h-full min-h-32 w-full cursor-pointer flex-col items-center gap-3 rounded-xl border px-3 py-4 text-center transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none has-focus-visible:ring-2 has-focus-visible:ring-offset-2',
            selected ? 'border-brand ring-brand ring-1' : 'border-line-subtle hover:border-brand',
        ]"
    >
        <slot />
        <!-- The mark keeps one height and the name two lines' room, so every logo in a row sits level. -->
        <span class="flex h-12 w-full items-center justify-center">
            <ServiceLogo :code="code" :fallback="fallback" class="h-12 max-w-full" />
        </span>
        <span class="line-clamp-2 h-[2.5em] text-sm leading-tight font-medium">{{ label }}</span>
    </component>
</template>
