<script setup lang="ts">
import HeroContainer from '@/components/HeroContainer.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { videoEmbedUrl } from '@/lib/videos';
import type { VideoData } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { Play } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        backdropPath: string | null;
        videos?: VideoData[];
        /** Fallback hero color for types without backdrops (books: the cover's average color). */
        color?: string | null;
    }>(),
    { videos: () => [], color: null },
);

// The hero renders up to 1530px wide, so w1280 would be upscaled on every desktop.
// w1920 is the next step up and stays bounded, unlike `original`.
const backdrop = computed(() => mediaImage(props.backdropPath, 'w1920'));

// A flat 420px block of one color is overwhelming — fade it top-to-bottom
// into the page background instead.
const colorFill = computed(() =>
    // color-mix rather than a hex alpha suffix, so a palette token works as well as a cover's hex.
    props.color
        ? `linear-gradient(180deg, ${props.color} 0%, color-mix(in srgb, ${props.color} 40%, transparent) 45%, var(--color-surface) 100%)`
        : null,
);

// Main trailer: official trailers first, then any trailer, then any video.
const trailer = computed<VideoData | null>(() => {
    const trailers = props.videos.filter((v) => v.type === 'Trailer');

    return trailers.find((v) => v.official) ?? trailers[0] ?? props.videos[0] ?? null;
});

const playing = ref(false);
</script>

<template>
    <!-- Container-wide backdrop (1530 × 420px, rounded) -->
    <HeroContainer>
        <div class="relative mt-8 h-52 w-full overflow-hidden rounded-2xl sm:h-72 md:h-105">
            <img
                v-if="backdrop"
                :src="backdrop"
                :srcset="mediaImageSrcSet(backdropPath, [780, 1280, 1920])"
                sizes="(min-width: 1536px) 1530px, 100vw"
                alt=""
                width="1920"
                height="1080"
                loading="lazy"
                class="h-full w-full object-cover"
            />
            <div v-else-if="colorFill" class="h-full w-full" :style="{ background: colorFill }"></div>
            <div v-else class="bg-surface h-full w-full"></div>

            <button
                v-if="trailer"
                v-tooltip="trans('media.play_trailer')"
                type="button"
                class="hover:border-brand hover:bg-brand border-on-photo/70 bg-scrim/50 absolute top-1/2 left-1/2 flex h-20 w-20 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 transition-all duration-200 hover:scale-110"
                @click="playing = true"
            >
                <Play class="fill-on-photo text-on-photo size-7" />
            </button>

            <Dialog v-model:open="playing">
                <DialogContent class="bg-scrim max-w-4xl border-none p-0">
                    <DialogTitle class="sr-only">{{ trailer?.name }}</DialogTitle>
                    <iframe
                        v-if="playing && trailer"
                        :src="videoEmbedUrl(trailer)"
                        :title="trailer.name || trans('media.trailer')"
                        class="aspect-video w-full"
                        allow="autoplay; encrypted-media; fullscreen"
                        allowfullscreen
                    ></iframe>
                </DialogContent>
            </Dialog>
        </div>
    </HeroContainer>
</template>
