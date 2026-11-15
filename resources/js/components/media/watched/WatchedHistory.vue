<script setup lang="ts">
import { confirm } from '@/composables/useConfirm';
import { useMediaActions } from '@/composables/useMediaActions';
import { formatDateTime } from '@/lib/format';
import { formatProgress, positionText, progressFromInput, progressInputMax, progressInputStep, progressToInput } from '@/lib/progress';
import type { MediaSummary, PlayEntry } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { Check, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// The attempt list: every completion and drop with its date, each one
// correctable (the hours or pages it cost) and removable on its own.
const props = defineProps<{
    media: MediaSummary;
    plays: PlayEntry[];
    /** The title counts progress, so every entry carries an amount. */
    withProgress: boolean;
}>();

const emit = defineEmits<{
    /** An entry changed or went: the list is stale. */
    changed: [];
    /** The last entry went: there is no history left to show. */
    emptied: [];
}>();

const { updatePlay, deletePlay } = useMediaActions();

const position = computed(() => positionText(props.media));

const editingId = ref<number | null>(null);
const editAmount = ref<string | number>('');

function startEdit(play: PlayEntry): void {
    editingId.value = play.id;
    editAmount.value = progressToInput(props.media, play.amount);
}

async function saveEdit(play: PlayEntry): Promise<void> {
    const edited = progressFromInput(props.media, editAmount.value);

    editingId.value = null;

    if (edited !== play.amount && (await updatePlay(props.media, play.id, edited))) {
        emit('changed');
    }
}

async function remove(play: PlayEntry): Promise<void> {
    const confirmed = await confirm({ message: trans('media.remove_play_confirm'), confirmLabel: trans('common.remove_this_play') });

    // Removing the last entry leaves nothing of the title's history to show —
    // the modal closes instead of collapsing the list under the cursor.
    const wasLast = props.plays.length === 1;

    if (!confirmed || !(await deletePlay(props.media, play.id))) {
        return;
    }

    if (wasLast) {
        emit('emptied');

        return;
    }

    emit('changed');
}
</script>

<template>
    <div class="mt-1 flex max-h-[34dvh] flex-col overflow-y-auto">
        <div v-for="play in plays" :key="play.id" class="border-line-subtle group flex items-center gap-3 border-b py-2.5">
            <span class="text-ink-secondary text-sm tabular-nums">{{ formatDateTime(play.watchedAt) }}</span>
            <span v-if="play.outcome === 'dropped'" class="text-status-dropped shrink-0 text-sm font-semibold">{{ trans('common.dropped') }}</span>
            <!-- Which run the attempt belongs to. The first one is the norm
                 and goes unsaid. -->
            <span v-if="play.cycle > 1" class="text-ink-faint shrink-0 text-sm font-semibold">{{
                trans('media.cycle_number', { number: String(play.cycle) })
            }}</span>

            <template v-if="editingId === play.id">
                <input
                    v-model="editAmount"
                    type="number"
                    min="0"
                    :max="progressInputMax(media) ?? undefined"
                    :step="progressInputStep(media)"
                    :placeholder="position ? trans('media.e_g_250') : trans('media.e_g_40')"
                    :aria-label="position?.editAmounts ?? trans('media.edit_hours')"
                    class="border-line bg-surface text-ink focus:border-brand-soft ml-auto w-20 rounded-lg border px-2.5 py-1 text-base font-semibold tabular-nums [color-scheme:dark] focus:outline-none"
                    @keyup.enter="saveEdit(play)"
                />
                <button
                    v-tooltip="trans('common.save')"
                    type="button"
                    class="bg-brand text-on-accent flex size-7 shrink-0 items-center justify-center rounded-lg transition-opacity duration-150 hover:opacity-90"
                    @click="saveEdit(play)"
                >
                    <Check class="size-4" :stroke-width="2.5" />
                </button>
            </template>

            <template v-else>
                <span v-if="withProgress" class="text-ink ml-auto text-sm font-semibold tabular-nums">
                    {{ formatProgress(media, play.amount) ?? position?.noneRecorded ?? trans('media.no_playtime_recorded') }}
                </span>
                <span v-else class="ml-auto"></span>
                <!-- Revealed on hover where there is one; a touch screen has
                     none, so there they simply stay. -->
                <span
                    class="flex shrink-0 gap-0.5 opacity-0 transition-opacity duration-150 group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100"
                >
                    <button
                        v-if="withProgress"
                        v-tooltip="position?.editAmounts ?? trans('media.edit_hours')"
                        type="button"
                        class="text-ink-faint hover:bg-surface-raised hover:text-ink flex size-7 items-center justify-center rounded-lg transition-colors duration-150"
                        @click="startEdit(play)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        v-tooltip="trans('common.remove_this_play')"
                        type="button"
                        class="text-ink-faint hover:bg-surface-raised hover:text-danger flex size-7 items-center justify-center rounded-lg transition-colors duration-150"
                        @click="remove(play)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </span>
            </template>
        </div>
    </div>
</template>
