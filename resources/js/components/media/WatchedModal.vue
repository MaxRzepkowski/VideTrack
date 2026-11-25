<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import EpisodeProgress from '@/components/media/watched/EpisodeProgress.vue';
import StatusRows from '@/components/media/watched/StatusRows.vue';
import StatusAction from '@/components/media/watched/StatusAction.vue';
import WatchedCounter from '@/components/media/watched/WatchedCounter.vue';
import WatchedCycles from '@/components/media/watched/WatchedCycles.vue';
import WatchedHistory from '@/components/media/watched/WatchedHistory.vue';
import MetaLine from '@/components/MetaLine.vue';
import AppModal from '@/components/modal/AppModal.vue';
import { confirm } from '@/composables/useConfirm';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { usePlayHistory } from '@/composables/usePlayHistory';
import { localDateTimeInput } from '@/lib/format';
import { hasProgress, isPositionCounter, parseDecimal, positionText, progressFromInput, progressToInput, playActionLabels } from '@/lib/progress';
import {
    actionLabel,
    actionTone,
    dropDescription,
    episodeStep,
    nextEpisodeLabel,
    openingIntent,
    statusCaption,
    statusDescription,
    statusFacts,
    statusRows,
    stepName,
    unmarkCount,
    watchedAtChoice,
    type ActionTone,
    type StatusRow,
    type WatchedIntent,
    type WhenMode,
} from '@/lib/watchedModal';
import type { LucideIcon } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ArrowLeft, Ban, Check, Play, Plus, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

// THE status modal (attempt model): one surface for everything that writes
// the user's relationship with a title. It is built around the ONE action
// the title's state asks for — completing it, logging progress, taking a
// status off — in the colour of its kind of write, with when beside it for a
// dated write. The rarer writes are rows under it, each saying what it does;
// a row that needs more (a drop's date, a corrected total, the hours of a
// completion) opens a step with a way back. There are no status tabs: a tab
// strip charges every visit for a choice already made when the modal opens.
const { modals, close } = useMediaModals();
const { isPlayable, markWatched, markNextEpisodeWatched, unmarkWatched, startRewatch, logProgress, setStatus, discardProgress } = useMediaActions();

const media = computed(() => modals.watched);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));

const intent = ref<WatchedIntent>('complete');
// The view the modal opened on; any other is a step, with a way back to it.
const mainIntent = ref<WatchedIntent>('complete');
const mode = ref<WhenMode>('now');
const customDate = ref('');

// The amount model holds the INPUT unit (hours / pages) and converts on
// submit; it holds a number once edited (type="number" inputs auto-apply
// .number).
const amount = ref<string | number>('');
const amountDirty = ref(false);

// Inside the session view: the field switched from "hours of this session"
// (which ADD) to the running total (which OVERWRITES). Games only — a
// position counter has one number and every save already overwrites it.
const correcting = ref(false);

// Completing over a latest dropped attempt: absorb its frozen hours or start
// the counter fresh. One quiet toggle, not a pair of slabs — the default is
// right almost every time.
const mergeDropped = ref(true);

const historyOpen = ref(false);
// The runs behind a show, a step of their own: each one, and the newest reset to undo.
const cyclesOpen = ref(false);

const { plays, cycles, loadPlays, loadCycles, clear: clearHistory } = usePlayHistory(() => media.value);

const withProgress = computed(() => media.value !== null && hasProgress(media.value));
const container = computed(() => media.value !== null && !isPlayable(media.value));
const position = computed(() => (media.value ? positionText(media.value) : null));

// The counter prefills with everything known about the attempt: logged
// sessions plus — when finishing over a dropped attempt — the amount frozen
// at the drop. The visible number IS the merge; the user can correct it
// before confirming. Position counters (pages / chapters / issues) don't add
// up: they take the furthest known position instead.
function prefillAmount(): void {
    const current = media.value;

    if (!current) {
        return;
    }

    const progress = state.value?.progress ?? 0;
    const frozen = state.value?.latestDropped && mergeDropped.value ? (state.value?.droppedAmount ?? 0) : 0;

    amount.value = progressToInput(current, isPositionCounter(current) ? Math.max(progress, frozen) : progress + frozen);
    amountDirty.value = false;
}

