<script setup lang="ts">
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { typeAndYear } from '@/lib/statistics';
import { computed } from 'vue';

/** A title's type glyph in the type's colour, then its type and year: "Movie, 1983". */
const props = defineProps<{
    type: string;
    year: number | null;
}>();

const meta = computed(() => mediaTypeMeta(props.type));
</script>

<template>
    <span class="text-ink-muted flex items-center gap-1.5 text-sm">
        <component :is="meta.icon" class="size-4 shrink-0" :style="{ color: meta.color }" aria-hidden="true" />
        <span class="truncate">{{ typeAndYear(type, year) }}</span>
    </span>
</template>
