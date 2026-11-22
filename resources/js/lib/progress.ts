import { durationTerms, formatNumber, formatTimestampDate, formatWatchTime, type DurationTerm } from '@/lib/format';
import { leafType, mediaTypeDefinition, mediaTypeMeta, readUnitOf, typeCountingIn } from '@/lib/mediaTypes';
import type { TooltipRow } from '@/lib/tooltip';
import type { UserMediaState } from '@/types/generated';
import type { MediaSummary, ProgressEntry, ProgressUnit, WatchStats } from '@/types/media';
import type { LucideIcon } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';

/**
 * Wording of the play action, from what the type counts: time played reads
 * as a game ("completed"), a position as reading ("read"), nothing as
 * watching ("watched").
 *
 * `start` is the action a type with a progress counter leads with while it
 * is untouched — nobody opens a fresh book to declare it read, they open it
 * to start reading. Null on types that only ever get marked in one go.
 */
export function playActionLabels(media: MediaSummary): { done: string; mark: string; start: string | null } {
    switch (progressUnit(media)) {
        case 'minutes':
            return { done: trans('common.completed'), mark: trans('media.mark_as_completed'), start: trans('media.start_playing') };
        case 'pages':
        case 'chapters':
        case 'issues':
            return { done: trans('media.read'), mark: trans('media.mark_as_read'), start: trans('media.start_reading') };
        default:
            return { done: trans('media.watched'), mark: trans('media.mark_as_watched'), start: null };
    }
}

/**
 * How many times a title was finished: a playable title counts its completed
 * plays, a container (a season, a show) its finished runs, not the episodes
 * inside them.
 */
export function timesFinished(media: MediaSummary, state: Pick<UserMediaState, 'plays' | 'completedCycles'>): number {
    return mediaTypeDefinition(media.type).playable ? state.plays : state.completedCycles;
}

/**
 * When a finished title was last finished, in the type's word, and how many
 * times once it is more than one: "Watched March 12, 2026 – 3×". Null for a
 * title not finished, or finished on no known day.
 */
export function finishedOn(media: MediaSummary, state: Pick<UserMediaState, 'watched' | 'watchedAt' | 'plays' | 'completedCycles'>): string | null {
    const date = state.watched ? formatTimestampDate(state.watchedAt) : null;

    if (date === null) {
        return null;
    }

    const times = timesFinished(media, state);
    const replacements = { date, count: String(times) };

    switch (progressUnit(media)) {
        case 'minutes':
            return transChoice('media.completed_on_date', times, replacements);
        case 'pages':
        case 'chapters':
        case 'issues':
            return transChoice('media.read_on_date', times, replacements);
        default:
            return transChoice('media.watched_on_date', times, replacements);
    }
}

/** Unit of user-recorded progress (sessions), from the type's definition; null when there is none. */
export function progressUnit(media: MediaSummary): ProgressUnit | null {
    return mediaTypeDefinition(media.type).progressUnit;
}

/** Flat types with user-recorded progress (sessions). */
export function hasProgress(media: MediaSummary): boolean {
    return progressUnit(media) !== null;
}

/**
 * Position-counter units (pages / chapters / issues): the amount means
 * "where I am" and every save overwrites it — unlike additive game hours.
 */
export function isPositionCounter(media: MediaSummary): boolean {
    const unit = progressUnit(media);

    return unit !== null && unit !== 'minutes';
}

/**
 * The unit-specific UI strings of a position counter. One lookup so every
 * surface (modals, cards, progress bars) words pages, chapters and issues
 * consistently; null for time-based and non-progress types.
 */
