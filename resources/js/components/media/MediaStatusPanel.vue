<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import ProgressHint from '@/components/media/ProgressHint.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { formatDateTime } from '@/lib/format';
import { backlogOf } from '@/lib/lists';
import { episodeShare, formatProgress, hasProgress, isPositionCounter, playActionLabels, timesFinished } from '@/lib/progress';
import { shownStatus, shownStatusVisual, STATUS_VISUALS } from '@/lib/status';
import type { SharedData } from '@/types';
import type { MediaSummary } from '@/types/media';
import { usePage } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

// THE status card — the single "watched / status" surface of a media page
// (the sticky actions panel below keeps rating / lists / favorites only).
// The first row reads like an action row: status label left, watched date or
// play count right, and a click opens the status modal on the matching tab.
// What differs per type is the middle: containers (shows, seasons) carry the
// episode progress bar and the "mark next" shortcut, progress types (games,
// books) the hour / position counter baked into the status line. Logging a
// session has no button of its own — the modal a title in progress opens
// already leads with it.
const props = defineProps<{
    media: MediaSummary;
}>();

const { stateOf, isPlayable, markWatchedIfUntouched } = useMediaActions();
const { openWatched } = useMediaModals();

const page = usePage<SharedData>();

const state = stateOf(() => props.media);

// "Backlog" means the system watchlist specifically — membership in a custom
// list is a lists concern (the lists surface below), not a viewing status.
const onWatchlist = computed(() => {
    const watchlist = backlogOf(page.props.userLists);

    return watchlist !== undefined && state.value.listIds.includes(watchlist.id);
});

const container = computed(() => !isPlayable(props.media));
const withSessions = computed(() => hasProgress(props.media));

const sessionHours = computed(() => formatProgress(props.media, state.value.progress));
const playedHours = computed(() => formatProgress(props.media, state.value.playedAmount));
const droppedHours = computed(() => formatProgress(props.media, state.value.droppedAmount));

// Paused and dropped attempts keep their counter beside the status label;
// live progress uses the row's right-hand value instead.
const withHours = (label: string) => [label, sessionHours.value];

// Past the first cycle the status names the one on screen: the bar and the
// counter under it report THAT cycle, not the whole history.
const withCycle = (label: string) =>
    container.value && state.value.cycle > 1 ? [label, trans('media.cycle_number', { number: String(state.value.cycle) })] : [label];

const status = computed(() => {
    // Which state shows is the one rule every surface reads (`shownStatus`);
    // the words are this card's own.
    const shown = shownStatus(state.value, container.value);
    const look = shown === null ? null : { ...shownStatusVisual(shown), filled: true, plain: false };

    if (shown === 'dropped' && look) {
        // A season is normally dropped because its show is — the label says so
        // rather than letting the user wonder when they gave up on this one
        // season. Only when the status really came from the show: one the user
        // dropped himself reads plainly. The dropped attempt keeps showing the
        // hours sunk into it, held in `droppedAmount` (not the live
        // `progress` the others use).
        const label = state.value.statusInherited ? trans('media.dropped_with_the_show') : trans('common.dropped');

        return { label: [label, droppedHours.value], ...look };
    }

    if (shown === 'onHold' && look) {
        const label = state.value.statusInherited ? trans('media.on_hold_with_the_show') : trans('common.on_hold');

        return { label: withHours(label), ...look };
    }

    if (shown === 'inProgress' && look) {
        return { label: [isPositionCounter(props.media) ? trans('media.reading') : trans('media.playing')], ...look };
    }

    if (shown === 'watched' && look) {
        if (withSessions.value) {
            return { label: [trans('common.completed'), playedHours.value], ...look };
        }

        return { label: [container.value ? trans('common.completed') : trans('media.watched')], ...look };
    }

    if (shown === 'underWay' && look) {
        return { label: withCycle(trans('common.in_progress')), ...look };
    }

    if (onWatchlist.value) {
        return { label: [trans('common.backlog')], ...STATUS_VISUALS.backlog, filled: false, plain: false };
    }

    // Nothing yet: the row reads as the action it performs — green icon,
    // plain label like the inactive action rows around it. Only the wording
    // differs per type: a title with a counter leads with starting it, not
    // with declaring it finished.
    const labels = playActionLabels(props.media);

    return { label: [labels.start ?? labels.mark], ...STATUS_VISUALS.watched, filled: false, plain: true };
});

// A row describing an existing state invites a different action than it
// shows — hovering reveals it (empty rows already read as their action).
const hoverLabel = computed(() => (status.value.plain ? null : trans('media.change_status')));

