<script setup lang="ts">
import MenuRow from '@/components/MenuRow.vue';
import { rowTint } from '@/lib/ui';
import { statusRowText, TONE_LOOK, type ActionTone, type HistorySummary, type StatusRow } from '@/lib/watchedModal';
import type { UserMediaState } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import type { LucideIcon } from '@lucide/vue';
import { Ban, Check, CheckCheck, ChevronDown, ChevronRight, CircleCheck, Clock, EyeOff, History, Pause, Pencil, Repeat, Trash2 } from '@lucide/vue';

/**
 * The rows under the dialog's one action: each a write of its own, a step to
 * one (an arrow on the right), or the history, which unfolds in place (the
 * `history` slot, under its row).
 */
const props = defineProps<{
    rows: StatusRow[];
    media: MediaSummary;
    state: UserMediaState;
    history: HistorySummary;
    historyOpen: boolean;
    /** The title keeps a counter, so completing it asks for the hours (a step) rather than writing at once. */
    withProgress: boolean;
}>();

defineEmits<{ press: [row: StatusRow] }>();

/** Each row's glyph and the kind of write it makes, whose colour it wears; `neutral` for a row that writes nothing. */
const ROW_LOOK: Record<StatusRow, { icon: LucideIcon; tone: ActionTone | 'neutral' }> = {
    wholeRun: { icon: CheckCheck, tone: 'watched' },
    nextEpisode: { icon: Check, tone: 'watched' },
    complete: { icon: CircleCheck, tone: 'watched' },
    addSession: { icon: Clock, tone: 'progress' },
    correctTotal: { icon: Pencil, tone: 'neutral' },
    hold: { icon: Pause, tone: 'hold' },
    drop: { icon: Ban, tone: 'drop' },
    unmark: { icon: EyeOff, tone: 'drop' },
    removeProgress: { icon: Trash2, tone: 'drop' },
    cycles: { icon: Repeat, tone: 'neutral' },
    history: { icon: History, tone: 'neutral' },
};

function tint(row: StatusRow): Record<string, string> {
    const tone = ROW_LOOK[row].tone;

    return rowTint(tone === 'neutral' ? 'neutral' : TONE_LOOK[tone].tint);
}

/** A row that opens a step rather than writing at once. */
function leadsOn(row: StatusRow): boolean {
    return row === 'drop' || row === 'correctTotal' || row === 'addSession' || row === 'cycles' || (row === 'complete' && props.withProgress);
}

const text = (row: StatusRow) => statusRowText(row, props.media, props.state, props.history);
</script>

<template>
    <div class="py-2">
        <template v-for="row in rows" :key="row">
            <MenuRow
                inset="dialog"
                :icon="ROW_LOOK[row].icon"
                :label="text(row).label"
                :description="text(row).description"
                :style="tint(row)"
                :aria-expanded="row === 'history' ? historyOpen : undefined"
                @click="$emit('press', row)"
            >
                <template #trailing>
                    <ChevronRight v-if="leadsOn(row)" class="text-ink-muted size-4.5 shrink-0" aria-hidden="true" />
                    <ChevronDown
                        v-else-if="row === 'history'"
                        class="text-ink-muted size-4.5 shrink-0 transition-transform duration-200"
                        :class="historyOpen ? 'rotate-180' : ''"
                        aria-hidden="true"
                    />
                </template>
            </MenuRow>
            <slot v-if="row === 'history' && historyOpen" name="history" />
        </template>
    </div>
</template>
