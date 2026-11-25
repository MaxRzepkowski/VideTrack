import {
    defaultSources,
    flippedValue,
    hasOwnSources,
    isHeldOff,
    nextFollowMode,
    offeredOwnSources,
    optionIsOn,
    optionSettings,
    toggledSource,
    trackedBecause,
    trackingSources,
    typeSummary,
    withOwnSources,
    type DefaultSources,
    type OwnSources,
} from '@/lib/tracking';
import { typePanels, userPreferences } from '@/test/factories';
import type { TrackingSource, TypeOption, TypeOptionSwitch, TypePanel } from '@/types/generated';
import { describe, expect, it } from 'vitest';

/** How many rows the matrix has: the summary counts own sources out of it. */
const TOTAL = trackingSources().length;

describe('trackedBecause', () => {
    it('names the row a title is tracked through, and stays quiet for bells and untouched titles', () => {
        expect(trackedBecause('on_hold')).toBe('Following (on hold)');
        expect(trackedBecause('dropped')).toBe('Following (dropped)');
        expect(trackedBecause('backlog')).toBe('Following (backlog)');
        expect(trackedBecause('activity')).toBe('Following (watching)');
        expect(trackedBecause('followed')).toBeNull();
        expect(trackedBecause('creator')).toBeNull();
        expect(trackedBecause(null)).toBeNull();
    });
});

describe('nextFollowMode', () => {
    it.each([
        ['nothing tracks the title', { follow: null, followSource: null, followVia: null }, 'follow'],
        ['the user rang it', { follow: 'follow', followSource: 'followed', followVia: null }, null],
        ['a creator rang it', { follow: 'follow', followSource: 'creator', followVia: 'Keanu Reeves' }, 'mute'],
        ['the matrix tracks it', { follow: null, followSource: 'activity', followVia: null }, 'mute'],
        ['a status tracks it with no bell', { follow: null, followSource: 'dropped', followVia: null }, 'mute'],
        ['the backlog outranks a creator', { follow: 'follow', followSource: 'backlog', followVia: 'Keanu Reeves' }, 'mute'],
        ['it is muted', { follow: 'mute', followSource: null, followVia: null }, null],
    ] as const)('when %s, one click moves it on', (_, state, next) => {
        expect(nextFollowMode(state)).toBe(next);
    });
});

describe('trackingSources', () => {
    it('gives every row of the matrix its own switch on each surface', () => {
        const sources = trackingSources();

        expect(sources.map((source) => source.key)).toEqual(['activity', 'backlog', 'on_hold', 'dropped', 'followed', 'creator']);
        for (const source of sources) {
            expect(Object.keys(source.preferences)).toEqual(['calendar', 'countdowns', 'notifications']);
        }
        expect(new Set(sources.flatMap((source) => Object.values(source.preferences))).size).toBe(sources.length * 3);
    });
});

const panel = (type: string): TypePanel => {
    const found = typePanels().find((candidate) => candidate.type === type);

    if (found === undefined) {
        throw new Error(`No panel for ${type}`);
    }

    return found;
};

const option = (type: string, key: string): TypeOption => {
    const found = panel(type).options.find((candidate) => candidate.key === key);

    if (found === undefined) {
        throw new Error(`No option ${key} for ${type}`);
    }

    return found;
};

const calendarSwitch = (of: TypeOption): TypeOptionSwitch => {
    const found = of.switches.find((candidate) => candidate.surface === 'calendar');

    if (found === undefined) {
        throw new Error(`No calendar switch for ${of.key}`);
    }

    return found;
};

const reader =
    (overrides: Record<string, unknown> = {}) =>
    (preference: string): unknown =>
        ({ ...userPreferences(), ...overrides })[preference];

const defaults = (): DefaultSources => defaultSources(userPreferences());

