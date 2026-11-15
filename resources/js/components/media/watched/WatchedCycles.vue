<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import { confirm } from '@/composables/useConfirm';
import { useMediaActions } from '@/composables/useMediaActions';
import { formatTimestampDate } from '@/lib/format';
import type { WatchCycleSummary } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';

// The runs of a show that was started over: one row each, newest first, with
// the way back from the newest reset on its own row.
const props = defineProps<{
    media: MediaSummary;
    cycles: WatchCycleSummary[];
}>();

const emit = defineEmits<{ undone: [] }>();

const { cancelRewatch } = useMediaActions();

/** What one run reads as: where it stands, how far it got, and when. */
function parts(row: WatchCycleSummary): (string | null)[] {
    const stage = row.completed ? trans('common.completed') : row.isActive ? trans('common.in_progress') : trans('media.unfinished');
    const started = formatTimestampDate(row.startedAt ?? row.firstPlayAt);
    const when = row.isActive ? (started === null ? null : trans('media.since_date', { date: started })) : formatTimestampDate(row.lastPlayAt);

    return [
        trans('media.cycle_number', { number: String(row.number) }),
        stage,
        row.airedEpisodes > 0 ? `${row.watchedEpisodes} / ${row.airedEpisodes}` : null,
        when,
    ];
}

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
    <div class="mt-4 flex flex-col">
        <div v-for="row in cycles" :key="row.number" class="border-line-subtle flex items-center gap-3 border-t py-2.5">
            <MetaLine :parts="parts(row)" class="text-ink-secondary min-w-0 text-sm" />
            <button
                v-if="row.canCancel"
                v-tooltip="trans('media.deletes_the_plays_of_this_cycle')"
                type="button"
                class="text-ink-faint hover:text-danger ml-auto shrink-0 text-sm font-semibold transition-colors duration-150"
                @click="undo(row)"
            >
                {{ trans('media.undo_the_reset') }}
            </button>
        </div>
    </div>
</template>
