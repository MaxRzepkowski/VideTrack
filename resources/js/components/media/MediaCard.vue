<script setup lang="ts">
import CardBusyOverlay from '@/components/media/CardBusyOverlay.vue';
import CardCaption from '@/components/media/CardCaption.vue';
import MediaActionBar from '@/components/media/MediaActionBar.vue';
import MediaStateMarkers from '@/components/media/MediaStateMarkers.vue';
import PosterArt from '@/components/media/PosterArt.vue';
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { cardBadge, hasStateMarkers } from '@/lib/badges';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        media: MediaSummary;
        /** Extra line under the title (e.g. a person's role, episode count). */
        subtitle?: string | string[] | null;
        showTypeBadge?: boolean;
        /** Replaces the year badge (e.g. an episode's SxxEyy code). */
        cornerBadge?: string | null;
        /**
         * Your own marks on the card — the action bar and the rating badge.
         * Off where the page never asked the server for them: drawn from the
         * empty default they would report every title as untouched.
         */
        withActions?: boolean;
    }>(),
    { subtitle: null, showTypeBadge: true, cornerBadge: null, withActions: true },
);

const { stateOf } = useMediaActions();

const state = stateOf(() => props.media);

const href = computed(() => mediaUrl(props.media));
const badge = computed(() => cardBadge(props.media));
const markersVisible = computed(() => props.withActions && hasStateMarkers(state.value));
</script>

<template>
    <!-- One card is one tooltip group: the hints inside it hand over to each
         other instantly, the next card waits out the delay. -->
    <div data-tooltip-group class="group flex flex-col">
        <div
            class="border-line-subtle bg-surface group-hover:border-brand xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border transition-colors duration-150"
        >
            <Link :href="href" class="block">
                <PosterArt :media="media" :corner-badge="cornerBadge" :show-type-badge="showTypeBadge">
                    <div class="scrim-bottom absolute inset-0"></div>
                    <div
                        v-if="badge"
                        class="shadow-card xs:bottom-3 xs:left-3 xs:px-2.5 xs:py-1 text-on-photo absolute left-2 rounded-md px-1.5 py-0.5 text-sm font-extrabold"
                        :class="markersVisible ? 'bottom-9' : 'bottom-2'"
                        :style="{ background: badge.color }"
                    >
                        {{ badge.label }}
                    </div>
                    <MediaStateMarkers v-if="withActions" :media="media" />
                    <UserRatingBadge
                        v-if="withActions && state.rating !== null"
                        :rating="state.rating"
                        class="xs:right-3! xs:bottom-3! xs:scale-100 right-2! bottom-2! origin-bottom-right scale-80"
                    />
                    <CardBusyOverlay :media="media" />
                </PosterArt>
            </Link>
            <MediaActionBar v-if="withActions" :media="media" />
            <CardCaption :title="media.title" :subtitle="subtitle" :href="href" :subtitle-href="href" />
        </div>
    </div>
</template>
