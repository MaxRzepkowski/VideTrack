<script setup lang="ts">
import { inRegistryOrder, mediaTypeMeta } from '@/lib/mediaTypes';
import { computed } from 'vue';

/** What the colours of a chart drawn by type stand for: each type's glyph in its colour, in the registry's order. */
const props = defineProps<{
    types: string[];
}>();

const ordered = computed(() => inRegistryOrder([...new Set(props.types)]));
</script>

<template>
    <p class="m-0 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <span v-for="code in ordered" :key="code" class="text-ink-secondary flex items-center gap-1.5">
            <component :is="mediaTypeMeta(code).icon" class="size-[18px]" :style="{ color: mediaTypeMeta(code).color }" aria-hidden="true" />
            {{ mediaTypeMeta(code).plural() }}
        </span>
    </p>
</template>
