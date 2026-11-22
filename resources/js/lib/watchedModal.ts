import { leafCode, showTitleOf } from '@/lib/badges';
import { dateTimeInputInstant, episodeCode, formatDateTime, formatTimestampDate } from '@/lib/format';
import { hasUserStatus, mediaTypeDefinition } from '@/lib/mediaTypes';
import { formatProgress, hasProgress, isPositionCounter, playActionLabels, positionText } from '@/lib/progress';
import { shownStatus, shownStatusVisual } from '@/lib/status';
import type { RowTint } from '@/lib/ui';
import type { UserMediaState } from '@/types/generated';
import type { IsoInstant, MediaSummary, WatchedAtChoice } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';

/*
 * The watched modal's decisions, apart from its screen: which view a title
 * opens on, what the one action does and says, and how the status is worded.
 * Pure — the modal hands in the title and the state the server last sent.
 */

/**
 * What the modal is about to write. 'status' is the view a title that already
 * carries a manual status opens on: there the one obvious action is taking
 * that status off again. 'session' is the view a game or book IN PROGRESS
 * opens on: playing another two hours is what happens there over and over,
 * finishing it happens once.
 */
export type WatchedIntent = 'complete' | 'session' | 'drop' | 'status';

/** The tab a caller opens the modal on. */
export type WatchedTab = 'watched' | 'on_hold' | 'dropped';

/** When the play happened, as the date line offers it. */
export type WhenMode = 'now' | 'custom' | 'release';

const isPlayable = (media: MediaSummary): boolean => mediaTypeDefinition(media.type).playable;

/**
 * Where the modal opens, in order of what the user is most likely here for:
 * taking off a status they already carry, the entry point they clicked,
 * logging another session on a title in progress, completing. A game or a
 * book that is not finished opens on logging progress, even before the first
 * session: starting one is what happens there, over and over. "Mark as read"
 * as the leading action on a book nobody has opened yet asks the wrong
 * question — finishing it stays one link away.
 */
export function openingIntent(media: MediaSummary, state: UserMediaState, tab: WatchedTab): WatchedIntent {
    if (state.status === 'on_hold' || state.status === 'dropped') {
        return 'status';
    }

    if (tab === 'dropped') {
        return 'drop';
    }

    return hasProgress(media) && !state.watched ? 'session' : 'complete';
}

/**
 * One episode, or the whole run: on a show or a season the single action marks
 * everything watched, which is rarely what the tap meant. So the episode the
 * container is at gets a button of its own, and that button leads — its first
 * one when nothing is watched yet, the next gap once it is under way. Dropped
 * and on hold are other questions entirely and keep one action.
 *
 * The untouched case is deliberately NOT gated on `nextEpisode`: a show the
 * user has never touched carries no hierarchy here (nothing is persisted until
 * the first write), and that is exactly the case the button exists for. Which
 * episode it lands on is the server's answer — see `ResolveNextEpisode`.
 */
export function episodeStep(media: MediaSummary, state: UserMediaState, intent: WatchedIntent): 'first' | 'next' | null {
    if (intent !== 'complete' || (media.type !== 'show' && media.type !== 'season')) {
        return null;
    }

    if (state.watched || state.status !== null) {
        return null;
    }

    if ((state.watchedEpisodes ?? 0) === 0) {
        // Nothing watched — or nothing counted at all, as on a specials season,
        // which carries no counters. Where the pointer exists it says whether
        // the episode it stands on really is the first one.
        return (state.position ?? 1) > 1 ? 'next' : 'first';
    }

    // Started, so the tree IS persisted: no gap left means every aired episode
    // is settled and there is nothing to carry on with.
    return state.nextEpisode === null ? null : 'next';
}

/** The line under the title: where it stands, in the status card's words and colour. */
export interface StatusCaption {
    parts: (string | null)[];
    color: string;
}

/**
 * Where the title stands today, in the same words and colours the status card
 * on the page uses — the status and its counter as two facts, which `MetaLine`
 * keeps apart. Null when the user has no relationship with the title yet:
 * naming the media type instead says nothing the page did not already.
 *
 * `counterShown`: the counter field is on screen carrying the live progress.
 * Repeating that number up here in a second wording ("Reading | 10 pages")
 * would state the same fact twice, so the caption then names the status alone.
 */
