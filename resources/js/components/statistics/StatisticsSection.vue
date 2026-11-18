<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

/**
 * One section of the statistics page under its own heading, its `id` an
 * anchor a link can jump to.
 */
defineProps<{
    id: string;
    icon: LucideIcon;
    title: string;
    subtitle: string;
}>();
</script>

<template>
    <section :id="id" :aria-labelledby="`${id}-heading`" class="flex scroll-mt-40 flex-col gap-6 pt-12">
        <div class="flex flex-col gap-1.5 pb-2">
            <h2 :id="`${id}-heading`" class="text-ink m-0 flex items-center gap-3 text-[1.75rem] leading-9 font-semibold">
                <component :is="icon" class="text-brand-soft size-7 shrink-0" aria-hidden="true" />
                {{ title }}
            </h2>
            <p class="text-ink-muted m-0 text-base">{{ subtitle }}</p>
        </div>
        <slot />
    </section>
</template>
