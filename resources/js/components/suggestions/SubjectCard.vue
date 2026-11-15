<script setup lang="ts">
import EpisodeProgressBar from '@/components/dashboard/EpisodeProgressBar.vue';
import CardCaption from '@/components/media/CardCaption.vue';
import ProgressCaption from '@/components/media/ProgressCaption.vue';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import type { EpisodeSegment } from '@/types/media';
import type { SuggestionSubject } from '@/types/suggestions';
import { Link } from '@inertiajs/vue3';
import { Layers } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * A subject of a suggestion kind (a collection) as a poster card: the poster
 * of the first title it puts forward and its name; under the card, how far
 * the user is through it, drawn the way Up Next draws a show's progress.
 */
const props = defineProps<{ subject: SuggestionSubject; href: string }>();

const poster = computed(() => mediaImage(props.subject.posterPath, 'w342'));

const segments = computed<EpisodeSegment[] | null>(() => {
    const { finished, total } = props.subject;

    if (finished === null || total === null || total <= 0) {
        return null;
    }

    return [
        { state: 'watched', episodes: finished },
        { state: 'pending', episodes: total - finished },
    ];
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div
            class="border-line-subtle bg-surface hover:border-brand xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border transition-colors duration-150"
        >
            <Link :href="href" class="block">
                <div class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
                    <img
                        v-if="poster"
                        :src="poster"
                        :srcset="mediaImageSrcSet(subject.posterPath, [185, 342])"
                        :sizes="POSTER_CARD_SIZES"
                        alt=""
                        width="342"
                        height="513"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                        <Layers class="text-ink-muted size-10" aria-hidden="true" />
                    </div>
                </div>
            </Link>
            <CardCaption :title="subject.title" :href="href" />
        </div>
        <div v-if="subject.caption !== null" class="mt-auto pt-5">
            <EpisodeProgressBar v-if="segments" :segments="segments" />
            <ProgressCaption :text="subject.caption" :remaining="subject.count" />
        </div>
    </div>
</template>
