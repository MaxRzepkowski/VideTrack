<script setup lang="ts">
/**
 * A chart's card: what it shows, in words, above it — the title names the
 * measure, the line under it the period, the scope and the headline fact.
 */
defineProps<{
    title: string;
    subtitle?: string | null;
}>();
</script>

<template>
    <div class="border-line-subtle bg-surface flex min-w-0 flex-col gap-6 rounded-xl border px-6 pt-7 pb-8 md:px-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h3 class="text-ink m-0 text-lg font-semibold">{{ title }}</h3>
                <p v-if="subtitle" class="text-ink-muted m-0 text-base">{{ subtitle }}</p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </div>
</template>
