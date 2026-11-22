import {
    actionLabel,
    actionTone,
    completeLabel,
    dropDescription,
    episodeStep,
    nextEpisodeLabel,
    openingIntent,
    statusCaption,
    statusDescription,
    statusFacts,
    statusRows,
    statusRowText,
    stepName,
    unmarkCount,
    watchedAtChoice,
} from '@/lib/watchedModal';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

const movie = () => mediaSummary({ type: 'movie' });
const show = () => mediaSummary({ type: 'show', title: 'Severance' });
const season = () => mediaSummary({ type: 'season', title: 'Season 2' });
const game = () => mediaSummary({ type: 'game' });
const book = () => mediaSummary({ type: 'book', progressTotal: 400 });

const quiet = { intent: 'complete' as const, correcting: false, step: null };

describe('where the modal opens', () => {
    it('on taking a status off before anything else', () => {
        expect(openingIntent(movie(), userState({ status: 'on_hold' }), 'dropped')).toBe('status');
        expect(openingIntent(show(), userState({ status: 'dropped' }), 'watched')).toBe('status');
    });

    it('on the view the user clicked, then on a session for an unfinished counter type', () => {
        expect(openingIntent(movie(), userState(), 'dropped')).toBe('drop');
        expect(openingIntent(book(), userState(), 'watched')).toBe('session');
        expect(openingIntent(book(), userState({ watched: true }), 'watched')).toBe('complete');
        expect(openingIntent(movie(), userState(), 'watched')).toBe('complete');
    });
});

describe('the episode button', () => {
    it('leads with the first episode of an untouched show, the next one once it is under way', () => {
        expect(episodeStep(show(), userState(), 'complete')).toBe('first');
        expect(episodeStep(season(), userState({ watchedEpisodes: 0, position: 3 }), 'complete')).toBe('next');
        expect(
            episodeStep(
                show(),
                userState({ watchedEpisodes: 4, nextEpisode: { ...mediaSummary({ type: 'episode' }), seasonNumber: 1 } }),
                'complete',
            ),
        ).toBe('next');
    });

    it('stays away when nothing is left, from other views and from playables', () => {
        expect(episodeStep(show(), userState({ watchedEpisodes: 4, nextEpisode: null }), 'complete')).toBeNull();
        expect(episodeStep(show(), userState({ watched: true }), 'complete')).toBeNull();
        expect(episodeStep(show(), userState({ status: 'on_hold' }), 'complete')).toBeNull();
        expect(episodeStep(show(), userState(), 'drop')).toBeNull();
        expect(episodeStep(movie(), userState(), 'complete')).toBeNull();
    });
});

describe('the caption under the title', () => {
    it('names the status in its colour, with its amount unless the field already shows it', () => {
        expect(statusCaption(game(), userState({ status: 'dropped', droppedAmount: 90 }), true)).toEqual({
            parts: ['Dropped', '1.5h'],
            color: 'var(--color-status-dropped)',
        });
        expect(statusCaption(book(), userState({ status: 'in_progress', progress: 10 }), true)?.parts).toEqual(['Reading', null]);
        expect(statusCaption(game(), userState({ status: 'in_progress', progress: 120 }), false)?.parts).toEqual(['Playing', '2h']);
        expect(statusCaption(movie(), userState({ status: 'on_hold' }), false)?.color).toBe('var(--color-status-onhold)');
    });

    it('says a container is completed or under way, and says nothing about an untouched title', () => {
        expect(statusCaption(show(), userState({ watched: true }), false)?.parts).toEqual(['Completed', null]);
        expect(statusCaption(movie(), userState({ watched: true }), false)?.parts).toEqual(['Watched', null]);
        expect(statusCaption(show(), userState({ watchedEpisodes: 2 }), false)?.parts).toEqual(['In progress']);
        expect(statusCaption(movie(), userState(), false)).toBeNull();
    });
});

describe('the one action', () => {
    it('takes a status off, drops, or completes — another play once watched', () => {
        expect(actionLabel(movie(), userState(), { ...quiet, intent: 'status' })).toBe('Remove status');
        expect(actionLabel(movie(), userState(), { ...quiet, intent: 'drop' })).toBe('Drop this title');
        expect(actionLabel(movie(), userState(), quiet)).toBe('Mark as watched');
        expect(actionLabel(movie(), userState({ watched: true }), quiet)).toBe('Add a play');
        expect(completeLabel(show(), userState({ watched: true }))).toBe('Mark as watched');
    });

    it('starts, adds to or saves a counter in the session view', () => {
        const session = { ...quiet, intent: 'session' as const };

        expect(actionLabel(game(), userState(), session)).toBe('Start playing');
        expect(actionLabel(game(), userState({ progress: 60 }), session)).toBe('Add session');
        expect(actionLabel(book(), userState({ progress: 12 }), session)).toBe('Save progress');
        expect(actionLabel(game(), userState({ progress: 60 }), { ...session, correcting: true })).toBe('Save');
    });

    it('says how much it takes beside the episode button', () => {
        expect(actionLabel(show(), userState(), { ...quiet, step: 'first' })).toBe('Watch the whole show');
        expect(actionLabel(season(), userState(), { ...quiet, step: 'next' })).toBe('Watch the whole season');
    });
});

