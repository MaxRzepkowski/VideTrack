<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import type { PaletteItem } from '@/lib/commands';
import { mediaImage } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { computed } from 'vue';

const props = defineProps<{ item: PaletteItem }>();

const poster = computed(() => mediaImage(props.item.posterPath, 'w92'));
const meta = computed(() => mediaTypeMeta(props.item.type));
</script>

<template>
    <!-- Coverless titles keep their place in the list: the type glyph
         stands in for the poster instead of the row collapsing. -->
    <img v-if="poster" :src="poster" alt="" width="32" height="48" loading="lazy" class="bg-surface h-12 w-8 shrink-0 rounded object-cover" />
    <span v-else class="bg-surface flex h-12 w-8 shrink-0 items-center justify-center rounded">
        <component :is="meta.icon" class="size-4" :style="{ color: meta.color }" aria-hidden="true" />
    </span>

    <span class="min-w-0 flex-1">
        <span v-tooltip.truncated="item.title" class="block truncate font-medium">{{ item.title }}</span>
        <!-- Type and year are two facts on one line, so the shared rule sets
             them apart and drops the year on its own when there is none. -->
        <MetaLine :parts="[meta.label(), item.year === null ? null : String(item.year)]" class="text-ink-muted text-sm" />
    </span>
</template>
