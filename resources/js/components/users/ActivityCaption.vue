<script setup lang="ts">
import { formatDateTime, formatNumber } from '@/lib/format';
import type { ActivityEntry } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Star, Trash2 } from '@lucide/vue';
import { computed } from 'vue';

/**
 * The line under a card of a log panel: the date, or — while a rating orders
 * a visitor's page — the owner's rating, right under the poster that carries
 * the visitor's own, so the two read one over the other.
 */
const props = defineProps<{
    entry: ActivityEntry;
    ownerName: string;
    isOwner: boolean;
    /** The server's call: the owner's rating stands here instead of the date. */
    ownerRatingShown: boolean;
    /** Owner-only removal; null when the panel has nothing to remove. */
    removeLabel: string | null;
}>();

const emit = defineEmits<{ remove: [] }>();

const timesFinished = computed(() =>
    props.entry.completions === null
        ? null
        : transChoice('users.times_finished', props.entry.completions, { count: formatNumber(props.entry.completions) }),
);

const ownerRating = computed(() =>
    props.entry.rating === null
        ? trans('users.owner_unrated', { name: props.ownerName })
        : trans('users.owner_rated', { name: props.ownerName, rating: String(props.entry.rating) }),
);
</script>

<template>
    <div class="text-ink-faint mt-2 flex items-center justify-center gap-2 text-sm font-semibold">
        <span v-if="ownerRatingShown" v-tooltip="ownerRating" class="text-ink-secondary flex items-center gap-1">
            <Star v-if="entry.rating !== null" class="text-action-rating size-3.5 fill-current" aria-hidden="true" />
            <span aria-hidden="true">{{ entry.rating ?? '–' }}</span>
            <span class="sr-only">{{ ownerRating }}</span>
        </span>
        <template v-else>
            <template v-if="entry.completions !== null">
                <span v-tooltip="timesFinished">
                    <span aria-hidden="true">{{ trans('users.times_finished_short', { count: formatNumber(entry.completions) }) }}</span>
                    <span class="sr-only">{{ timesFinished }}</span>
                </span>
                <span class="bg-line h-2 w-px shrink-0" aria-hidden="true"></span>
            </template>
            <span>{{ formatDateTime(entry.at) }}</span>
        </template>
        <!-- The page asks before the entry goes. -->
        <button
            v-if="isOwner && removeLabel !== null && entry.id !== null"
            v-tooltip="removeLabel"
            :aria-label="removeLabel"
            type="button"
            class="text-ink-faint hover:text-danger flex items-center gap-1 rounded-md px-1.5 py-0.5 transition-colors duration-150"
            @click="emit('remove')"
        >
            <Trash2 class="size-3.5" />
        </button>
    </div>
</template>
