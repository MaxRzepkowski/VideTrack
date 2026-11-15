<script setup lang="ts">
import CardBusyOverlay from '@/components/media/CardBusyOverlay.vue';
import CardCaption from '@/components/media/CardCaption.vue';
import MediaActionBar from '@/components/media/MediaActionBar.vue';
import MediaStateMarkers from '@/components/media/MediaStateMarkers.vue';
import ProgressCaption from '@/components/media/ProgressCaption.vue';
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { counterView } from '@/lib/progress';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import { mediaUrl } from '@/lib/urls';
import type { PlayingEntry } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// A title in the Currently Playing / Reading section: games caption the
// total playtime, books / manga / comics a position progress bar (page,
// chapter or issue out of the known total). No pencil in the corner — the
// action bar under the poster already opens the status modal, which edits
// the counter in place. The live state comes from the store (the server's
// answer to every write), so a counter taken back reads as none.
const props = defineProps<{ entry: PlayingEntry }>();

const { stateOf } = useMediaActions();

const state = stateOf(() => props.entry.media);

const poster = computed(() => mediaImage(props.entry.media.posterPath, 'w500'));
const href = computed(() => mediaUrl(props.entry.media));

const amount = computed(() => state.value.progress ?? 0);

const progress = computed(() => counterView(props.entry.media, amount.value));
</script>

<template>
    <div data-tooltip-group class="flex h-full flex-col">
        <!-- The hover colour belongs to the card itself, not to the progress
             below it: reading how far you are is not aiming at the poster. -->
        <div
            class="border-line-subtle bg-surface hover:border-brand xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border transition-colors duration-150"
        >
            <Link :href="href" class="block">
                <div class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
                    <img
                        v-if="poster"
                        :src="poster"
                        :srcset="mediaImageSrcSet(entry.media.posterPath, [185, 342, 500])"
                        :sizes="POSTER_CARD_SIZES"
                        :alt="entry.media.title"
                        width="500"
                        height="750"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line h-full w-full bg-gradient-to-br"></div>
                    <div class="scrim-bottom absolute inset-0"></div>
                    <MediaStateMarkers :media="entry.media" />
                    <UserRatingBadge
                        v-if="state.rating !== null"
                        :rating="state.rating"
                        class="xs:right-3! xs:bottom-3! xs:scale-100 right-2! bottom-2! origin-bottom-right scale-80"
                    />
                    <CardBusyOverlay :media="entry.media" />
                </div>
            </Link>
            <MediaActionBar :media="entry.media" />
            <CardCaption :title="entry.media.title" :subtitle="String(entry.media.year ?? trans('common.n_a'))" :href="href" />
        </div>
        <div class="mt-auto pt-5">
            <div v-if="progress.percent !== null" class="bg-line h-1.5 w-full overflow-hidden rounded-full">
                <div class="bg-brand h-full rounded-full transition-[width] duration-300" :style="{ width: `${progress.percent}%` }"></div>
            </div>
            <ProgressCaption :text="progress.caption" :mobile-text="progress.mobileCaption" :remaining="progress.remaining" />
        </div>
    </div>
</template>
