<script setup lang="ts">
import { type EpisodeProgress, episodeProgressRows } from '@/lib/progress';
import { computed } from 'vue';

// What a progress bar cannot say on its own, wrapped around whichever bar it
// is: how long the rest takes, how long the part behind took, and how many
// episodes were settled without being watched — the only explanation the grey
// holes ever get. The scope is whatever the caller passes: a whole show on its
// page or an Up Next card, one season on the season page.
//
// The bar and its caption stay the slot's business; this only owns the hint
// and the target for it — both go inside, because the line under the bar reads
// as one thing with it and aiming at a 6px rule alone is a chore. Where there
// is nothing to report (a reading type's position bar, a title with no aired
// episodes) the rows come back null and the wrapper falls silent — no tab
// stop, no cursor, no bubble.
const props = defineProps<{ progress?: EpisodeProgress | null }>();

const rows = computed(() => (props.progress ? episodeProgressRows(props.progress) : null));
</script>

<template>
    <!-- The block is padded out and the padding pulled back off the layout, so
         the target reaches past the bar without moving anything. It is also a
         tab stop where it speaks — the bubble is the only place these numbers
         are said, so a keyboard has to be able to reach them. -->
    <div
        v-tooltip="rows"
        data-tooltip-tap
        :tabindex="rows ? 0 : undefined"
        class="focus-visible:ring-brand -my-2 rounded-lg py-2 focus-visible:ring-2 focus-visible:outline-hidden"
        :class="rows ? 'cursor-pointer' : ''"
    >
        <slot />
    </div>
</template>