export function statusCaption(media: MediaSummary, state: UserMediaState, counterShown: boolean): StatusCaption | null {
    const withAmount = (label: string, amount: number | null): (string | null)[] => [label, formatProgress(media, amount)];

    const shown = shownStatus(state, !isPlayable(media));

    if (shown === null) {
        return null;
    }

    const color = shownStatusVisual(shown).color;

    switch (shown) {
        case 'dropped':
            return { parts: withAmount(trans('common.dropped'), state.droppedAmount), color };
        case 'onHold':
            return { parts: withAmount(trans('common.on_hold'), counterShown ? null : state.progress), color };
        case 'inProgress':
            return {
                parts: withAmount(isPositionCounter(media) ? trans('media.reading') : trans('media.playing'), counterShown ? null : state.progress),
                color,
            };
        case 'watched':
            return { parts: withAmount(isPlayable(media) ? playActionLabels(media).done : trans('common.completed'), state.playedAmount), color };
        case 'underWay':
            return { parts: [trans('common.in_progress')], color };
    }
}

/** Completing, worded for where the title is: a watched playable takes another play. */
export function completeLabel(media: MediaSummary, state: UserMediaState): string {
    return isPlayable(media) && state.watched ? trans('media.add_a_play') : playActionLabels(media).mark;
}

/** What the one action button says in the view the modal is in. */
export function actionLabel(
    media: MediaSummary,
    state: UserMediaState,
    view: { intent: WatchedIntent; correcting: boolean; step: 'first' | 'next' | null },
): string {
    if (view.intent === 'status') {
        return trans('media.remove_status');
    }

    if (view.intent === 'drop') {
        return trans('media.drop_this_title');
    }

    if (view.intent === 'session') {
        if (view.correcting) {
            return trans('common.save');
        }

        // Nothing logged yet: the button opens the attempt rather than adding
        // to one.
        if (!(state.progress ?? 0)) {
            return playActionLabels(media).start ?? trans('media.add_session');
        }

        return isPositionCounter(media) ? trans('media.save_progress') : trans('media.add_session');
    }

    // Beside a button that takes one episode, "Mark as watched" does not say
    // how much it takes.
    if (view.step) {
        return media.type === 'season' ? trans('media.watch_the_whole_season') : trans('media.watch_the_whole_show');
    }

    return completeLabel(media, state);
}

/** What dropping does to this title, said before it happens. */
export function dropDescription(media: MediaSummary): string {
    if (media.type === 'show') {
        return trans('media.the_show_leaves_up_next_your');
    }

    if (media.type === 'season') {
        return trans('media.up_next_moves_on_to_the_2');
    }

    if (media.type === 'episode') {
        return trans('media.up_next_moves_on_to_the');
    }

    if (hasProgress(media)) {
        return positionText(media)?.dropDescription ?? trans('media.the_dropped_attempt_lands_in_your_3');
    }

    return trans('media.the_dropped_attempt_lands_in_your');
}

/**
 * What taking the status off does, one statement per entry. A season shows
 * the status its show carries, so clearing it here reaches the show as well —
 * said out loud, because a click that quietly resumes a whole show is not
 * something to discover afterwards.
 */
export function statusDescription(media: MediaSummary, state: UserMediaState): string[] {
    const title = media.title;
    const dropped = state.status === 'dropped';

    // The season carries the show's status: say where it came from, because
    // "dropped" on a season the user never gave up on reads like an error.
    if (state.statusInherited) {
        return [dropped ? trans('media.title_is_dropped_along_with_the', { title }) : trans('media.title_is_on_hold_along_with', { title })];
    }

    const own = dropped ? trans('media.title_is_dropped_watching_it_again', { title }) : trans('media.title_is_on_hold_it_stays', { title });

    if (state.rootStatus === null) {
        return [own];
    }

    // A status of its own plus the show's: two statements, and the show keeps
    // its own whether or not it is the same word — dropping a show is a dated
    // history entry, not a flag to flip from here. Said plainly, with where
    // that second status does come off: a started season goes on to wear it,
    // so a second clear from this card reaches the show (ResumeAttempt); an
    // unbegun one goes blank, and only the show's page is left.
    const rootDropped = state.rootStatus === 'dropped';

    if ((state.watchedEpisodes ?? 0) > 0) {
        return [own, rootDropped ? trans('media.the_show_itself_stays_dropped_so') : trans('media.the_show_itself_stays_on_hold')];
    }

    return [own, rootDropped ? trans('media.the_show_itself_stays_dropped_clearing') : trans('media.the_show_itself_stays_on_hold_2')];
}

/**
 * The moment to write, as the API takes it. A `datetime-local` value is naive
 * wall time in the viewer's timezone — it leaves the client as a universal
 * instant. Null while the picked date is not a date.
 */
export function watchedAtChoice(mode: WhenMode, date: string): WatchedAtChoice | null {
    if (mode !== 'custom') {
        return mode;
    }

    const picked = dateTimeInputInstant(date);

    return picked === null ? null : (picked.toISOString() as IsoInstant);
}

