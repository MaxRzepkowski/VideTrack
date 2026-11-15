<script setup lang="ts">
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The poster face of a card: the art (or its placeholder), the year — or what
 * stands in for it — in the corner, and the type opposite. Whatever a card
 * lays over the art goes in the slot, above all of it.
 */
const props = withDefaults(defineProps<{ media: MediaSummary; cornerBadge?: string | null; showTypeBadge?: boolean }>(), {
    cornerBadge: null,
    showTypeBadge: true,
});

const source = computed(() => props.media.posterPath ?? props.media.backdropPath);
const poster = computed(() => mediaImage(source.value, 'w500'));
const srcset = computed(() => mediaImageSrcSet(source.value, [185, 342, 500]));
</script>

<template>
    <div class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
        <img
            v-if="poster"
            :src="poster"
            :srcset="srcset"
            :sizes="POSTER_CARD_SIZES"
            :alt="media.title"
            width="500"
            height="750"
            loading="lazy"
            class="h-full w-full object-cover"
        />
        <div v-else class="from-surface-raised to-line h-full w-full bg-gradient-to-br"></div>
        <div
            class="text-ink shadow-card xs:top-3 xs:left-3 xs:px-2.5 xs:py-1 bg-scrim/80 absolute top-2 left-2 rounded-md px-1.5 py-0.5 text-sm font-extrabold backdrop-blur-sm"
        >
            {{ cornerBadge ?? media.year ?? trans('common.n_a') }}
        </div>
        <MediaTypeBadge v-if="showTypeBadge" :type="media.type" compact-on-mobile class="xs:top-3 xs:right-3 absolute top-2 right-2" />
        <slot />
    </div>
</template>
