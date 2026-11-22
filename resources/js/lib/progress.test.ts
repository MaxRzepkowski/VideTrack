import {
    activityProgress,
    amountTerms,
    counterView,
    episodeProgressRows,
    episodeShare,
    finishedOn,
    formatProgress,
    formatUnitAmount,
    hasProgress,
    isPositionCounter,
    parseDecimal,
    playActionLabels,
    positionText,
    progressFromInput,
    progressInputMax,
    progressInputStep,
    progressRemaining,
    progressToInput,
    statCounts,
} from '@/lib/progress';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import type { ProgressEntry } from '@/types/media';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

const book = (pages: number | null = 300) => mediaSummary({ type: 'book', progressTotal: pages });
const game = () => mediaSummary({ type: 'game' });
const show = () => mediaSummary({ type: 'show' });

function entry(overrides: Partial<ProgressEntry>): ProgressEntry {
    return { id: 1, at: null, media: show(), ...overrides };
}

describe('progress units', () => {
    it('reads the unit off the type definition', () => {
        expect(hasProgress(game())).toBe(true);
        expect(isPositionCounter(game())).toBe(false);
        expect(isPositionCounter(book())).toBe(true);
        expect(hasProgress(show())).toBe(false);
    });

    it('treats a type this instance does not run as having no counter', () => {
        useMediaTypes([]);

        expect(hasProgress(book())).toBe(false);
    });

    it('caps a position counter at the known total, never a time counter', () => {
        expect(progressInputMax(book(300))).toBe(300);
        expect(progressInputMax(book(null))).toBeNull();
        expect(progressInputMax(game())).toBeNull();
    });

    it('counts what is left only once there is a start and a total', () => {
        expect(progressRemaining(book(300), 120)).toBe(180);
        expect(progressRemaining(book(300), 0)).toBeNull();
        expect(progressRemaining(book(300), 400)).toBe(0);
        expect(progressRemaining(game(), 60)).toBeNull();
    });

    it('formats game minutes as hours and positions in their unit', () => {
        expect(formatProgress(game(), 90)).toBe('1.5h');
        expect(formatProgress(book(), 12)).toBe('12 pages');
        expect(formatProgress(book(), null)).toBeNull();
    });
});

describe('counterView', () => {
    it('reads a position out of the known total, with a bar', () => {
        expect(counterView(book(300), 150)).toEqual({
            percent: 50,
            caption: 'Page 150 of 300',
            mobileCaption: '150 of 300',
            remaining: 150,
        });
    });

    it('reads a position without a total as where the reader is, without a bar', () => {
        const view = counterView(book(null), 42);

        expect(view.percent).toBeNull();
        expect(view.mobileCaption).toBeNull();
        expect(view.remaining).toBeNull();
        expect(view.caption).toContain('42');
    });

    it('says nothing was read yet before the first page', () => {
        expect(counterView(book(300), 0).caption).toBe('No reading progress yet');
    });

    it('reads a game as time played, with no bar and nothing left', () => {
        expect(counterView(game(), 90)).toEqual({ percent: null, caption: '1.5h played', mobileCaption: null, remaining: null });
        expect(counterView(game(), 0).caption).toBe('No sessions logged yet');
    });
});

describe('activityProgress', () => {
    it('gives a paused film no strip', () => {
        expect(activityProgress(entry({ media: mediaSummary({ type: 'movie' }) }))).toBeNull();
    });

    it('reads a show like an Up Next card: the next aired episode', () => {
        const view = activityProgress(entry({ airedEpisodes: 10, watchedEpisodes: 4, position: 5, remainingEpisodes: 6 }));

        expect(view).toEqual({ percent: 40, caption: 'Episode 5 of 10', mobileCaption: '5 of 10', remaining: 6 });
    });

    it('falls back to what was watched when no episode is next', () => {
        const view = activityProgress(entry({ airedEpisodes: 10, watchedEpisodes: 10 }));

        expect(view?.caption).toBe('10 of 10 episodes watched');
        expect(view?.percent).toBe(100);
    });

    it('reads a counter type through its counter', () => {
        expect(activityProgress(entry({ media: book(200), progress: 50 }))).toEqual(counterView(book(200), 50));
    });
});

