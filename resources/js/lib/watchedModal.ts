import { dateTimeInputInstant } from '@/lib/format';
import { mediaTypeDefinition } from '@/lib/mediaTypes';
import { formatProgress, hasProgress, isPositionCounter, playActionLabels, positionText } from '@/lib/progress';
import { shownStatus, shownStatusVisual } from '@/lib/status';
import type { UserMediaState } from '@/types/generated';
import type { IsoInstant, MediaSummary, WatchedAtChoice } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

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
