<script setup lang="ts">
import EpisodeProgress from '@/components/media/watched/EpisodeProgress.vue';
import MenuRow from '@/components/MenuRow.vue';
import { confirm } from '@/composables/useConfirm';
import { useMediaActions } from '@/composables/useMediaActions';
import { formatTimestampDate, formatTimestampRange } from '@/lib/format';
import { STATUS_VISUALS } from '@/lib/status';
import { rowTint } from '@/lib/ui';
import type { WatchCycleSummary } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Undo2 } from '@lucide/vue';
import { computed } from 'vue';

// The runs of a show that was started over: one row each, newest first, with
// the way back from the newest reset on its own row.
const props = defineProps<{
    media: MediaSummary;
    cycles: WatchCycleSummary[];
}>();

const emit = defineEmits<{ undone: [] }>();

const { cancelRewatch } = useMediaActions();

/** Where a run stands, in the colour of that state: finished, the one under way, or left unfinished. */
function stage(row: WatchCycleSummary): { text: string; color: string } {
    if (row.completed) {
        return { text: trans('common.completed'), color: STATUS_VISUALS.watched.color };
    }

    return row.isActive
        ? { text: trans('common.in_progress'), color: STATUS_VISUALS.inProgress.color }
        : { text: trans('media.unfinished'), color: 'var(--color-ink-muted)' };
}

/** When a run happened: since its start for the one under way, its first to its last play for the rest. */
function when(row: WatchCycleSummary): string | null {
    const started = formatTimestampDate(row.startedAt ?? row.firstPlayAt);

    if (row.isActive) {
        return started === null ? null : trans('media.since_date', { date: started });
    }

    const from = row.startedAt ?? row.firstPlayAt;

    return from !== null && row.lastPlayAt !== null ? formatTimestampRange(from, row.lastPlayAt) : formatTimestampDate(row.lastPlayAt);
}

// The newest reset, the one run that can be taken back.
const cancellable = computed(() => props.cycles.find((row) => row.canCancel) ?? null);

/**
 * Take the newest reset back: the viewings it collected are deleted and the
 * run before it stands as it did. The one row in the modal that destroys more
 * than a single entry, so the question says how many plays go.
 */
async function undo(row: WatchCycleSummary): Promise<void> {
    const confirmed = await confirm({
        message:
            row.plays > 0
                ? transChoice('media.this_deletes_count_play_of_this', row.plays, { count: String(row.plays) })
                : trans('media.undo_the_reset_no_plays'),
        confirmLabel: trans('media.undo_the_reset'),
    });

    if (confirmed) {
        cancelRewatch(props.media);
        emit('undone');
    }
}
</script>

<template>
    <!-- One block per run, newest first: what it is, how far it got, when. -->
    <ul class="flex flex-col gap-2 px-5 pb-4">
        <li v-for="row in cycles" :key="row.number" class="bg-surface-raised flex flex-col gap-2.5 rounded-[10px] px-4 py-3.5">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-ink text-base font-semibold">{{ trans('media.cycle_number', { number: String(row.number) }) }}</span>
                <span class="text-sm font-medium" :style="{ color: stage(row).color }">{{ stage(row).text }}</span>
            </div>

            <EpisodeProgress v-if="row.airedEpisodes > 0" :watched="row.watchedEpisodes" :aired="row.airedEpisodes" :color="stage(row).color" />

            <span v-if="when(row)" class="text-ink-muted text-sm">{{ when(row) }}</span>
        </li>
    </ul>

    <!-- Taking the newest reset back is a write like the dialog's others: a row under the line. -->
    <template v-if="cancellable">
        <div class="border-line border-t" />
        <div class="py-2" :style="rowTint('remove')">
            <MenuRow
                inset="dialog"
                :icon="Undo2"
                :label="trans('media.undo_the_reset')"
                :description="trans('media.deletes_the_plays_of_this_cycle')"
                @click="undo(cancellable)"
            />
        </div>
    </template>
</template>