// The play count rides along with the status: a game completed once and now
// dropped (or on hold) still shows "1 play". A single completion with no
// status shows its date instead — a count next to a date would read as
// "twice on that day". Dropped is the exception: it surfaces WHEN it was
// dropped, and pairs the count with that date when completions also exist.
const statusValue = computed<(string | null)[]>(() => {
    const playable = isPlayable(props.media);
    const times = timesFinished(props.media, state.value);

    // A container counts finished runs: "2×" is the fact a show watched twice
    // carries, and the dates of each run live in the modal.
    const count = playable
        ? times > 0 && (times > 1 || state.value.status !== null)
            ? transChoice('media.count_play', times, { count: String(times) })
            : null
        : times > 1
          ? trans('media.count', { count: String(times) })
          : null;

    // A live game or reading counter is the right-hand value of the status
    // row, matching the watched date layout instead of joining the label with
    // a hairline on the left.
    if (state.value.status === 'in_progress') {
        return sessionHours.value ? [sessionHours.value] : [];
    }

    if (state.value.status === 'dropped' && state.value.droppedAt) {
        return [count, formatDateTime(state.value.droppedAt)];
    }

    if (count) {
        return [count];
    }

    if (state.value.status !== null || !state.value.watched) {
        return [];
    }

    return [formatDateTime(state.value.watchedAt)];
});

// ---- Containers: the episode progress bar (the "watch next" shortcut is
// its own block below — WatchNextPanel) ----

const progress = computed(() => episodeShare(state.value.watchedEpisodes ?? 0, state.value.airedEpisodes ?? 0));

// The status modal opens on the view matching the current status — a
// dropped title lands straight on its "remove status" view, a game being
// played on logging another session.
function openStatusModal(): void {
    const current = state.value.status;

    openWatched(props.media, current === 'on_hold' || current === 'dropped' ? current : 'watched');
}

// Right-click is the card's shortcut too: a title with no status yet is
// marked watched now, skipping the modal. Anything already set keeps the
// click-to-open path only — judged by the server's state when the request
// would go out, not by the picture held under the loader.
function markWatchedNow(): void {
    markWatchedIfUntouched(props.media);
}
</script>

<template>
    <div class="border-line-subtle bg-surface flex flex-col gap-2 rounded-2xl border p-2">
        <div class="flex items-stretch gap-2">
            <!-- No tooltip: the row spells its own label out, in full. The
                 counter has no pencil beside it — the status modal this row
                 opens edits it in place, so one door leads to one write. -->
            <button
                type="button"
                class="group flex min-w-0 flex-1 items-center gap-3 rounded-xl px-4 py-3.5 transition-colors duration-150"
                :class="status.filled ? '' : 'hover:bg-highlight/5'"
                :style="status.filled ? { backgroundColor: status.color } : undefined"
                @click="openStatusModal"
                @contextmenu.prevent="markWatchedNow"
            >
                <component
                    :is="status.icon"
                    class="size-6 shrink-0 transition-colors duration-150"
                    :style="status.filled ? undefined : { color: status.color }"
                    :class="status.filled ? 'text-surface-base' : ''"
                    :stroke-width="2.2"
                />
                <!-- The hover swap toggles `display` on these two, so the
                     hairline line stays INSIDE a plain span: MetaLine's root
                     carries `flex`, which fights `group-hover:hidden` for the
                     same property and would leave "Change status" unreachable. -->
                <span
                    class="min-w-0 flex-1 text-left text-base font-semibold transition-colors duration-150"
                    :class="[status.filled ? 'text-surface-base' : status.plain ? 'text-ink-muted' : '', hoverLabel ? 'group-hover:hidden' : '']"
                    :style="status.filled || status.plain ? undefined : { color: status.color }"
                >
                    <!-- A filled status turns the button into a bright slab,
                         so the rule has to darken instead of lighten. -->
                    <MetaLine :parts="status.label" :tone="status.filled ? 'accent' : 'surface'" />
                </span>
                <span
                    v-if="hoverLabel"
                    class="hidden min-w-0 flex-1 truncate text-left text-base font-semibold group-hover:block"
                    :class="status.filled ? 'text-surface-base' : 'text-ink-muted'"
                >
                    {{ hoverLabel }}
                </span>
                <span
                    v-if="statusValue.length"
                    class="shrink-0 text-sm font-bold whitespace-nowrap transition-colors duration-150"
                    :class="status.filled ? 'text-surface-base' : 'text-on-photo'"
                >
                    <MetaLine :parts="statusValue" :tone="status.filled ? 'accent' : 'surface'" />
                </span>
            </button>
        </div>

        <!-- Containers: the episode progress bar. On a season page the counters
             are the season's own, so the hint counts what this page is about
             and nothing else. -->
        <div v-if="container && (state.watchedEpisodes ?? 0) > 0" class="pt-1 pb-1.5">
            <ProgressHint :progress="state" class="flex flex-col gap-2">
                <div class="bg-line h-1.5 overflow-hidden rounded-full">
                    <div class="bg-brand h-full rounded-full transition-[width] duration-300" :style="{ width: `${progress}%` }"></div>
                </div>
                <div class="text-ink-muted text-sm font-semibold">
                    {{
                        trans('common.current_of_total_episodes_watched', {
                            current: String(state.watchedEpisodes ?? 0),
                            total: String(state.airedEpisodes ?? 0),
                        })
                    }}
                </div>
            </ProgressHint>
        </div>
    </div>
</template>