/**
 * What the single number field holds, per view. Completing carries the
 * merged total; a game session starts empty because it ADDS; everything
 * else (a position counter, a correction, the amount frozen into a drop)
 * starts at the live counter.
 */
function resetAmount(): void {
    const current = media.value;

    if (!current || !hasProgress(current)) {
        return;
    }

    if (intent.value === 'complete') {
        prefillAmount();

        return;
    }

    amount.value =
        intent.value === 'session' && !correcting.value && !isPositionCounter(current) ? '' : progressToInput(current, state.value?.progress ?? 0);

    amountDirty.value = false;
}

// At once as well: the modal mounts the first time it is asked for, already open.
watch(
    media,
    (opened) => {
        if (!opened || !state.value) {
            return;
        }

        intent.value = openingIntent(opened, state.value, modals.watchedTab);
        mainIntent.value = intent.value;
        mode.value = 'now';
        customDate.value = localDateTimeInput();
        correcting.value = false;
        mergeDropped.value = true;
        historyOpen.value = false;
        cyclesOpen.value = false;
        clearHistory();

        resetAmount();

        if (isPlayable(opened)) {
            void loadPlays();
        }

        // Only a show that has already been started over has a history worth
        // fetching: without a reset there is exactly one cycle, and the status
        // line says everything about it.
        if (opened.type === 'show' && state.value.cycle > 1) {
            void loadCycles();
        }
    },
    { immediate: true },
);

watch(mergeDropped, prefillAmount);

// Every view fills the field from its own source — switching between them
// must not carry the previous view's number over.
watch([intent, correcting], () => {
    if (intent.value !== 'session') {
        correcting.value = false;
    }

    resetAmount();
});

// Status writes (drop / un-drop / completion) change the entries — refresh
// the list whenever the resolved status flips while the modal is open. Not
// on opening: that read is already on its way.
watch(
    () => [media.value, state.value?.status] as const,
    ([current, status], [previous, before]) => {
        if (current && current === previous && status !== before && isPlayable(current)) {
            void loadPlays();
        }
    },
);

// ---- What the modal shows ----

const isStep = computed(() => intent.value !== mainIntent.value || correcting.value || cyclesOpen.value);

// The caption carries the counter even beside the field: a session's field
// holds the hours to add, not the total, and the line reads as the title's state.
const caption = computed(() => (media.value && state.value ? statusCaption(media.value, state.value, false) : null));

const facts = computed(() => (media.value && state.value ? statusFacts(media.value, state.value, caption.value !== null) : []));

const step = computed(() =>
    media.value ? stepName(media.value, { intent: intent.value, correcting: correcting.value, cycles: cyclesOpen.value }) : null,
);

// A fully watched container has nothing left to mark (the cascade only fills
// unwatched episodes); playables can always take another play (rewatch).
const canMark = computed(() => media.value !== null && state.value !== null && (isPlayable(media.value) || !state.value.watched));

// Starting over belongs where the modal runs out of things to offer: a
// finished show has nothing left to mark. Only a show carries cycles of its
// own — a season or an episode is watched inside the run its show is in.
const canWatchAgain = computed(
    () => media.value?.type === 'show' && state.value !== null && state.value.plays > 0 && state.value.watched && intent.value === 'complete',
);

const episode = computed(() => (media.value && state.value ? episodeStep(media.value, state.value, intent.value) : null));

const releaseUnavailable = computed(() => media.value !== null && isPlayable(media.value) && !media.value.releaseDate);

const sessionMode = computed(() => intent.value === 'session');

// Completing while the newest entry is a drop: absorb its hours or start
// fresh. Position counters never sum, so the choice does not apply to them.
const overDropped = computed(() => (state.value?.latestDropped ?? false) && intent.value === 'complete');