export function positionText(media: MediaSummary): {
    currentField: string;
    shortUnit: string;
    totalField: string;
    correctCounter: string;
    editAmounts: string;
    noneRecorded: string;
    noProgressYet: string;
    dropDescription: string;
    anotherRun: string;
    currentlyOn: (current: number) => string;
    positionOf: (current: number, total: number) => string;
} | null {
    switch (progressUnit(media)) {
        case 'pages':
            return {
                currentField: trans('media.current_page'),
                shortUnit: trans('media.page'),
                totalField: trans('media.total_pages_read_optional'),
                correctCounter: trans('media.correct_the_page_counter'),
                editAmounts: trans('media.edit_pages'),
                noneRecorded: trans('media.no_pages_recorded'),
                noProgressYet: trans('media.no_reading_progress_yet'),
                dropDescription: trans('media.the_dropped_attempt_lands_in_your_5'),
                anotherRun: trans('media.this_starts_another_read_pages_of'),
                currentlyOn: (current) => trans('media.currently_on_page_page', { page: formatNumber(current) }),
                positionOf: (current, total) => trans('common.page_current_of_total', { current: formatNumber(current), total: formatNumber(total) }),
            };
        case 'chapters':
            return {
                currentField: trans('media.current_chapter'),
                shortUnit: trans('media.chapter'),
                totalField: trans('media.total_chapters_read_optional'),
                correctCounter: trans('media.correct_the_chapter_counter'),
                editAmounts: trans('media.edit_chapters'),
                noneRecorded: trans('media.no_chapters_recorded'),
                noProgressYet: trans('media.no_reading_progress_yet'),
                dropDescription: trans('media.the_dropped_attempt_lands_in_your_2'),
                anotherRun: trans('media.this_starts_another_read_chapters_of'),
                currentlyOn: (current) => trans('media.currently_on_chapter_chapter', { chapter: formatNumber(current) }),
                positionOf: (current, total) =>
                    trans('media.chapter_current_of_total', { current: formatNumber(current), total: formatNumber(total) }),
            };
        case 'issues':
            return {
                currentField: trans('media.current_issue'),
                shortUnit: trans('media.issue'),
                totalField: trans('media.total_issues_read_optional'),
                correctCounter: trans('media.correct_the_issue_counter'),
                editAmounts: trans('media.edit_issues'),
                noneRecorded: trans('media.no_issues_recorded'),
                noProgressYet: trans('media.no_reading_progress_yet'),
                dropDescription: trans('media.the_dropped_attempt_lands_in_your_4'),
                anotherRun: trans('media.this_starts_another_read_issues_of'),
                currentlyOn: (current) => trans('media.currently_on_issue_issue', { issue: formatNumber(current) }),
                positionOf: (current, total) => trans('media.issue_current_of_total', { current: formatNumber(current), total: formatNumber(total) }),
            };
        default:
            return null;
    }
}

/**
 * Upper bound for progress inputs: the total a position counter runs to —
 * a book's page count, a manga's chapters, a comic volume's issues (hours
 * have no natural cap). Null = leave the input unbounded.
 */
export function progressInputMax(media: MediaSummary): number | null {
    const unit = progressUnit(media);

    if (unit === null || unit === 'minutes') {
        return null;
    }

    const total = media.progressTotal;

    return total !== null && total > 0 ? total : null;
}

/**
 * How much of a position counter is still ahead — the pages, chapters or
 * issues left of the known total. Null = nothing to count down to: a
 * time-based counter has no total, and before the first page the whole
 * thing is left, which the caption already says.
 */
export function progressRemaining(media: MediaSummary, current: number | null): number | null {
    const total = progressInputMax(media);

    if (total === null || !current || current <= 0) {
        return null;
    }

    return Math.max(0, total - current);
}

/** What a progress strip under a card reads: the bar's fill (null = no bar), the caption, its short form for narrow screens and what is left. */
export interface ProgressView {
    percent: number | null;
    caption: string;
    mobileCaption: string | null;
    remaining: number | null;
}

/**
 * The strip of a type with its own counter: a position (page, chapter,
 * issue) out of the known total, or the time a game has been played — which
 * has no total, so no bar and nothing left to count down.
 */
export function counterView(media: MediaSummary, amount: number): ProgressView {
    const position = positionText(media);
    const total = progressInputMax(media);

    let caption: string;

    if (amount <= 0) {
        caption = position?.noProgressYet ?? trans('common.no_sessions_logged_yet');
    } else if (position) {
        caption = total !== null ? position.positionOf(amount, total) : position.currentlyOn(amount);
    } else {
        caption = trans('common.time_played', { time: formatProgress(media, amount) ?? '' });
    }

    return {
        percent: position && total !== null ? Math.min(100, Math.round((amount / total) * 100)) : null,
        caption,
        mobileCaption:
            position && total !== null && amount > 0
                ? trans('common.current_of_total', { current: formatNumber(amount), total: formatNumber(total) })
                : null,
        remaining: progressRemaining(media, amount),
    };
}

