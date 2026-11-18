<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';

/**
 * A section of the statistics page that arrives after the page: a pulsing
 * block holds its place until its props land.
 */
defineProps<{
    /** The deferred props the section waits for. */
    data: string | string[];
}>();
</script>

<template>
    <Deferred :data="data">
        <template #fallback>
            <div class="border-line-subtle bg-surface mt-12 h-96 animate-pulse rounded-xl border" aria-hidden="true" />
        </template>
        <slot />
    </Deferred>
</template>
