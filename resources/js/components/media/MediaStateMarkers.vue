<script setup lang="ts">
import { useMediaActions } from '@/composables/useMediaActions';
import type { MediaSummary } from '@/types/media';
import { Heart, List } from '@lucide/vue';
import { computed } from 'vue';

/**
 * What a narrow card says on the poster itself: the marks you put on the title,
 * bottom left. Nothing else belongs here —
 *
 * - watched / dropped / on hold are the status cell of the action bar below,
 *   in the open and in the same colour; saying it twice says nothing;
 * - ratings (yours and the community's) are on the title's own page. A poster
 *   the size of a thumb is not where a number is read.
 */
const props = defineProps<{ media: MediaSummary }>();

const { stateOf } = useMediaActions();
const state = stateOf(() => props.media);

const inList = computed(() => state.value.listIds.length > 0);
</script>

<template>
    <div
        v-if="inList || state.favorite"
        class="shadow-card xs:hidden bg-scrim/80 absolute bottom-2 left-2 z-10 flex items-center gap-2 rounded-md px-1.5 py-1 backdrop-blur-sm"
    >
        <List v-if="inList" class="text-action-watchlist size-4.5 shrink-0" :stroke-width="2.5" />
        <Heart v-if="state.favorite" class="fill-action-favorite text-action-favorite size-4 shrink-0" :stroke-width="2.2" />
    </div>
</template>
