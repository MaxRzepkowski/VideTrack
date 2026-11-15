<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import WatchedCounter from '@/components/media/watched/WatchedCounter.vue';
import WatchedCycles from '@/components/media/watched/WatchedCycles.vue';
import WatchedHistory from '@/components/media/watched/WatchedHistory.vue';
import WatchedWhen from '@/components/media/watched/WatchedWhen.vue';
import MetaLine from '@/components/MetaLine.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { confirm } from '@/composables/useConfirm';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { usePlayHistory } from '@/composables/usePlayHistory';
import { leafCode } from '@/lib/badges';
import { localDateTimeInput } from '@/lib/format';
import { hasUserStatus } from '@/lib/mediaTypes';
import { formatProgress, hasProgress, isPositionCounter, parseDecimal, positionText, progressFromInput, progressToInput } from '@/lib/progress';
import {
    actionLabel,
    completeLabel,
    dropDescription,
    episodeStep,
    openingIntent,
    statusCaption,
    statusDescription,
    watchedAtChoice,
    type WatchedIntent,
    type WhenMode,
} from '@/lib/watchedModal';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Check, ChevronDown, CircleCheck, CircleOff, Pause, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// THE status modal (attempt model): one surface for everything that writes
// the user's relationship with a title. It is built around the ONE action
// almost every visit wants — completing the title, now. That action is the
// only button with weight; the date sits under it as a line you can click,
// and the rarer writes (hold, drop, history) are quiet footer links. There
// are no status tabs: a tab strip charges every visit for a choice that is
// already made by the time the modal opens.
//
// The counter of a progress type (game hours, book pages) is editable right
// here, so a title in progress needs no second door.
const { modals, close } = useMediaModals();
const { isPlayable, markWatched, markNextEpisodeWatched, unmarkWatched, startRewatch, logProgress, setStatus, discardProgress } = useMediaActions();

const media = computed(() => modals.watched);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));

const intent = ref<WatchedIntent>('complete');
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

const { plays, cycles, loadPlays, loadCycles, clear: clearHistory } = usePlayHistory(() => media.value);

const withProgress = computed(() => media.value !== null && hasProgress(media.value));
const withStatus = computed(() => media.value !== null && hasUserStatus(media.value));
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

watch(media, (opened) => {
    if (!opened || !state.value) {
        return;
    }

    intent.value = openingIntent(opened, state.value, modals.watchedTab);
    mode.value = 'now';
    customDate.value = localDateTimeInput();
    correcting.value = false;
    mergeDropped.value = true;
    historyOpen.value = false;
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
});

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

// Whether the counter field is on screen carrying the live progress — the
// caption above then names the status alone.
const counterShown = computed(() => withProgress.value && intent.value !== 'status');

const caption = computed(() => (media.value && state.value ? statusCaption(media.value, state.value, counterShown.value) : null));

const chip = computed(() => (media.value ? leafCode(media.value) : null));

// A fully watched container has nothing left to mark (the cascade only fills
// unwatched episodes); playables can always take another play (rewatch).
const canMark = computed(() => media.value !== null && state.value !== null && (isPlayable(media.value) || !state.value.watched));

// Starting over belongs exactly where the modal runs out of things to offer:
// a finished show has nothing left to mark, a dropped one only offers taking
// the status off. Both are the moment "from the top" is the real question.
// Only a show carries cycles of its own — a season or an episode is watched
// inside the run its show is in.
const canWatchAgain = computed(
    () =>
        media.value?.type === 'show' &&
        state.value !== null &&
        state.value.plays > 0 &&
        ((intent.value === 'complete' && state.value.watched) || (intent.value === 'status' && state.value.status === 'dropped')),
);

const step = computed(() => (media.value && state.value ? episodeStep(media.value, state.value, intent.value) : null));

const releaseUnavailable = computed(() => media.value !== null && isPlayable(media.value) && !media.value.releaseDate);