describe('type options', () => {
    it('reads a flag as itself', () => {
        expect(optionIsOn(option('game', 'platforms'), true)).toBe(true);
        expect(optionIsOn(option('game', 'platforms'), false)).toBe(false);
        expect(optionIsOn(option('game', 'platforms'), 'true')).toBe(false);
        expect(optionIsOn(option('game', 'platforms'), undefined)).toBe(false);
    });

    it.each([
        ['both', true, true],
        ['cinema', true, false],
        ['digital', false, true],
        ['none', false, false],
        ['someday', false, false],
        [undefined, false, false],
    ])("reads a film's release mode %s as premiere %s and home release %s", (mode, premiere, home) => {
        expect(optionIsOn(option('movie', 'premiere'), mode)).toBe(premiere);
        expect(optionIsOn(option('movie', 'home'), mode)).toBe(home);
    });

    it.each([
        ['both', 'premiere', 'digital'],
        ['both', 'home', 'cinema'],
        ['cinema', 'premiere', 'none'],
        ['cinema', 'home', 'both'],
        ['digital', 'premiere', 'both'],
        ['digital', 'home', 'none'],
        ['none', 'premiere', 'cinema'],
        ['none', 'home', 'digital'],
    ])("flips a film's %s mode on the %s's switch to %s, either date or neither", (mode, key, next) => {
        const target = option('movie', key);

        expect(flippedValue(panel('movie').options, target, calendarSwitch(target), reader({ calendar_release_mode: mode }))).toBe(next);
    });

    it("flips a film's date on one surface by that surface's mode alone", () => {
        const premiere = option('movie', 'premiere');
        const onCountdowns = premiere.switches.find((at) => at.surface === 'countdowns');
        const read = reader({ calendar_release_mode: 'none', countdown_release_mode: 'both' });

        expect(onCountdowns?.preference).toBe('countdown_release_mode');
        expect(onCountdowns && flippedValue(panel('movie').options, premiere, onCountdowns, read)).toBe('digital');
    });

    it('flips a flag either way', () => {
        const platforms = option('game', 'platforms');

        expect(flippedValue(panel('game').options, platforms, calendarSwitch(platforms), reader({ calendar_game_platforms: true }))).toBe(false);
        expect(flippedValue(panel('game').options, platforms, calendarSwitch(platforms), reader({ calendar_game_platforms: false }))).toBe(true);
    });

    it('holds the value of every preference a kind of title switches', () => {
        expect(optionSettings(typePanels(), { ...userPreferences({ countdown_release_mode: 'cinema', notify_episodes: false }) })).toEqual({
            notify_episodes: false,
            notify_show_status: true,
            calendar_release_mode: 'both',
            countdown_release_mode: 'cinema',
            notifications_release_mode: 'both',
            calendar_game_platforms: true,
            countdown_game_platforms: false,
            calendar_game_addons: true,
            countdown_game_addons: true,
            notifications_game_addons: true,
            calendar_game_remakes: true,
            countdown_game_remakes: true,
            notifications_game_remakes: true,
            calendar_game_remasters: true,
            countdown_game_remasters: true,
            notifications_game_remasters: true,
            calendar_events: true,
            countdown_events: true,
            notify_related_announced: false,
        });
    });
});

describe('own sources', () => {
    it('reads the defaults each surface switches on', () => {
        expect(defaults()).toEqual({
            calendar: ['activity', 'backlog', 'on_hold', 'dropped', 'followed', 'creator'],
            countdowns: ['activity', 'backlog', 'on_hold', 'followed', 'creator'],
            notifications: ['activity', 'backlog', 'followed', 'creator'],
        });
        expect(defaultSources(userPreferences({ calendar_watchlist: false, notifications_on_hold: true })).calendar).not.toContain('backlog');
        expect(defaultSources(userPreferences({ calendar_watchlist: false, notifications_on_hold: true })).notifications).toContain('on_hold');
    });

    it('copies the defaults as they stand when switched on, the views together', () => {
        const base = defaults();
        const views = withOwnSources(undefined, 'views', true, base);

        expect(views).toEqual({ calendar: base.calendar, countdowns: base.countdowns });
        expect(hasOwnSources(views, 'views')).toBe(true);
        expect(hasOwnSources(views, 'notifications')).toBe(false);

        // A copy: what happens to the defaults later leaves it be.
        const copied = base.calendar.length;
        base.calendar.pop();
        expect(views.calendar).toHaveLength(copied);
    });

    it("switches one tab's own sources without touching the other's", () => {
        const both = withOwnSources(withOwnSources(undefined, 'views', true, defaults()), 'notifications', true, defaults());

        expect(Object.keys(both)).toEqual(['calendar', 'countdowns', 'notifications']);
        expect(withOwnSources(both, 'views', false, defaults())).toEqual({ notifications: defaults().notifications });
        expect(withOwnSources(both, 'notifications', false, defaults())).toEqual({
            calendar: defaults().calendar,
            countdowns: defaults().countdowns,
        });
        expect(withOwnSources({ notifications: [] }, 'notifications', false, defaults())).toEqual({});
    });

    it('takes the views as having their own only when both of them do', () => {
        expect(hasOwnSources(undefined, 'views')).toBe(false);
        expect(hasOwnSources({ calendar: [] }, 'views')).toBe(false);
        expect(hasOwnSources({ calendar: [], countdowns: [] }, 'views')).toBe(true);
        expect(hasOwnSources({ notifications: [] }, 'notifications')).toBe(true);
    });

    it('keeps the rows in the order of the matrix', () => {
        expect(toggledSource(['backlog', 'followed'], 'activity')).toEqual(['activity', 'backlog', 'followed']);
        expect(toggledSource(['activity', 'backlog'], 'activity')).toEqual(['backlog']);
        expect(toggledSource([], 'creator')).toEqual(['creator']);
        expect(toggledSource(['creator'], 'creator')).toEqual([]);
    });
});