describe('what a status write does', () => {
    it('says what dropping does to each kind of title', () => {
        expect(dropDescription(show())).toBe('The show leaves Up Next; your watch history stays intact.');
        expect(dropDescription(season())).toBe('Up Next moves on to the next season; watched episodes stay in your history.');
        expect(dropDescription(mediaSummary({ type: 'episode' }))).toBe('Up Next moves on to the next episode.');
        expect(dropDescription(book())).toBe('The dropped attempt lands in your history with its logged pages.');
        expect(dropDescription(movie())).toBe('The dropped attempt lands in your history as a dated entry.');
    });

    it('says where an inherited status comes from and that the show keeps its own', () => {
        expect(statusDescription(season(), userState({ status: 'dropped', statusInherited: true }))).toEqual([
            '“Season 2” is dropped along with the show. Clearing the status here resumes the show as well.',
        ]);
        expect(statusDescription(movie(), userState({ status: 'on_hold' }))).toEqual([
            '“Title” is on hold. It stays out of Up Next until you resume.',
        ]);
        expect(statusDescription(season(), userState({ status: 'on_hold', rootStatus: 'dropped', watchedEpisodes: 3 }))).toEqual([
            '“Season 2” is on hold. It stays out of Up Next until you resume.',
            'The show itself stays dropped, so the season will then read “Dropped with the show”. Clearing that too resumes the show.',
        ]);
        expect(statusDescription(season(), userState({ status: 'on_hold', rootStatus: 'on_hold' }))).toEqual([
            '“Season 2” is on hold. It stays out of Up Next until you resume.',
            'The show itself stays on hold. Clearing that one is done on the show’s page.',
        ]);
    });
});

describe('the moment to write', () => {
    it('passes now and release through, and a picked wall time as an instant', () => {
        expect(watchedAtChoice('now', '')).toBe('now');
        expect(watchedAtChoice('release', '')).toBe('release');
        expect(watchedAtChoice('custom', '2026-10-10T14:05')).toBe(new Date(2026, 9, 10, 14, 5).toISOString());
    });

    it("reads the picked wall time in the viewer's timezone", () => {
        setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: { timezone: 'Europe/Warsaw' } } } });

        expect(watchedAtChoice('custom', '2026-10-10T20:00')).toBe('2026-10-10T18:00:00.000Z');
    });

    it('refuses a picked date that is not a date', () => {
        expect(watchedAtChoice('custom', '')).toBeNull();
    });
});

