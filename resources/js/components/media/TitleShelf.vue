<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { useShelfLimit } from '@/composables/useShelfLimit';
import type { MediaSummary } from '@/types/media';
import { computed, ref } from 'vue';

// One shelf of titles tied to the page's own (a game's DLC, what a bundle
// holds, a series' spin-offs): about a dozen cards rounded up to a whole row,
// the rest behind the toggle.
const props = defineProps<{ title: string; items: MediaSummary[] }>();

const LIMIT = useShelfLimit(12);
const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.items : props.items.slice(0, LIMIT.value)));
</script>

<template>
    <section v-if="items.length" class="pt-16">
        <SectionHeading :title="title" />
        <MediaGrid>
            <MediaCard v-for="media in visible" :key="media.ref" :media="media" />
        </MediaGrid>
        <ShowMoreToggle v-if="items.length > LIMIT" v-model="expanded" :hidden-count="items.length - LIMIT" />
    </section>
</template>