/** What kind of write the dialog's one action is, which its colour says: logging a play, logging progress, dropping, resuming. */
export type ActionTone = 'watched' | 'progress' | 'drop' | 'hold';

/**
 * The colours of a kind of write, decided once: the fill of its button (with
 * the ink on it), the colour of its words elsewhere, and the tint of its row.
 */
export const TONE_LOOK: Record<ActionTone, { fill: string; color: string; tint: RowTint }> = {
    watched: { fill: 'bg-action-watched text-on-bright', color: 'var(--color-action-watched)', tint: 'watched' },
    progress: { fill: 'bg-brand text-on-accent', color: 'var(--color-status-progress)', tint: 'progress' },
    drop: { fill: 'bg-remove text-on-remove', color: 'var(--color-remove)', tint: 'remove' },
    hold: { fill: 'bg-status-onhold text-on-bright', color: 'var(--color-status-onhold)', tint: 'hold' },
};

export function actionTone(intent: WatchedIntent, state: UserMediaState): ActionTone {
    switch (intent) {
        case 'drop':
            return 'drop';
        case 'status':
            return state.status === 'dropped' ? 'drop' : 'hold';
        case 'session':
            return 'progress';
        case 'complete':
            return 'watched';
    }
}

/**
 * A row under the dialog's one action: a write of its own, a step to one
 * (drop, correct the total, complete, add a session), or the history.
 */
export type StatusRow =
    'wholeRun' | 'nextEpisode' | 'complete' | 'addSession' | 'correctTotal' | 'hold' | 'drop' | 'unmark' | 'removeProgress' | 'cycles' | 'history';

/**
 * The rows of the view the dialog opens on, in their order. Only what can be
 * done is offered: a completed playable has no open attempt to drop, a title
 * on hold is not put on hold again. A step has no rows: its action is the
 * whole question.
 */
export function statusRows(
    media: MediaSummary,
    state: UserMediaState,
    intent: WatchedIntent,
    has: { history: boolean; cycles: boolean },
): StatusRow[] {
    const playable = isPlayable(media);
    const container = !playable;
    const canHold = hasUserStatus(media) && state.status !== 'on_hold';
    const rows: StatusRow[] = [];
    const offer = (row: StatusRow, when: boolean): void => {
        if (when) {
            rows.push(row);
        }
    };

    if (intent === 'status') {
        const nextEpisode = state.status === 'on_hold' && container && state.nextEpisode !== null;

        offer('nextEpisode', nextEpisode);
        offer('complete', !nextEpisode && !container);
        offer('drop', state.status === 'on_hold');
    } else if (intent === 'session') {
        offer('correctTotal', !isPositionCounter(media) && (state.progress ?? 0) > 0);
        offer('complete', true);
        offer('hold', canHold);
        offer('drop', true);
        offer('removeProgress', state.status === 'in_progress');
    } else if (intent === 'complete') {
        offer('wholeRun', episodeStep(media, state, intent) !== null);
        offer('addSession', hasProgress(media) && state.watched);
        offer('hold', canHold);
        offer('drop', !(playable && state.watched && state.status === null));
        // Unmarking empties the current run, so it needs something watched in it; a
        // container without episode counters (a specials season) counts its plays.
        offer('unmark', container && unmarkCount(state) > 0);
    }

    offer('cycles', has.cycles && intent !== 'drop');
    offer('history', has.history && intent !== 'drop');

    return rows;
}

/** The history a row sums up: how many plays, and the latest one's moment. */
export interface HistorySummary {
    count: number;
    last: string | null;
}