const showEpisodes = computed(
    () => media.value !== null && state.value !== null && container.value && (state.value.watchedEpisodes ?? 0) > 0 && !isStep.value,
);

/** The one action of the view: what it says and does, its colour, and whether it takes a date. */
const primary = computed<{ label: string; icon: LucideIcon; tone: ActionTone; dated: boolean; act: () => void } | null>(() => {
    const current = media.value;
    const shown = state.value;

    if (!current || !shown || cyclesOpen.value) {
        return null;
    }

    const tone = actionTone(intent.value, shown);

    if (intent.value === 'status') {
        return {
            label: shown.status === 'on_hold' ? trans('media.resume') : trans('media.remove_status'),
            icon: shown.status === 'on_hold' ? Play : RotateCcw,
            tone,
            dated: false,
            act,
        };
    }

    if (intent.value === 'drop') {
        return { label: trans('media.drop_this_title'), icon: Ban, tone, dated: true, act };
    }

    if (sessionMode.value) {
        return {
            label: actionLabel(current, shown, { intent: 'session', correcting: correcting.value, step: null }),
            icon: correcting.value || position.value ? Check : Plus,
            tone,
            dated: false,
            act,
        };
    }

    if (episode.value) {
        return {
            label: episode.value === 'first' && shown.nextEpisode === null ? trans('media.watch_the_first_episode') : nextEpisodeLabel(shown),
            icon: Check,
            tone,
            dated: true,
            act: startNextEpisode,
        };
    }

    if (canWatchAgain.value) {
        return { label: trans('media.watch_again'), icon: RotateCcw, tone, dated: false, act: watchAgain };
    }

    if (!canMark.value) {
        return null;
    }

    return {
        label:
            isStep.value && withProgress.value
                ? playActionLabels(current).mark
                : actionLabel(current, shown, { intent: 'complete', correcting: false, step: null }),
        icon: Check,
        tone,
        dated: true,
        act,
    };
});

// The rows of the view the modal opened on; a step has its one action alone.
const rows = computed<StatusRow[]>(() =>
    media.value && state.value && !isStep.value
        ? statusRows(media.value, state.value, mainIntent.value, { history: plays.value.length > 0, cycles: cycles.value.length > 1 })
        : [],
);

const historySummary = computed(() => ({
    count: plays.value.length,
    last: plays.value.reduce<string | null>((latest, play) => (latest === null || play.watchedAt > latest ? play.watchedAt : latest), null),
}));

function pressRow(row: StatusRow): void {
    switch (row) {
        case 'wholeRun':
            act();
            break;
        case 'nextEpisode':
            startNextEpisode();
            break;
        case 'complete':
            if (withProgress.value) {
                intent.value = 'complete';
            } else {
                mode.value = 'now';
                act('complete');
            }
            break;
        case 'addSession':
            intent.value = 'session';
            break;
        case 'correctTotal':
            intent.value = 'session';
            correcting.value = true;
            break;
        case 'hold':
            hold();
            break;
        case 'drop':
            intent.value = 'drop';
            break;
        case 'unmark':
            void unmark();
            break;
        case 'removeProgress':
            void resetProgress();
            break;
        case 'cycles':
            cyclesOpen.value = true;
            break;
        case 'history':
            historyOpen.value = !historyOpen.value;
            break;
    }
}

function back(): void {
    intent.value = mainIntent.value;
    correcting.value = false;
    cyclesOpen.value = false;
}

// ---- Writes ----

function parseAmount(value: string | number): number | null {
    return media.value ? progressFromInput(media.value, value) : null;
}

/** The counter as typed, where zero is a real answer: back to the start. */
function typedCounter(): number | null {
    return parseDecimal(amount.value) === 0 ? 0 : parseAmount(amount.value);
}

/** The counter correction alone: overwrite the total and close, like every
 *  other write in this modal — one action, done. */
