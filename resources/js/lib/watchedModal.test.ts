import {
    actionLabel,
    completeLabel,
    dropDescription,
    episodeStep,
    openingIntent,
    statusCaption,
    statusDescription,
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