const sessionMode = computed(() => intent.value === 'session');

// A session on a game ADDS hours, so the running total belongs on screen as
// context with a way to correct it. A position counter has one number and
// the field already is it.
const sessionTotal = computed(() =>
    media.value && sessionMode.value && !correcting.value && !position.value && (state.value?.progress ?? 0) > 0
        ? formatProgress(media.value, state.value?.progress ?? null)
        : null,
);

// Completing while the newest entry is a drop: absorb its hours or start
// fresh. Position counters never sum, so the choice does not apply to them.
const overDropped = computed(() => (state.value?.latestDropped ?? false) && intent.value === 'complete');

// A completed title has no open attempt to drop or pause. A game with an
// open state (playing / sessions) is a fresh attempt and stays droppable.
const completedLock = computed(
    () => media.value !== null && state.value !== null && isPlayable(media.value) && state.value.watched && state.value.status === null,
);

const accent = computed(() => {
    if (intent.value === 'drop') {
        return 'var(--color-status-dropped)';
    }

    if (intent.value === 'status') {
        return state.value?.status === 'dropped' ? 'var(--color-status-dropped)' : 'var(--color-status-onhold)';
    }

    return 'var(--color-action-watched)';
});

const label = computed(() =>
    media.value && state.value ? actionLabel(media.value, state.value, { intent: intent.value, correcting: correcting.value, step: step.value }) : '',
);

const actionIcon = computed(() => {
    if (intent.value === 'status') {
        return RotateCcw;
    }

    if (intent.value === 'drop') {
        return CircleOff;
    }

    return sessionMode.value && !correcting.value ? Plus : Check;
});

// Correcting the counter from the completion view is its own write, so it
// gets its own full-width button — the same weight as the main action,
// below the field rather than inside its row. Growing downwards leaves the
// input and the spinner arrows exactly where the cursor left them. (In the
// session view the main button already IS the save.)
const canSaveCounter = computed(() => intent.value === 'complete' && withProgress.value);

const episodePercent = computed(() => {
    const total = state.value?.airedEpisodes ?? 0;

    return total > 0 ? Math.round(((state.value?.watchedEpisodes ?? 0) / total) * 100) : 0;
});

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