describe('the rows under the one action', () => {
    const episode = () => mediaSummary({ type: 'episode' });
    const pointer = { type: 'episode', ref: '1:2:4', title: 'Woe', number: 4, seasonNumber: 2 };

    it.each([
        ['an unwatched movie', movie, {}, 'complete', { history: false, cycles: false }, ['hold', 'drop']],
        ['a watched movie, with its history', movie, { watched: true, plays: 1 }, 'complete', { history: true, cycles: false }, ['hold', 'history']],
        ['a dropped movie', movie, { status: 'dropped' }, 'status', { history: false, cycles: false }, ['complete']],
        ['an untouched show', show, { airedEpisodes: 10 }, 'complete', { history: false, cycles: false }, ['wholeRun', 'hold', 'drop']],
        [
            'a show under way',
            show,
            { watchedEpisodes: 13, airedEpisodes: 19, plays: 13, nextEpisode: pointer },
            'complete',
            { history: false, cycles: false },
            ['wholeRun', 'hold', 'drop', 'unmark'],
        ],
        [
            'a completed show',
            show,
            { watched: true, watchedEpisodes: 62, airedEpisodes: 62, plays: 62 },
            'complete',
            { history: false, cycles: false },
            ['hold', 'drop', 'unmark'],
        ],
        [
            'a show on hold',
            show,
            { status: 'on_hold', watchedEpisodes: 28, nextEpisode: pointer },
            'status',
            { history: false, cycles: false },
            ['nextEpisode', 'drop'],
        ],
        ['a watched episode', episode, { watched: true, plays: 1 }, 'complete', { history: true, cycles: false }, ['history']],
        [
            'a game in progress',
            game,
            { status: 'in_progress', progress: 1140 },
            'session',
            { history: false, cycles: false },
            ['correctTotal', 'complete', 'hold', 'drop', 'removeProgress'],
        ],
        ['an untouched game', game, {}, 'session', { history: false, cycles: false }, ['complete', 'hold', 'drop']],
        [
            'a completed game',
            game,
            { watched: true, plays: 1, playedAmount: 3720 },
            'complete',
            { history: true, cycles: false },
            ['addSession', 'hold', 'history'],
        ],
        [
            'a book being read',
            book,
            { status: 'in_progress', progress: 412 },
            'session',
            { history: false, cycles: false },
            ['complete', 'hold', 'drop', 'removeProgress'],
        ],
    ] as const)('offers %s what can be done to it', (_, media, overrides, intent, has, rows) => {
        expect(statusRows(media(), userState(overrides), intent, has)).toEqual(rows);
    });

    it('offers nothing to unmark in a run just started over, though plays of the one before stand', () => {
        const rows = statusRows(show(), userState({ watchedEpisodes: 0, airedEpisodes: 9, plays: 9, cycle: 2 }), 'complete', {
            history: false,
            cycles: true,
        });

        expect(rows).not.toContain('unmark');
        expect(rows).toContain('cycles');
    });

    it('offers the runs of a show started over, before its history', () => {
        expect(
            statusRows(show(), userState({ watchedEpisodes: 3, airedEpisodes: 9, plays: 12, cycle: 2 }), 'complete', {
                history: false,
                cycles: true,
            }),
        ).toContain('cycles');
    });

    it('offers no rows in a step, only its own action', () => {
        expect(statusRows(movie(), userState(), 'drop', { history: true, cycles: true })).toEqual([]);
    });

    it('says what each row does to this title', () => {
        const history = { count: 2, last: '2026-09-05T20:00:00Z' };

        expect(statusRowText('wholeRun', show(), userState({ watchedEpisodes: 13, remainingEpisodes: 6 }), history).description).toBe(
            'The remaining 6 episodes',
        );
        expect(statusRowText('wholeRun', show(), userState({ airedEpisodes: 10 }), history).description).toBe('All 10 episodes with the same date');
        expect(statusRowText('unmark', show(), userState({ watchedEpisodes: 13 }), history).description).toBe('Removes plays from 13 episodes');
        expect(statusRowText('history', movie(), userState(), history).description).toMatch(/^2 plays, last on /);
        expect(statusRowText('complete', movie(), userState({ status: 'dropped' }), history)).toEqual({
            label: 'Mark as watched',
            description: 'Logged with today’s date',
        });
    });
});

describe('the one action', () => {
    it('reads its kind of write in its colour', () => {
        expect(actionTone('complete', userState())).toBe('watched');
        expect(actionTone('session', userState())).toBe('progress');
        expect(actionTone('drop', userState())).toBe('drop');
        expect(actionTone('status', userState({ status: 'dropped' }))).toBe('drop');
        expect(actionTone('status', userState({ status: 'on_hold' }))).toBe('hold');
    });

    it('names the episode a show is at', () => {
        expect(nextEpisodeLabel(userState({ nextEpisode: { type: 'episode', ref: 'x', title: 'Woe', number: 4, seasonNumber: 2 } }))).toBe(
            'Watch S02E04',
        );
        expect(nextEpisodeLabel(userState())).toBe('Watch the next episode');
    });
});

describe('the facts and the steps under the title', () => {
    it('names the code, the show, the size of an untouched run and the day of a drop', () => {
        expect(statusFacts(show(), userState({ airedEpisodes: 10 }), false)).toEqual(['10 episodes']);
        expect(statusFacts(show(), userState({ airedEpisodes: 10, watchedEpisodes: 3 }), true)).toEqual([]);
        expect(statusFacts(movie(), userState({ status: 'dropped', droppedAt: '2026-11-08T20:00:00Z' }), true)).toEqual(['November 8, 2026']);
    });

    it('names each step in the colour of its write', () => {
        expect(stepName(movie(), { intent: 'drop', correcting: false, cycles: false }).text).toBe('Drop');
        expect(stepName(game(), { intent: 'session', correcting: true, cycles: false }).text).toBe('Correct the total');
        expect(stepName(show(), { intent: 'complete', correcting: false, cycles: true }).text).toBe('Watch cycles');
    });

    it('counts what unmarking empties: the current run, or the plays where no episode is counted', () => {
        expect(unmarkCount(userState({ watchedEpisodes: 0, plays: 9 }))).toBe(0);
        expect(unmarkCount(userState({ watchedEpisodes: null, plays: 4 }))).toBe(4);
    });

    it('says a run started over loses only its own plays', () => {
        expect(statusRowText('unmark', show(), userState({ watchedEpisodes: 3, cycle: 2 }), { count: 0, last: null }).description).toBe(
            'Removes plays from 3 episodes of this cycle',
        );
    });
});
