<script setup lang="ts">
import { useMediaActions } from '@/composables/useMediaActions';
import { episodeCode } from '@/lib/format';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { CircleCheck } from '@lucide/vue';
import { computed } from 'vue';

// The "watch next" shortcut as its own block below the status card and the
// actions panel (the third block, not a row inside the first). Only once something is watched — a fresh season showing both
// "Mark as watched" and "Mark next as watched" reads like a bug.
const props = defineProps<{
    media: MediaSummary;
}>();

const { stateOf, markNextEpisodeWatched } = useMediaActions();

const state = stateOf(() => props.media);

const nextEpisode = computed(() => state.value.nextEpisode);

const visible = computed(() => nextEpisode.value !== null && (state.value.watchedEpisodes ?? 0) > 0);

// Inside a season the gap's path carries no season segment — the season
// number comes from the page context.
const nextCode = computed(() =>
    nextEpisode.value ? episodeCode(nextEpisode.value.seasonNumber ?? props.media.number ?? 0, nextEpisode.value.number ?? 0) : null,
);

// One-click "watch next": logs a play for the first gap right away. The server
// picks the episode when the request goes out — the one on screen may be the
// one the click before this has just logged.
function watchNext(): void {
    markNextEpisodeWatched(props.media, 'now');
}
</script>

<template>
    <div v-if="visible && nextEpisode" class="border-line-subtle bg-surface flex flex-col gap-2 rounded-2xl border p-2">
        <!-- The row says what the click does; the tooltip says which episode. -->
        <button
            v-tooltip="nextEpisode.title"
            type="button"
            class="text-ink-muted hover:text-ink-secondary hover:bg-highlight/5 flex w-full items-center gap-3 rounded-xl px-4 py-3.5 text-base font-semibold transition-colors duration-150"
            @click="watchNext"
        >
            <CircleCheck class="text-action-watched size-6 shrink-0" :stroke-width="2.2" />
            <span class="min-w-0 flex-1 truncate text-left">{{ trans('media.mark_next_as_watched') }}</span>
            <span class="text-ink-secondary bg-scrim/30 rounded-md px-2 py-0.5 text-sm font-extrabold">{{ nextCode }}</span>
        </button>
    </div>
</template>
