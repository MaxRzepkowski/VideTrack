<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import type { FactTile } from '@/lib/factTiles';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { computed } from 'vue';

/**
 * A few headline facts of a title as tiles under one heading (a game's time
 * to beat, a manga's rankings), each icon in the title type's colour. Absent
 * when there is nothing to show.
 */
const props = defineProps<{ title: string; tiles: FactTile[]; type: string }>();

const color = computed(() => mediaTypeMeta(props.type).color);
</script>

<template>
    <section v-if="tiles.length">
        <SectionHeading :title="title" />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-for="tile in tiles" :key="tile.key" class="border-line-subtle bg-surface flex items-center gap-4 rounded-xl border p-5">
                <component :is="tile.icon" class="size-7 shrink-0" :style="{ color }" :stroke-width="2" aria-hidden="true" />
                <div class="min-w-0">
                    <div class="text-ink-muted text-sm font-bold">{{ tile.label }}</div>
                    <div class="text-ink text-xl font-extrabold">{{ tile.value }}</div>
                </div>
            </div>
        </div>
    </section>
</template>