/**
 * The strip of a title on the In Progress tab. A counter type reads its
 * counter; a show reads like an Up Next card — which aired episode is next,
 * or, with nothing left between, what was watched; the remainder counts the
 * episodes neither watched nor skipped. A paused film has no strip.
 */
/** How much of a run is watched, in whole percent, never past the whole. */
export function episodeShare(watched: number, aired: number): number {
    return aired > 0 ? Math.min(100, Math.round((watched / aired) * 100)) : 0;
}

export function activityProgress(entry: ProgressEntry): ProgressView | null {
    if (hasProgress(entry.media)) {
        return counterView(entry.media, entry.progress ?? 0);
    }

    const aired = entry.airedEpisodes;

    if (aired == null) {
        return null;
    }

    const watched = entry.watchedEpisodes ?? 0;

    return {
        percent: episodeShare(watched, aired),
        caption:
            entry.position != null
                ? trans('common.episode_current_of_total', { current: formatNumber(entry.position), total: formatNumber(aired) })
                : trans('common.current_of_total_episodes_watched', { current: formatNumber(watched), total: formatNumber(aired) }),
        mobileCaption: trans('common.current_of_total', { current: formatNumber(entry.position ?? watched), total: formatNumber(aired) }),
        remaining: entry.remainingEpisodes ?? null,
    };
}

/**
 * Stored progress amount → input field value: minutes become hours (the
 * inputs ask for hours, with a dot decimal), position counters stay raw.
 */
export function progressToInput(media: MediaSummary, amount: number | null): string {
    if (!amount || amount <= 0) {
        return '';
    }

    return progressUnit(media) === 'minutes' ? String(Math.round((amount / 60) * 10) / 10) : String(amount);
}

/**
 * A number as typed into a field, decimal comma accepted. Null when the field
 * holds none — an empty field is not zero.
 */
export function parseDecimal(value: string | number): number | null {
    if (typeof value === 'string' && value.trim() === '') {
        return null;
    }

    const parsed = Number(String(value).replace(',', '.'));

    return Number.isFinite(parsed) ? parsed : null;
}

/** How far a progress field's arrows move: whole pages, chapters and issues, half hours. */
export function progressInputStep(media: MediaSummary): number {
    return isPositionCounter(media) ? 1 : 0.5;
}

/**
 * Input field value → stored progress amount (null = not a usable number).
 * Hours accept a decimal comma; position counters are whole numbers,
 * hard-capped at the known total (the input's `max` only guards the
 * spinner, not typing).
 */
export function progressFromInput(media: MediaSummary, value: string | number): number | null {
    const parsed = parseDecimal(value);

    if (parsed === null || parsed <= 0) {
        return null;
    }

    const amount = progressUnit(media) === 'minutes' ? Math.round(parsed * 60) : Math.round(parsed);
    const max = progressInputMax(media);

    return max === null ? amount : Math.min(amount, max);
}

/** A unit something is read in: a position counter, not time. */
export type ReadUnit = Exclude<ProgressUnit, 'minutes'>;

/** A progress unit as one that is read; null for time. */
export function asReadUnit(unit: ProgressUnit | null): ReadUnit | null {
    return unit === null || unit === 'minutes' ? null : unit;
}

/**
 * An amount named in its own unit, e.g. "320 pages" / "12 chapters". Minutes
 * are the odd one out: they read as a duration, so the caller picks the
 * duration format that fits its surface (short "3h 20min", spelled-out
 * "3 hours 20 minutes") and this one only spells the counters.
 */
export function formatUnitAmount(unit: ReadUnit, amount: number): string {
    // `:amount`, not `:count`: transChoice writes the raw number into :count,
    // which would drop the thousands separator.
    const replacements = { amount: formatNumber(amount) };

    switch (unit) {
        case 'pages':
            return transChoice('media.count_pages', amount, replacements);
        case 'chapters':
            return transChoice('media.count_chapters', amount, replacements);
        case 'issues':
            return transChoice('media.count_issues', amount, replacements);
    }
}

/** Accumulated progress as a short label, e.g. "12,5h" / "320 pages". */
export function formatProgress(media: MediaSummary, amount: number | null): string | null {
    const unit = progressUnit(media);

    if (!amount || unit === null) {
        return null;
    }

    return unit === 'minutes' ? `${formatNumber(Math.round((amount / 60) * 10) / 10, 1)}h` : formatUnitAmount(unit, amount);
}

