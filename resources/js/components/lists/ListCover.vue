<script setup lang="ts">
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import type { ListOverview } from '@/types/media';
import { computed } from 'vue';

/**
 * A list's card face: four of its posters side by side — a company logo
 * contained on white, the rest cover-cropped. Whatever a card lays over it
 * goes in the slot.
 */
const props = defineProps<{ list: ListOverview }>();

/** The four tiles, a missing poster an empty one. */
const tiles = computed(() => [0, 1, 2, 3].map((index) => props.list.posterPaths[index] ?? null));
</script>

<template>
    <div class="bg-surface-raised aspect-banner relative grid grid-cols-4 overflow-hidden">
        <div v-for="(tile, index) in tiles" :key="index" :class="tile?.logo ? 'bg-paper flex items-center justify-center p-2' : ''">
            <img
                v-if="tile"
                :src="mediaImage(tile.path, 'w342') ?? undefined"
                :srcset="tile.logo ? undefined : mediaImageSrcSet(tile.path, [185, 342])"
                sizes="(min-width: 1024px) 8vw, (min-width: 640px) 12vw, 25vw"
                alt=""
                width="342"
                height="513"
                loading="lazy"
                draggable="false"
                :class="tile.logo ? 'max-h-full max-w-full object-contain' : 'h-full w-full object-cover'"
            />
        </div>
        <slot />
    </div>
</template>