function act(): void {
    const current = media.value;

    if (!current) {
        return;
    }

    if (intent.value === 'status') {
        setStatus(current, null);
        close();

        return;
    }

    const when = watchedAtChoice(mode.value, customDate.value);

    if (when === null) {
        return;
    }

    if (intent.value === 'drop') {
        setStatus(current, 'dropped', withProgress.value ? parseAmount(amount.value) : null, when);
        close();

        return;
    }

    if (sessionMode.value) {
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

    const count = shown.watchedEpisodes ?? shown.plays;
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
    <Dialog :open="media !== null" @update:open="(open: boolean) => !open && close()">
        <!-- The accent is bound per element, not as a `--accent` custom
             property on the dialog: DialogContent's root is a fragment
             (portal + overlay + content), so a fallthrough `style` never
             reaches the DOM and every rule reading the variable dies. -->
        <DialogContent v-if="media && state" class="border-line-subtle bg-surface max-w-md rounded-2xl p-5 sm:p-6">
            <!-- Keyed by the title, so the parts holding a view of their own
                 (the open date picker, the percent lens) start fresh with
                 each one. -->
            <div :key="stateKey(media)" class="flex flex-col">
                <!-- The title leads; where it stands today is the caption
                     under it, carried by the colour of the words alone. No
                     status dot, no badge slab — the line is short enough to
                     read as one phrase. -->
                <DialogTitle class="mr-8 min-w-0 text-xl leading-snug font-semibold tracking-tight" :class="caption || chip ? '' : 'mb-5'">
                    {{ media.title }}
                </DialogTitle>

                <p v-if="caption || chip" class="m-0 mt-1.5 mb-5 flex min-w-0 items-baseline gap-1.5 text-sm font-semibold">
                    <span v-if="chip" class="text-ink-faint shrink-0 tabular-nums">{{ chip }}</span>
                    <Hairline v-if="chip && caption" class="mx-0" />
                    <MetaLine v-if="caption" :parts="caption.parts" class="min-w-0" :style="{ color: caption.color }" />
                </p>

                <!-- ============ Already on hold / dropped ============ -->
                <template v-if="intent === 'status'">
                    <div class="mb-4 flex flex-col gap-2">
                        <p v-for="statement in statusDescription(media, state)" :key="statement" class="text-ink-muted m-0 text-sm leading-relaxed">
                            {{ statement }}
                        </p>
                    </div>
                </template>

                <!-- ============ Completing / dropping / logging ============ -->
                <template v-else>
                    <!-- Containers: how far the episodes got (read-only —
                         episodes are marked on their own rows). A counter
                         over a bar needs no word naming it. -->
                    <div v-if="container && (state.watchedEpisodes ?? 0) > 0 && intent === 'complete'" class="mb-4 flex flex-col gap-2">
                        <span class="text-ink-secondary text-sm font-semibold tabular-nums"
                            >{{ state.watchedEpisodes }} / {{ state.airedEpisodes }}</span
                        >
                        <div class="bg-surface-overlay h-1 overflow-hidden rounded-full">
                            <div
                                class="bg-status-progress h-full rounded-full transition-[width] duration-300"
                                :style="{ width: `${episodePercent}%` }"
                            ></div>
                        </div>
                    </div>

                    <!-- The counter, editable from the moment the modal opens.
                         The field keeps a stable width and the save button
                         sits in its own reserved row underneath, so clicking
                         the spinner arrows never moves anything. -->
                    <div v-if="withProgress" class="mb-4 flex flex-col gap-2">
                        <WatchedCounter
                            v-model="amount"
                            :media="media"
                            :adding="sessionMode && !correcting && !position"
                            @edited="amountDirty = true"
                        />

                        <!-- A game session ADDS to a running total, so the
                             total belongs on screen — with the way to correct
                             it instead of adding to it. -->
                        <p v-if="sessionTotal" class="text-ink-muted m-0 flex flex-wrap items-baseline gap-x-1.5 text-sm">
                            <span>{{ trans('media.logged_so_far_time', { time: sessionTotal }) }}</span>
                            <Hairline class="mx-0" />
                            <button
                                v-tooltip="position?.correctCounter ?? trans('media.correct_the_hour_counter')"
                                type="button"
                                class="text-ink-secondary hover:text-ink font-semibold underline decoration-dotted underline-offset-4 transition-colors duration-150"
                                @click="correcting = true"
                            >
                                {{ trans('media.correct') }}
                            </button>
                        </p>

                        <button
                            v-if="sessionMode && correcting"
                            type="button"
                            class="text-ink-muted hover:text-ink m-0 self-start text-sm font-semibold underline decoration-dotted underline-offset-4 transition-colors duration-150"
                            @click="correcting = false"
                        >
                            {{ trans('media.add_session') }}
                        </button>

                        <button
                            v-if="canSaveCounter && amountDirty"
                            v-tooltip="position?.correctCounter ?? trans('media.correct_the_hour_counter')"
                            type="button"
                            class="bg-brand text-on-accent mt-1 w-full rounded-xl py-3.5 text-base font-bold transition-opacity duration-150 hover:opacity-90"
                            @click="saveCounter"
                        >
                            {{ trans('common.save') }}
                        </button>

                        <p v-if="sessionMode && state.watched && !state.progress" class="text-ink-muted m-0 text-sm">
                            {{ position?.anotherRun ?? trans('media.this_starts_another_playthrough_hours_of') }}
                        </p>
                    </div>

                    <!-- Absorbing the hours frozen at the last drop: one line,
                         checked by default, hidden where it cannot apply. -->
                    <button
                        v-if="overDropped && withProgress && !position && !sessionMode"
                        type="button"
                        :aria-pressed="mergeDropped"
                        class="text-ink-secondary hover:text-ink mb-4 flex items-center gap-2.5 text-left text-sm transition-colors duration-150"
                        @click="mergeDropped = !mergeDropped"
                    >
                        <span
                            class="flex size-4.5 shrink-0 items-center justify-center rounded-md border transition-colors duration-150"
                            :class="mergeDropped ? 'border-transparent' : 'border-line-strong'"
                            :style="mergeDropped ? { background: accent } : undefined"
                        >
                            <Check v-if="mergeDropped" class="text-surface-base size-3" :stroke-width="3" />
                        </span>
                        {{ trans('media.include_the_hours_from_the_dropped') }}
                    </button>

                    <p v-if="intent === 'drop'" class="text-ink-muted m-0 mb-4 text-sm leading-relaxed">{{ dropDescription(media) }}</p>
                </template>

                <!-- ============ The one action ============ -->
                <!-- Two, on an untouched show or season: starting it leads, and
                     taking the whole run keeps its place below in outline. -->
                <button
                    v-if="step"
                    type="button"
                    class="text-surface-base mb-2 flex w-full items-center justify-center gap-2.5 rounded-xl py-3.5 text-base font-bold transition-opacity duration-150 hover:opacity-90"
                    :style="{ background: accent }"
                    @click="startNextEpisode"
                >
                    <Check class="size-5 shrink-0" :stroke-width="2.4" />
                    {{ step === 'first' ? trans('media.watch_the_first_episode') : trans('media.watch_the_next_episode') }}
                </button>

                <button
                    v-if="canMark || intent !== 'complete'"
                    type="button"
                    class="flex w-full items-center justify-center gap-2.5 rounded-xl py-3.5 text-base font-bold transition-opacity duration-150 hover:opacity-90"
                    :class="sessionMode ? 'bg-brand text-on-accent' : step ? 'border' : 'text-surface-base'"
                    :style="sessionMode ? undefined : step ? { borderColor: accent, color: accent } : { background: accent }"
                    @click="act"
                >
                    <component :is="actionIcon" class="size-5 shrink-0" :stroke-width="2.4" />
                    {{ label }}
                </button>

                <!-- Hidden rather than taken down, so a picker left open stays
                     open across a look at another view. -->
                <WatchedWhen
                    v-show="!sessionMode && intent !== 'status' && (canMark || intent === 'drop')"
                    v-model:mode="mode"
                    v-model:date="customDate"
                    :release-date="media.releaseDate"
                    :release-unavailable="releaseUnavailable"
                    :accent="accent"
                />

                <!-- Starting over. Outlined, not a slab: it is the rarer of
                     the two answers a finished show has, and the line under it
                     says what it costs (nothing). -->
                <template v-if="canWatchAgain">
                    <button
                        type="button"
                        class="mt-2.5 flex w-full items-center justify-center gap-2.5 rounded-xl border py-3.5 text-base font-bold transition-opacity duration-150 hover:opacity-90"
                        :style="{ borderColor: 'var(--color-action-watched)', color: 'var(--color-action-watched)' }"
                        @click="watchAgain"
                    >
                        <RotateCcw class="size-5 shrink-0" :stroke-width="2.4" />
                        {{ trans('media.watch_again') }}
                    </button>
                    <p class="text-ink-muted m-0 mt-2 text-sm leading-relaxed">
                        {{ trans('media.your_history_and_statistics_stay_the') }}
                    </p>
                </template>

                <!-- Containers keep the bulk unmark (plays live on episodes).
                     A container with no episode counters — a specials season —
                     counts the plays themselves instead. Past the first cycle
                     the wording names the run it empties, because the earlier
                     ones are staying. -->
                <button
                    v-if="container && state.plays > 0 && intent === 'complete'"
                    type="button"
                    class="border-danger-line text-danger hover:bg-danger-surface mt-2.5 w-full rounded-xl border bg-transparent py-2.5 text-sm font-semibold transition-colors duration-150"
                    @click="unmark"
                >
                    {{
                        transChoice(
                            state.cycle > 1 ? 'media.mark_as_unwatched_removes_count_episode' : 'media.mark_as_unwatched_removes_plays_from',
                            state.watchedEpisodes ?? state.plays,
                            {
                                count: String(state.watchedEpisodes ?? state.plays),
                            },
                        )
                    }}
                </button>

                <!-- The runs behind. A single one says nothing the status line
                     does not already say, so the list appears once a second
                     cycle exists. -->
                <WatchedCycles v-if="cycles.length > 1" :media="media" :cycles="cycles" @undone="close" />

                <!-- ============ The quiet writes ============ -->
                <div class="border-line-subtle text-ink-muted mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-3.5 text-sm font-semibold">
                    <!-- Logging another session on a title in progress: the
                         way back from the completion view. -->
                    <button
                        v-if="withProgress && intent === 'complete'"
                        type="button"
                        class="hover:text-status-progress flex items-center gap-1.5 transition-colors duration-150"
                        @click="intent = 'session'"
                    >
                        <Plus class="size-4 shrink-0" />
                        {{ position ? trans('media.update_progress') : trans('media.add_session') }}
                    </button>

                    <button
                        v-if="withStatus && intent !== 'status' && state.status !== 'on_hold'"
                        type="button"
                        class="hover:text-status-onhold flex items-center gap-1.5 transition-colors duration-150"
                        @click="hold"
                    >
                        <Pause class="size-4 shrink-0" />
                        {{ trans('media.put_on_hold') }}
                    </button>

                    <button
                        v-if="intent !== 'drop' && intent !== 'status' && !completedLock"
                        type="button"
                        class="hover:text-danger flex items-center gap-1.5 transition-colors duration-150"
                        @click="intent = 'drop'"
                    >
                        <CircleOff class="size-4 shrink-0" />
                        {{ trans('media.drop') }}
                    </button>

                    <!-- The open attempt is one row server-side: throwing it
                         away takes the counter and the status with it. -->
                    <button
                        v-if="state.status === 'in_progress' && intent !== 'drop'"
                        type="button"
                        class="hover:text-danger flex items-center gap-1.5 transition-colors duration-150"
                        @click="resetProgress"
                    >
                        <Trash2 class="size-4 shrink-0" />
                        {{ trans('media.remove_progress') }}
                    </button>

                    <!-- The way back to completing: from the session view it
                         is one of four links, so it stays short there. -->
                    <button
                        v-if="intent !== 'complete'"
                        type="button"
                        class="hover:text-action-watched flex items-center gap-1.5 transition-colors duration-150"
                        @click="intent = 'complete'"
                    >
                        <CircleCheck class="size-4 shrink-0" />
                        {{ sessionMode ? trans('media.complete') : completeLabel(media, state) }}
                    </button>

                    <button
                        v-if="plays.length > 0"
                        type="button"
                        class="hover:text-ink ml-auto flex items-center gap-1 transition-colors duration-150"
                        :aria-expanded="historyOpen"
                        @click="historyOpen = !historyOpen"
                    >
                        {{ trans('media.history_count', { count: String(plays.length) }) }}
                        <ChevronDown class="size-4 shrink-0 transition-transform duration-200" :class="historyOpen ? 'rotate-180' : ''" />
                    </button>
                </div>

                <WatchedHistory
                    v-if="historyOpen && plays.length > 0"
                    :media="media"
                    :plays="plays"
                    :with-progress="withProgress"
                    @changed="loadPlays"
                    @emptied="close"
                />

                <!-- Completed playable: the drop link is gone, say why. -->
                <p v-if="completedLock && intent === 'complete'" class="text-ink-faint m-0 mt-3 text-sm">
                    {{ trans('media.you_have_completed_this_title_only') }}
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