describe('typeSummary', () => {
    const summary = (type: string, tab: 'views' | 'notifications', settings: Record<string, unknown> = {}, own?: OwnSources) =>
        typeSummary(panel(type), tab, reader(settings), own, defaultSources(userPreferences(settings)));

    it.each([
        ['movie', {}, 'same as default; cinema premiere and home release'],
        ['movie', { countdown_release_mode: 'digital' }, 'same as default; cinema premiere in the calendar and home release'],
        ['movie', { calendar_release_mode: 'cinema', countdown_release_mode: 'cinema' }, 'same as default; cinema premiere'],
        ['movie', { calendar_release_mode: 'none' }, 'same as default; cinema premiere in the countdowns and home release in the countdowns'],
        ['movie', { calendar_release_mode: 'none', countdown_release_mode: 'none' }, 'same as default; off: cinema premiere and home release'],
        ['game', {}, 'same as default; every platform on its own in the calendar, DLC and expansions, remakes, remasters, and events'],
        [
            'game',
            { countdown_game_platforms: true, calendar_events: false },
            'same as default; every platform on its own, DLC and expansions, remakes, remasters, and events in the countdowns',
        ],
        [
            'game',
            { calendar_game_platforms: false, calendar_events: false, countdown_events: false },
            'same as default; DLC and expansions, remakes, and remasters',
        ],
        [
            'game',
            {
                calendar_game_platforms: false,
                calendar_events: false,
                countdown_events: false,
                calendar_game_addons: false,
                countdown_game_addons: false,
                calendar_game_remakes: false,
                countdown_game_remakes: false,
                calendar_game_remasters: false,
                countdown_game_remasters: false,
            },
            'same as default; off: every platform on its own, DLC and expansions, remakes, remasters, and events',
        ],
        [
            'game',
            { countdown_game_remakes: false },
            'same as default; every platform on its own in the calendar, DLC and expansions, remakes in the calendar, remasters, and events',
        ],
        ['show', {}, 'same as default'],
        ['book', {}, 'same as default'],
    ])('in the views, %s with %o reads "%s"', (type, settings, words) => {
        expect(summary(type, 'views', settings)).toBe(words);
    });

    it.each([
        ['show', {}, 'same as default; new episodes and show status'],
        ['show', { notify_episodes: false }, 'same as default; show status'],
        ['show', { notify_episodes: false, notify_show_status: false }, 'same as default; off: new episodes and show status'],
        ['movie', { notifications_release_mode: 'digital' }, 'same as default; home release'],
        ['movie', { notifications_release_mode: 'none' }, 'same as default; off: cinema premiere and home release'],
        // Premieres ping only while the defaults let them: held off, the premiere is not said.
        ['movie', { notify_premieres: false }, 'same as default; home release'],
        ['movie', { notify_premieres: false, notifications_release_mode: 'cinema' }, 'same as default; off: cinema premiere and home release'],
        ['game', { calendar_game_platforms: false }, 'same as default; DLC and expansions, remakes, and remasters'],
        [
            'game',
            { notifications_game_remakes: false, notify_related_announced: true },
            'same as default; DLC and expansions, remasters, and announcements of DLC, remakes and remasters',
        ],
        ['book', {}, 'same as default'],
    ])('in the notifications, %s with %o reads "%s"', (type, settings, words) => {
        expect(summary(type, 'notifications', settings)).toBe(words);
    });

    const views = (calendar: TrackingSource[], countdowns: TrackingSource[]): OwnSources => ({ calendar, countdowns });

    it.each([
        [
            'copied from the defaults',
            () => views(defaults().calendar, defaults().countdowns),
            `own sources: calendar 6 of ${TOTAL}, countdowns 5 of ${TOTAL}`,
        ],
        ['one row on each', () => views(['backlog'], ['backlog']), `own sources: calendar 1 of ${TOTAL}, countdowns 1 of ${TOTAL}`],
        ['none on the calendar', () => views([], ['backlog']), `own sources: calendar 0 of ${TOTAL}, countdowns 1 of ${TOTAL}`],
        ['none at all', () => views([], []), 'own sources: none'],
    ])('in the views, own sources %s read "%s"', (_, own, words) => {
        expect(summary('book', 'views', {}, own())).toBe(words);
    });

    it('reads own sources of the other tab as the defaults', () => {
        expect(summary('book', 'views', {}, { notifications: [] })).toBe('same as default');
        expect(summary('book', 'notifications', {}, views([], []))).toBe('same as default');
        expect(summary('book', 'views', {}, { calendar: [] })).toBe('same as default');
    });

    it('says the own sources first, then the options, and no options when it takes no titles', () => {
        expect(summary('show', 'notifications', { notify_show_status: false }, { notifications: ['backlog'] })).toBe(
            `own sources: 1 of ${TOTAL}; new episodes`,
        );
        expect(summary('movie', 'views', { calendar_release_mode: 'cinema', countdown_release_mode: 'cinema' }, views(['backlog'], []))).toBe(
            `own sources: calendar 1 of ${TOTAL}, countdowns 0 of ${TOTAL}; cinema premiere`,
        );
        expect(summary('movie', 'views', {}, views([], []))).toBe('own sources: none');
        expect(summary('game', 'notifications', {}, { notifications: [] })).toBe('own sources: none');
    });

    it('names an option only where the type takes titles, and leaves it out where none take it', () => {
        expect(summary('movie', 'views', { countdown_release_mode: 'none' }, views([], ['backlog']))).toBe(
            `own sources: calendar 0 of ${TOTAL}, countdowns 1 of ${TOTAL}; off: cinema premiere and home release`,
        );
        expect(summary('movie', 'views', { calendar_release_mode: 'none' }, views([], ['backlog']))).toBe(
            `own sources: calendar 0 of ${TOTAL}, countdowns 1 of ${TOTAL}; cinema premiere and home release`,
        );
        expect(summary('game', 'views', {}, views(['backlog'], []))).toBe(
            `own sources: calendar 1 of ${TOTAL}, countdowns 0 of ${TOTAL}; every platform on its own, DLC and expansions, remakes, remasters, and events`,
        );
        expect(summary('game', 'views', { calendar_events: false }, views(['backlog'], []))).toBe(
            `own sources: calendar 1 of ${TOTAL}, countdowns 0 of ${TOTAL}; every platform on its own, DLC and expansions, remakes, remasters, and events in the countdowns`,
        );
    });

    it("keeps the medium's events in the line when the type takes no titles: they do not hang on its titles", () => {
        expect(summary('game', 'views', {}, views([], []))).toBe('own sources: none; events');
        expect(summary('game', 'views', { calendar_events: false }, views([], []))).toBe('own sources: none; events in the countdowns');
        expect(summary('game', 'views', { calendar_events: false, countdown_events: false }, views([], []))).toBe('own sources: none; off: events');
    });

    const noDefaults = {
        calendar_active: false,
        calendar_watchlist: false,
        calendar_on_hold: false,
        calendar_dropped: false,
        calendar_followed: false,
        calendar_creators: false,
        countdown_active: false,
        countdown_watchlist: false,
        countdown_on_hold: false,
        countdown_dropped: false,
        countdown_followed: false,
        countdown_creators: false,
        notifications_active: false,
        notifications_watchlist: false,
        notifications_on_hold: false,
        notifications_dropped: false,
        notifications_followed: false,
        notifications_creators: false,
    };

    it('says a type following defaults that take no titles takes none, and its own sources then still count', () => {
        expect(summary('movie', 'views', noDefaults)).toBe('same as default: no titles');
        expect(summary('movie', 'notifications', noDefaults)).toBe('same as default: no titles');
        expect(summary('game', 'views', noDefaults)).toBe('same as default: no titles; events');
        expect(summary('book', 'views', noDefaults, views([], []))).toBe('own sources: none');
        expect(summary('movie', 'views', noDefaults, views(['backlog'], []))).toBe(
            `own sources: calendar 1 of ${TOTAL}, countdowns 0 of ${TOTAL}; cinema premiere and home release`,
        );
        // Defaults that take titles on one view only still take titles.
        expect(summary('movie', 'views', { ...noDefaults, countdown_watchlist: true })).toBe('same as default; cinema premiere and home release');
    });
});

describe('offeredOwnSources', () => {
    it('keeps the own sources of the kinds of title the page offers, and only those', () => {
        expect(
            offeredOwnSources(
                typePanels().filter((candidate) => candidate.type !== 'comic'),
                { movie: { notifications: [] }, comic: { notifications: ['backlog'] }, boardgame: { notifications: [] } },
            ),
        ).toEqual({ movie: { notifications: [] } });
        expect(offeredOwnSources(typePanels(), {})).toEqual({});
    });
});

describe('isHeldOff', () => {
    it('holds a switch off while the default it needs is off', () => {
        const notifications = option('movie', 'premiere').switches.find((at) => at.surface === 'notifications');

        expect(notifications && isHeldOff(notifications, reader({ notify_premieres: false }))).toBe(true);
        expect(notifications && isHeldOff(notifications, reader())).toBe(false);
        expect(isHeldOff(calendarSwitch(option('movie', 'premiere')), reader({ notify_premieres: false }))).toBe(false);
    });
});