describe('wording and inputs', () => {
    it('words the play action after what the type counts', () => {
        expect(playActionLabels(game()).mark).toBe('Mark as completed');
        expect(playActionLabels(book()).start).toBe('Start reading');
        expect(playActionLabels(show())).toEqual({ done: 'Watched', mark: 'Mark as watched', start: null });
    });

    it('names an amount in its unit, singular for one', () => {
        expect(formatUnitAmount('pages', 1)).toBe('1 page');
        expect(formatUnitAmount('pages', 1234)).toBe('1,234 pages');
        expect(formatUnitAmount('chapters', 1)).toBe('1 chapter');
        expect(formatUnitAmount('issues', 3)).toBe('3 issues');
    });

    it('gives each position unit its own words', () => {
        expect(positionText(mediaSummary({ type: 'manga' }))?.currentlyOn(4)).toBe('Currently on chapter 4');
        expect(positionText(mediaSummary({ type: 'comic' }))?.positionOf(2, 9)).toBe('Issue 2 of 9');
        expect(positionText(game())).toBeNull();
    });

    it('shows game minutes as hours in the input and reads hours back with a comma', () => {
        expect(progressToInput(game(), 90)).toBe('1.5');
        expect(progressToInput(book(), 12)).toBe('12');
        expect(progressToInput(book(), 0)).toBe('');
        expect(progressFromInput(game(), '1,5')).toBe(90);
        expect(progressFromInput(book(300), '120.4')).toBe(120);
    });

    it('caps a typed position at the known total and refuses what is not a number', () => {
        expect(progressFromInput(book(300), '999')).toBe(300);
        expect(progressFromInput(book(null), '999')).toBe(999);
        expect(progressFromInput(book(), 'abc')).toBeNull();
        expect(progressFromInput(book(), '0')).toBeNull();
    });

    it('reads a typed number with either decimal mark, and an empty field as none', () => {
        expect(parseDecimal('1,5')).toBe(1.5);
        expect(parseDecimal(2)).toBe(2);
        expect(parseDecimal('0')).toBe(0);
        expect(parseDecimal('')).toBeNull();
        expect(parseDecimal('  ')).toBeNull();
        expect(parseDecimal('abc')).toBeNull();
    });

    it('steps positions by one and hours by half', () => {
        expect(progressInputStep(book())).toBe(1);
        expect(progressInputStep(game())).toBe(0.5);
    });
});

describe('episodeProgressRows', () => {
    it('reports what the bar cannot say, skipping empty rows', () => {
        expect(episodeProgressRows({ watchedEpisodes: 3, airedEpisodes: 10, remainingMinutes: 300, skippedEpisodes: 1, watchedMinutes: 90 })).toEqual(
            [
                { label: 'Watched', value: '3 of 10 (30%)' },
                { label: 'Left to finish', value: '5 hours' },
                { label: 'Skipped', value: '1' },
                { label: 'Time watched', value: '1 hour 30 minutes' },
            ],
        );
        expect(episodeProgressRows({ watchedEpisodes: 0, airedEpisodes: 4 })).toHaveLength(1);
    });

    it('has no hint before anything aired', () => {
        expect(episodeProgressRows({ airedEpisodes: 0 })).toBeNull();
        expect(episodeProgressRows({})).toBeNull();
    });
});

describe('amountTerms', () => {
    it('spells out time for what is watched or played, the unit for what is read', () => {
        expect(amountTerms('movie', 135)).toEqual([
            { value: '2', unit: 'hours' },
            { value: '15', unit: 'minutes' },
        ]);
        expect(amountTerms('book', 1234)).toEqual([{ value: '1,234', unit: 'pages' }]);
    });
});

describe('statCounts', () => {
    it("names what a type's totals are made of, a read type with what was read", () => {
        useMediaTypes();

        expect(statCounts('movie', { minutes: 250, watched: 2, plays: 2, titles: 2, estimatedMinutes: null })).toEqual(['2 movies']);
        expect(statCounts('show', { minutes: 420, watched: 10, plays: 10, titles: 1, estimatedMinutes: null })).toEqual(['1 show', '10 episodes']);
        expect(statCounts('book', { minutes: 1355, watched: 8, plays: 8, titles: 8, estimatedMinutes: 1626 })).toEqual(['8 books', '1,355 pages']);
    });
});

describe('episodeShare', () => {
    it('is the watched share in whole percent, never past the whole, and nothing of nothing', () => {
        expect(episodeShare(13, 19)).toBe(68);
        expect(episodeShare(12, 10)).toBe(100);
        expect(episodeShare(0, 0)).toBe(0);
    });
});

describe('finishedOn', () => {
    const at = '2026-03-12T20:00:00+00:00';

    it("says when a title was finished, in its type's word, and how often once it is more than one", () => {
        expect(finishedOn(game(), userState({ watched: true, watchedAt: at, plays: 1 }))).toBe('Completed March 12, 2026');
        expect(finishedOn(game(), userState({ watched: true, watchedAt: at, plays: 3 }))).toBe('Completed March 12, 2026 – 3×');
        expect(finishedOn(book(), userState({ watched: true, watchedAt: at, plays: 1 }))).toBe('Read March 12, 2026');
    });

    it('counts a show by its finished runs, not by the episodes in them', () => {
        expect(finishedOn(show(), userState({ watched: true, watchedAt: at, plays: 10, completedCycles: 1 }))).toBe('Watched March 12, 2026');
        expect(finishedOn(show(), userState({ watched: true, watchedAt: at, plays: 20, completedCycles: 2 }))).toBe('Watched March 12, 2026 – 2×');
    });

    it('says nothing of a title not finished, or finished on no known day', () => {
        expect(finishedOn(show(), userState({ watched: false, watchedAt: at }))).toBeNull();
        expect(finishedOn(show(), userState({ watched: true, watchedAt: null }))).toBeNull();
    });
});
