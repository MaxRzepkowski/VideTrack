<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { SELECT_CLASS } from '@/lib/ui';
import { videoEmbedUrl, videoThumbnail } from '@/lib/videos';
import type { VideoKind } from '@/types/generated';
import type { VideoData } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { Play } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    videos: VideoData[];
}>();

// The kinds of video, in offer order, with what the select calls them.
const KINDS: { kind: VideoKind; label: () => string }[] = [
    { kind: 'Trailer', label: () => trans('media.video_trailer') },
    { kind: 'Teaser', label: () => trans('media.video_teaser') },
    { kind: 'Clip', label: () => trans('media.video_clip') },
    { kind: 'Featurette', label: () => trans('media.video_featurette') },
    { kind: 'Behind the Scenes', label: () => trans('media.video_behind_the_scenes') },
    { kind: 'Bloopers', label: () => trans('media.video_bloopers') },
];

const types = computed(() => KINDS.filter(({ kind }) => props.videos.some((v) => v.type === kind)));

// Trailers by default: the other kinds are likelier to spoil.
const selectedType = ref<string | null>(null);
const currentType = computed(() => selectedType.value ?? types.value[0]?.kind ?? null);

// Newest first within the chosen type: TMDB serves them in upload order, which
// can put a decade-old teaser above the trailer that actually sold the film.
// Undated entries sort last rather than jumping the queue.
const tiles = computed(() =>
    props.videos
        .filter((v) => v.type === currentType.value)
        .sort((a, b) => (b.publishedAt ?? '').localeCompare(a.publishedAt ?? ''))
        .map((video) => ({ video, thumbnail: videoThumbnail(video) })),
);

const playing = ref<VideoData | null>(null);
</script>

<template>
    <section v-if="types.length > 0">
        <SectionHeading :title="trans('media.trailers_videos')">
            <select
                :value="currentType ?? ''"
                :class="SELECT_CLASS"
                :aria-label="trans('media.trailers_videos')"
                @change="selectedType = ($event.target as HTMLSelectElement).value"
            >
                <option v-for="type in types" :key="type.kind" :value="type.kind">{{ type.label() }}</option>
            </select>
        </SectionHeading>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="{ video, thumbnail: still } in tiles"
                :key="video.key"
                type="button"
                class="border-line-subtle hover:border-brand bg-surface-raised relative aspect-video overflow-hidden rounded-xl border transition-colors duration-150"
                @click="playing = video"
            >
                <img
                    v-if="still"
                    :src="still"
                    :alt="video.name"
                    width="480"
                    height="360"
                    loading="lazy"
                    class="absolute inset-0 h-full w-full object-cover"
                />
                <!-- Thumbnail-less tiles keep the play button and the title, so
                     the grid stays one shape whatever the video sits on. -->
                <div class="absolute inset-0 flex items-center justify-center" :class="still ? 'bg-scrim/30' : ''">
                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-full border"
                        :class="still ? 'border-on-photo/70 bg-scrim/60' : 'border-line-strong bg-surface'"
                    >
                        <Play class="size-4" :class="still ? 'fill-on-photo text-on-photo' : 'fill-ink text-ink'" />
                    </div>
                </div>
                <!-- Over an image the caption needs its own contrast; over the
                     bare surface it is ordinary text and takes the theme's. -->
                <div class="absolute right-0 bottom-0 left-0 p-2 text-left" :class="still ? 'from-scrim/80 bg-gradient-to-t to-transparent' : ''">
                    <div class="truncate text-sm font-semibold" :class="still ? 'text-on-photo' : 'text-ink'">{{ video.name }}</div>
                </div>
            </button>
        </div>

        <Dialog :open="playing !== null" @update:open="(open: boolean) => !open && (playing = null)">
            <DialogContent class="bg-scrim max-w-4xl border-none p-0">
                <DialogTitle class="sr-only">{{ playing?.name }}</DialogTitle>
                <iframe
                    v-if="playing"
                    :src="videoEmbedUrl(playing)"
                    :title="playing.name"
                    class="aspect-video w-full"
                    allow="autoplay; encrypted-media; fullscreen"
                    allowfullscreen
                ></iframe>
            </DialogContent>
        </Dialog>
    </section>
</template>