/**
 * The numbers behind an episode progress bar, in whatever scope the bar draws
 * — a whole show on its own page or an Up Next card, a single season on the
 * season page.
 */
export type EpisodeProgress = {
    watchedEpisodes?: number | null;
    airedEpisodes?: number | null;
    /** Settled without being watched: the grey holes in the bar. */
    skippedEpisodes?: number | null;
    /**
     * Runtime of what is left and of what is behind. An episode the provider
     * left without a runtime is priced at the length the show declares for
     * one, which is how the dashboard tiles price it too.
     */
    remainingMinutes?: number | null;
    watchedMinutes?: number | null;
};

/**
 * What the bar itself cannot say: how long the rest takes, how much of the run
 * is really behind you, and how many episodes were settled without watching —
 * the only explanation the grey holes ever get. Null where there is nothing to
 * report (no aired episodes), which is also how a caller drops the hint.
 */
export function episodeProgressRows(progress: EpisodeProgress): TooltipRow[] | null {
    const aired = progress.airedEpisodes ?? 0;

    if (aired <= 0) {
        return null;
    }

    const watched = progress.watchedEpisodes ?? 0;
    const skipped = progress.skippedEpisodes ?? 0;
    const remainingMinutes = progress.remainingMinutes ?? 0;
    const watchedMinutes = progress.watchedMinutes ?? 0;

    const rows: TooltipRow[] = [
        {
            label: trans('media.watched'),
            value: trans('common.current_of_total_percent', {
                current: formatNumber(watched),
                total: formatNumber(aired),
                percent: formatNumber(Math.round((watched / aired) * 100)),
            }),
        },
    ];

    // Worded exactly like the sort that ranks shows by it, so the number and
    // the order it feeds are recognisably the same thing.
    if (remainingMinutes > 0) {
        rows.push({ label: trans('common.left_to_finish'), value: formatWatchTime(remainingMinutes) });
    }

    if (skipped > 0) {
        rows.push({ label: trans('common.skipped'), value: formatNumber(skipped) });
    }

    if (watchedMinutes > 0) {
        rows.push({ label: trans('common.time_watched'), value: formatWatchTime(watchedMinutes) });
    }

    return rows;
}

/**
 * The glyph a unit reads by: the glyph of the medium that counts in it — the
 * gamepad for playtime, the book for pages. Null when no type here counts in
 * it.
 */
export function unitGlyph(unit: ProgressUnit): LucideIcon | null {
    const type = typeCountingIn(unit);

    return type === null ? null : mediaTypeMeta(type).icon;
}

/**
 * A type's total as headline terms: time spelled out ("3 days 7 hours") for
 * what is watched or played, the unit's count ("320 pages") for what is read.
 * Reading types count positions, not time, so their total is in that unit.
 */
export function amountTerms(type: string, amount: number): DurationTerm[] {
    const unit = readUnitOf(type);

    return unit === null ? durationTerms(amount) : [{ value: formatNumber(amount), unit: unitWord(unit, amount) }];
}

/** The unit's own word beside a number set large ("320" / "pages"). */
export function unitWord(unit: Exclude<ProgressUnit, 'minutes'>, count: number): string {
    switch (unit) {
        case 'pages':
            return transChoice('dashboard.page', count);
        case 'chapters':
            return transChoice('dashboard.chapter', count);
        case 'issues':
            return transChoice('dashboard.issue', count);
    }
}

/**
 * What a type's totals are made of, each in its own words: "82 movies",
 * "51 shows" and "1,203 episodes" for a container, "8 books" and "1,355
 * pages" for a type that is read, whose time is an estimate and so names
 * what was read beside it.
 */
export function statCounts(type: string, stats: WatchStats): string[] {
    const meta = mediaTypeMeta(type);
    const leaf = leafType(type);

    const unit = readUnitOf(type);

    if (stats.estimatedMinutes !== null && unit !== null) {
        return [meta.count(stats.titles), formatUnitAmount(unit, stats.minutes)];
    }

    return leaf === type ? [meta.count(stats.watched)] : [meta.count(stats.titles), mediaTypeMeta(leaf).count(stats.watched)];
}
