<script setup lang="ts">
import { glyphFor } from '@/lib/serviceGlyphs';
import { computed } from 'vue';

/** A service's brand mark by its code; nothing where it has none. */
const props = defineProps<{ code: string }>();

const path = computed(() => glyphFor(props.code));
</script>

<template>
    <svg v-if="path" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path :d="path" />
    </svg>
</template>
