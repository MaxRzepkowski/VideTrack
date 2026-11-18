<script setup lang="ts">
import { mediaImage, mediaImageSrcSet, type ImageSize } from '@/lib/images';
import { computed } from 'vue';

/**
 * A title's poster at the size its card gives it (the size and shape come
 * as classes), or an empty block of the same size where it has none, so the
 * text beside it never jumps. Decorative: the title is written next to it.
 */
const props = defineProps<{
    path: string | null;
    size: ImageSize;
    /** Intrinsic size, reserving the space before the image loads. */
    width: number;
    height: number;
    /** Widths offered to the browser, with `sizes`; just `size` without them. */
    widths?: number[];
    sizes?: string;
}>();

const src = computed(() => mediaImage(props.path, props.size));
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :srcset="widths ? mediaImageSrcSet(path, widths) : undefined"
        :sizes="sizes"
        alt=""
        :width="width"
        :height="height"
        loading="lazy"
        class="bg-surface-raised shrink-0 object-cover"
    />
    <span v-else class="bg-surface-raised block shrink-0" aria-hidden="true" />
</template>