/** A row's name and the line under it, which says what it does to this title. */
export function statusRowText(
    row: StatusRow,
    media: MediaSummary,
    state: UserMediaState,
    history: HistorySummary,
): { label: string; description: string } {
    const watchedEpisodes = state.watchedEpisodes ?? 0;

    switch (row) {
        case 'wholeRun':
            return {
                label: media.type === 'season' ? trans('media.watch_the_whole_season') : trans('media.watch_the_whole_show'),
                description:
                    watchedEpisodes > 0
                        ? transChoice('media.the_remaining_count_episodes', state.remainingEpisodes ?? 0, {
                              count: String(state.remainingEpisodes ?? 0),
                          })
                        : transChoice('media.all_count_episodes_with_the_same_date', state.airedEpisodes ?? 0, {
                              count: String(state.airedEpisodes ?? 0),
                          }),
            };
        case 'nextEpisode':
            return { label: nextEpisodeLabel(state), description: trans('media.resumes_and_logs_the_episode') };
        case 'complete':
            return {
                label: state.status !== null && state.status !== 'in_progress' ? completeLabel(media, state) : trans('media.complete'),
                description: completeDescription(media, state),
            };
        case 'addSession':
            return { label: trans('media.add_session'), description: trans('media.log_more_hours_to_this_play') };
        case 'correctTotal':
            return {
                label: trans('media.correct_the_total'),
                description: trans('media.logged_so_far_time', { time: formatProgress(media, state.progress) ?? '' }),
            };
        case 'hold':
            return { label: trans('media.put_on_hold'), description: trans('media.stays_out_of_up_next_until_you_resume') };
        case 'drop':
            return { label: trans('media.drop'), description: trans('media.saved_in_history_as_an_unfinished_attempt') };
        case 'unmark': {
            const count = unmarkCount(state);

            // Past the first run, only the current one is emptied: the line says so.
            return {
                label: trans('media.mark_as_unwatched'),
                description: transChoice(
                    state.cycle > 1 ? 'media.removes_plays_from_count_episodes_of_this_cycle' : 'media.removes_plays_from_count_episodes',
                    count,
                    { count: String(count) },
                ),
            };
        }
        case 'removeProgress':
            return { label: trans('media.remove_progress'), description: trans('media.clears_the_progress_and_the_status') };
        case 'cycles':
            return {
                label: trans('media.watch_cycles'),
                description: transChoice('media.count_cycles_the_current_one_number', state.cycle, {
                    count: String(state.cycle),
                    number: String(state.cycle),
                }),
            };
        case 'history':
            return {
                label: trans('common.history'),
                description: transChoice('media.count_plays_last_on_date', history.count, {
                    count: String(history.count),
                    date: formatDateTime(history.last) ?? '',
                }),
            };
    }
}

/** What completing writes, said before it happens. */
function completeDescription(media: MediaSummary, state: UserMediaState): string {
    if (!hasProgress(media)) {
        return trans('media.logged_with_todays_date');
    }

    if (isPositionCounter(media)) {
        return trans('media.saved_in_history_at_the_position');
    }

    return (state.progress ?? 0) > 0 ? trans('media.saved_in_history_with_the_hours_played') : trans('media.saved_in_history_with_or_without_hours');
}

/** The episode a show or a season is at, as the button that watches it names it: "Watch S02E04". */
export function nextEpisodeLabel(state: UserMediaState): string {
    const next = state.nextEpisode;

    return next !== null && next.seasonNumber !== null && next.number !== null
        ? trans('media.watch_episode_code', { code: episodeCode(next.seasonNumber, next.number) })
        : trans('media.watch_the_next_episode');
}

/**
 * How many episodes marking a show or a season unwatched empties: those
 * watched in the current run; a container without episode counters (a
 * specials season) counts its plays.
 */
export function unmarkCount(state: UserMediaState): number {
    return state.watchedEpisodes ?? state.plays;
}

/**
 * The facts under the title beside the status: an episode's code, the show a
 * season belongs to, how many episodes an untouched show holds, the day a
 * title was dropped (when no amount says more).
 */
export function statusFacts(media: MediaSummary, state: UserMediaState, withCaption: boolean): string[] {
    const untouchedRun = !withCaption && !isPlayable(media) && (state.airedEpisodes ?? 0) > 0;
    const droppedOn = state.status === 'dropped' && !state.droppedAmount ? formatTimestampDate(state.droppedAt) : null;

    return [
        leafCode(media),
        showTitleOf(media),
        untouchedRun ? transChoice('common.count_episode', state.airedEpisodes ?? 0, { count: String(state.airedEpisodes ?? 0) }) : null,
        droppedOn,
    ].filter((fact): fact is string => fact !== null);
}

/** A step of the dialog, as it names itself under the title: its words, in the colour of its write. */
export function stepName(
    media: MediaSummary,
    step: { intent: WatchedIntent; correcting: boolean; cycles: boolean },
): { text: string; color: string } {
    if (step.cycles) {
        return { text: trans('media.watch_cycles'), color: 'var(--color-ink-muted)' };
    }

    if (step.correcting) {
        return { text: trans('media.correct_the_total'), color: 'var(--color-ink-muted)' };
    }

    if (step.intent === 'drop') {
        return { text: trans('media.drop'), color: TONE_LOOK.drop.color };
    }

    if (step.intent === 'complete') {
        return { text: trans('media.complete'), color: TONE_LOOK.watched.color };
    }

    return { text: isPositionCounter(media) ? trans('media.update_progress') : trans('media.add_session'), color: TONE_LOOK.progress.color };
}
