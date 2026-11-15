<script setup lang="ts">
/**
 * The one "nothing here" surface. Every list, grid and panel says it the same
 * way: a bordered card with a muted line, and room under it for the action
 * that would fill the emptiness (a search link, a filter reset).
 */
defineProps<{ message: string }>();
</script>

<template>
    <div class="border-line-subtle bg-surface rounded-xl border p-8 text-center">
        <p class="text-ink-muted m-0 text-base">{{ message }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-3">
            <slot />
        </div>
    </div>
</template>