function saveCounter(): void {
    const current = media.value;

    if (!current) {
        return;
    }

    const stored = typedCounter();

    if (stored !== null && stored !== (state.value?.progress ?? 0)) {
        logProgress(current, { set: stored });
    }

    amountDirty.value = false;
    close();
}

/** The view's one action; `as` writes another view's (a row completing from the status view). */
function act(as: WatchedIntent = intent.value): void {
    const current = media.value;

    if (!current) {
        return;
    }

    if (as === 'status') {
        setStatus(current, null);
        close();

        return;
    }

    const when = watchedAtChoice(mode.value, customDate.value);

    if (when === null) {
        return;
    }

    if (as === 'drop') {
        setStatus(current, 'dropped', withProgress.value ? parseAmount(amount.value) : null, when);
        close();

        return;
    }

    if (as === 'session') {
        if (correcting.value) {
            saveCounter();

            return;
        }

        // Zero is a real position on the slider ("back to the start"); a game session, which ADDS hours, still has nothing to
        // write there.
        const logged = position.value ? typedCounter() : parseAmount(amount.value);

        if (logged === null) {
            return;
        }

        // Position counters overwrite ("I'm on page / chapter / issue X");
        // games add the session's hours on top.
        logProgress(current, position.value ? { set: logged } : { add: logged });
        close();

        return;
    }

    // A latest dropped attempt is ALWAYS replaced (no separate
    // records); games only choose what happens to its hours.
    // Position counters never sum (the counter already carries the furthest
    // position).
    markWatched(current, when, {
        amount: withProgress.value ? parseAmount(amount.value) : null,
        mergeHours: position.value ? false : mergeDropped.value,
    });
    close();
}

/** The first episode alone — the rest of the run stays untouched. */
function startNextEpisode(): void {
    const when = watchedAtChoice(mode.value, customDate.value);

    if (!media.value || when === null) {
        return;
    }

    markNextEpisodeWatched(media.value, when);
    close();
}

function hold(): void {
    if (media.value) {
        setStatus(media.value, 'on_hold');
        close();
    }
}

/**
 * Undo an open attempt: the logged sessions and the "Playing" / "Reading"
 * status go away together, because they are one row server-side. Without
 * this the first session a title ever gets can never be taken back — only
 * completed or dropped. Taking a HOLD off is the other thing entirely and
 * keeps them ({@see hold}).
 */
async function resetProgress(): Promise<void> {
    const current = media.value;

    if (!current) {
        return;
    }

    const confirmed = await confirm({ message: trans('media.remove_progress_confirm'), confirmLabel: trans('media.remove_progress') });

    if (confirmed) {
        discardProgress(current);
        close();
    }
}

/** Deletes the plays of every watched episode, so it asks first, naming how many. */
async function unmark(): Promise<void> {
    const current = media.value;
    const shown = state.value;

    if (!current || !shown) {
        return;
    }

    const count = unmarkCount(shown);
    const confirmed = await confirm({
        message: transChoice(shown.cycle > 1 ? 'media.unmark_cycle_confirm' : 'media.unmark_confirm', count, { count: String(count) }),
        confirmLabel: trans('media.mark_as_unwatched'),
    });

    if (confirmed) {
        unmarkWatched(current);
        close();
    }
}

/** Start the show over: nothing is deleted, the counters begin at zero. */
function watchAgain(): void {
    if (media.value) {
        startRewatch(media.value);
        close();
    }
}
</script>

<template>
    <AppModal :open="media !== null" :title="media?.title ?? ''" @update:open="(open: boolean) => !open && close()">
        <template v-if="media && state && isStep" #leading>
            <button
                type="button"
                :aria-label="trans('common.back')"
                class="bg-surface-raised text-ink-muted hover:text-ink mt-0.5 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg transition-colors duration-150"
                @click="back"
            >
                <ArrowLeft class="size-4.5" />
            </button>
        </template>

        <template v-if="media && state" #meta>
            <span v-if="isStep && step" class="font-medium" :style="{ color: step.color }">{{ step.text }}</span>
            <template v-else>
                <MetaLine v-if="caption" :parts="caption.parts" class="min-w-0 font-medium" :style="{ color: caption.color }" />
                <Hairline v-if="caption && facts.length" class="mx-0" />
                <MetaLine v-if="facts.length" :parts="facts" class="text-ink-muted min-w-0" />
            </template>
        </template>

        <!-- Keyed by the title, so the parts holding a view of their own (the
             percent lens, an open history) start fresh with each one. -->
        <div v-if="media && state" :key="stateKey(media)" class="flex flex-col">
            <WatchedCycles v-if="cyclesOpen" :media="media" :cycles="cycles" @undone="close" />

            <div v-else class="flex flex-col gap-3 px-5 pb-4">
                <!-- What a status or a drop does to this title, said before the button. -->
                <p
                    v-for="statement in intent === 'status' ? statusDescription(media, state) : []"
                    :key="statement"
                    class="text-ink-muted m-0 text-sm"
                >
                    {{ statement }}
                </p>
                <p v-if="intent === 'drop'" class="text-ink-muted m-0 text-sm">{{ dropDescription(media) }}</p>

                <EpisodeProgress v-if="showEpisodes" :watched="state.watchedEpisodes ?? 0" :aired="state.airedEpisodes ?? 0" />

                <!-- The counter of a progress type, wherever a write carries it. -->
                <template v-if="withProgress && intent !== 'status'">
                    <WatchedCounter v-model="amount" :media="media" :adding="sessionMode && !correcting && !position" @edited="amountDirty = true" />
                    <p v-if="sessionMode && !correcting && state.watched && !state.progress" class="text-ink-muted m-0 text-sm">
                        {{ position?.anotherRun ?? trans('media.this_starts_another_playthrough_hours_of') }}
                    </p>
                </template>

                <!-- Absorbing the hours frozen at the last drop: one line,
                     checked by default, hidden where it cannot apply. -->
                <button
                    v-if="overDropped && withProgress && !position"
                    type="button"
                    :aria-pressed="mergeDropped"
                    class="text-ink-secondary hover:text-ink flex items-center gap-2.5 text-left text-sm transition-colors duration-150"
                    @click="mergeDropped = !mergeDropped"
                >
                    <span
                        class="flex size-4.5 shrink-0 items-center justify-center rounded-md border transition-colors duration-150"
                        :class="mergeDropped ? 'bg-action-watched border-transparent' : 'border-line-strong'"
                    >
                        <Check v-if="mergeDropped" class="text-on-bright size-3" :stroke-width="3" />
                    </span>
                    {{ trans('media.include_the_hours_from_the_dropped') }}
                </button>

                <StatusAction
                    v-if="primary"
                    v-model:mode="mode"
                    v-model:date="customDate"
                    :label="primary.label"
                    :icon="primary.icon"
                    :tone="primary.tone"
                    :dated="primary.dated"
                    :release-date="media.releaseDate"
                    :release-precision="media.extra.releasePrecision"
                    :release-unavailable="releaseUnavailable"
                    @act="primary.act()"
                />
                <p v-if="canWatchAgain" class="text-ink-muted m-0 text-sm">{{ trans('media.your_history_and_statistics_stay_the') }}</p>
            </div>

            <template v-if="rows.length">
                <div class="border-line border-t" />
                <StatusRows
                    :rows="rows"
                    :media="media"
                    :state="state"
                    :history="historySummary"
                    :history-open="historyOpen"
                    :with-progress="withProgress"
                    @press="pressRow"
                >
                    <template #history>
                        <div class="px-5 pb-2">
                            <WatchedHistory :media="media" :plays="plays" :with-progress="withProgress" @changed="loadPlays" @emptied="close" />
                        </div>
                    </template>
                </StatusRows>
            </template>
        </div>
    </AppModal>
</template>
