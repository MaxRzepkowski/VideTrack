import { pageLocale } from '@/lib/pageContext';
import type { UserPreferences } from '@/types';
import type { FollowMode, Surface, TypeOption, TypeOptionSwitch, TypePanel, UserMediaState } from '@/types/generated';
import type { TrackingSource } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

/**
 * What the bell says about a title the tracking matrix covers on its own —
 * the row the server put it in. Null for a title only a bell tracks (the bell
 * then speaks for itself) and for one nothing tracks.
 */
export function trackedBecause(source: TrackingSource | null): string | null {
    switch (source) {
        case 'on_hold':
            return trans('media.following_on_hold');
        case 'dropped':
            return trans('media.following_dropped');
        case 'backlog':
            return trans('media.following_backlog');
        case 'activity':
            return trans('media.following_watching');
        default:
            return null;
    }
}

/**
 * Where one click on a title's bell takes it: off → follow, auto-followed →
 * mute, forced or muted → back to whatever the matrix says. A bell a creator
 * rang cannot be plainly taken back (the creator's sync would ring it again),
 * so its exit is muting.
 */
export function nextFollowMode(state: Pick<UserMediaState, 'follow' | 'followSource' | 'followVia'>): FollowMode | null {
    if (state.follow === 'mute') {
        return null;
    }

    if (trackedBecause(state.followSource) !== null) {
        return 'mute';
    }

    if (state.follow === 'follow') {
        return state.followVia ? 'mute' : null;
    }

    return 'follow';
}

/** The preference switching one row of the matrix on one surface by default. */
type SourcePreference = `${'calendar' | 'countdown' | 'notifications'}_${'active' | 'watchlist' | 'on_hold' | 'dropped' | 'followed' | 'creators'}`;

/**
 * The rows of the tracking matrix as the settings show them, in their order,
 * each with the preference that switches it on each surface by default.
 */
export function trackingSources(): {
    key: TrackingSource;
    label: string;
    description: string;
    preferences: Record<Surface, SourcePreference>;
}[] {
    return [
        {
            key: 'activity',
            // The row holds anything with a play on it. A series is "in the
            // middle", a film is simply seen — one word has to cover both, and
            // the app already calls that place your history.
            label: trans('settings.in_your_history'),
            description: trans('settings.series_and_games_you_are_in'),
            preferences: { calendar: 'calendar_active', countdowns: 'countdown_active', notifications: 'notifications_active' },
        },
        {
            key: 'backlog',
            label: trans('common.backlog'),
            description: trans('settings.titles_you_plan_to_get_to'),
            preferences: { calendar: 'calendar_watchlist', countdowns: 'countdown_watchlist', notifications: 'notifications_watchlist' },
        },
        {
            key: 'on_hold',
            label: trans('settings.on_hold_titles'),
            description: trans('settings.paused_titles_a_fresh_season_is'),
            preferences: { calendar: 'calendar_on_hold', countdowns: 'countdown_on_hold', notifications: 'notifications_on_hold' },
        },
        {
            key: 'dropped',
            label: trans('settings.dropped_titles'),
            description: trans('settings.you_gave_up_on_it_but'),
            preferences: { calendar: 'calendar_dropped', countdowns: 'countdown_dropped', notifications: 'notifications_dropped' },
        },
        {
            key: 'followed',
            label: trans('common.followed_manually'),
            description: trans('settings.titles_you_rang_the_bell_on'),
            preferences: { calendar: 'calendar_followed', countdowns: 'countdown_followed', notifications: 'notifications_followed' },
        },
        {
            key: 'creator',
            label: trans('settings.new_titles_from_followed_creators'),
            description: trans('settings.titles_the_bell_picked_up_from'),
            preferences: { calendar: 'calendar_creators', countdowns: 'countdown_creators', notifications: 'notifications_creators' },
        },
    ];
}

/** The tabs of the tracking settings, in their order; `?tab=` opens one from a link. */
export const TRACKING_TABS = ['views', 'notifications', 'creators'] as const;

export type TrackingTab = (typeof TRACKING_TABS)[number];

/** The tabs a kind of title has a panel in, and the surfaces each one switches. */
export type TypeTab = Extract<TrackingTab, 'views' | 'notifications'>;

export const TAB_SURFACES: Record<TypeTab, readonly Surface[]> = {
    views: ['calendar', 'countdowns'],
    notifications: ['notifications'],
};

/** A setting the page holds, read by its name. */
export type SettingReader = (preference: string) => unknown;

export function surfaceLabel(surface: Surface): string {
    switch (surface) {
        case 'calendar':
            return trans('common.calendar');
        case 'countdowns':
            return trans('common.countdowns');
        case 'notifications':
            return trans('common.notifications');
    }
}

/** The option's switches on the surfaces of one tab. */
export function switchesIn(option: TypeOption, tab: TypeTab): TypeOptionSwitch[] {
    return option.switches.filter((candidate) => TAB_SURFACES[tab].includes(candidate.surface));
}

/** Whether an option is on, given the value of the preference its switch turns. */
export function optionIsOn(option: TypeOption, value: unknown): boolean {
    return option.onWhen === null ? value === true : typeof value === 'string' && option.onWhen.includes(value);
}

/**
 * The value a switch's preference takes when the switch is flipped. A flag
 * simply follows; a preference several options share (a film's two dates)
 * takes the value standing for the new combination, and null means none does
 * — the switch cannot be flipped.
 */
export function flippedValue(options: readonly TypeOption[], target: TypeOption, at: TypeOptionSwitch, read: SettingReader): boolean | string | null {
    const current = read(at.preference);

    if (target.onWhen === null) {
        return current !== true;
    }

    const sharing = options.filter((option) => option.onWhen !== null && option.switches.some((other) => other.preference === at.preference));
    const wanted = (option: TypeOption): boolean => (option === target ? !optionIsOn(option, current) : optionIsOn(option, current));
    const values = target.choices ?? [];

    return values.find((value) => sharing.every((option) => optionIsOn(option, value) === wanted(option))) ?? null;
}

/** A kind of title's own sources: surface => the rows switched on there; a surface left out follows the defaults. */
export type OwnSources = NonNullable<UserPreferences['type_sources'][string]>;

/** The rows switched on by default on each surface. */
export type DefaultSources = Record<Surface, TrackingSource[]>;

/** Whether a kind of title has sources of its own in a tab (the views share one switch). */
export function hasOwnSources(own: OwnSources | undefined, tab: TypeTab): boolean {
    return TAB_SURFACES[tab].every((surface) => own?.[surface] !== undefined);
}

/**
 * A kind of title's sources with the tab's own switched on or off. Switching
 * on copies the defaults as they stand: from then on they are its own, and a
 * later change to the defaults leaves them be.
 */
export function withOwnSources(own: OwnSources | undefined, tab: TypeTab, on: boolean, defaults: DefaultSources): OwnSources {
    const next: OwnSources = { ...own };

    for (const surface of TAB_SURFACES[tab]) {
        if (on) {
            next[surface] = [...defaults[surface]];
        } else {
            delete next[surface];
        }
    }

    return next;
}

/** The rows with one switched, in the matrix's order. */
export function toggledSource(rows: readonly TrackingSource[], row: TrackingSource): TrackingSource[] {
    const next = rows.includes(row) ? rows.filter((candidate) => candidate !== row) : [...rows, row];

    return trackingSources()
        .map((source) => source.key)
        .filter((key) => next.includes(key));
}

/** The rows a kind of title takes on a surface: its own when it has them in the tab, else the defaults. */
export function sourcesTaken(own: OwnSources | undefined, tab: TypeTab, defaults: DefaultSources, surface: Surface): TrackingSource[] {
    return own !== undefined && hasOwnSources(own, tab) ? (own[surface] ?? []) : defaults[surface];
}

/** Whether a switch is held off by a default setting it needs (a film's premiere ping while premieres ping at all). */
export function isHeldOff(at: TypeOptionSwitch, read: SettingReader): boolean {
    return at.requires !== null && read(at.requires.preference) !== true;
}

/**
 * What a kind of title does in a tab, in plain words, on one line: how many
 * of the sources it takes when it has its own, then which of its own options
 * are on — where only on some of the tab's surfaces, which. A type that takes
 * no titles there, by its own sources or the defaults, says so, and keeps only
 * the options that do not hang on its titles (its medium's events).
 */
export function typeSummary(panel: TypePanel, tab: TypeTab, read: SettingReader, own: OwnSources | undefined, defaults: DefaultSources): string {
    const total = String(trackingSources().length);
    const ownOn = own !== undefined && hasOwnSources(own, tab);
    const takes = (surface: Surface): number => sourcesTaken(own, tab, defaults, surface).length;
    const takesNothing = TAB_SURFACES[tab].every((surface) => takes(surface) === 0);

    let sources = trans(takesNothing ? 'settings.summary_default_nothing' : 'settings.summary_as_default');

    if (ownOn) {
        sources = takesNothing
            ? trans('settings.summary_own_nothing')
            : tab === 'views'
              ? trans('settings.summary_own_views', { calendar: String(takes('calendar')), countdowns: String(takes('countdowns')), total })
              : trans('settings.summary_own_notifications', { count: String(takes('notifications')), total });
    }

    // An option showing something of the titles means nothing where the type takes none.
    const meaningful = (option: TypeOption): TypeOptionSwitch[] =>
        switchesIn(option, tab).filter((at) => !option.ofTrackedTitles || takes(at.surface) > 0);
    const options = optionsInWords(
        panel.options.map((option) => ({ option, switches: meaningful(option) })).filter(({ switches }) => switches.length > 0),
        read,
    );

    return options === null ? sources : trans('settings.summary_with_options', { sources, options });
}

/** Options, each by the switches that mean something: those on (and where, when not on all of them), or those all off; null when there are none. */
function optionsInWords(options: { option: TypeOption; switches: TypeOptionSwitch[] }[], read: SettingReader): string | null {
    if (options.length === 0) {
        return null;
    }

    const on = options.flatMap(({ option, switches }) => {
        const where = switches.filter((at) => optionIsOn(option, read(at.preference)) && !isHeldOff(at, read));

        if (where.length === 0) {
            return [];
        }

        return where.length === switches.length
            ? [trans(option.phrase)]
            : [trans('settings.summary_option_where', { option: trans(option.phrase), where: list(where.map((at) => surfacePhrase(at.surface))) })];
    });

    return on.length > 0 ? list(on) : trans('settings.summary_options_off', { options: list(options.map(({ option }) => trans(option.phrase))) });
}

/** A surface inside a summary line ("in the calendar"). */
function surfacePhrase(surface: Surface): string {
    switch (surface) {
        case 'calendar':
            return trans('settings.summary_in_calendar');
        case 'countdowns':
            return trans('settings.summary_in_countdowns');
        case 'notifications':
            return trans('settings.summary_in_notifications');
    }
}

function list(items: string[]): string {
    return new Intl.ListFormat(pageLocale(), { type: 'conjunction' }).format(items);
}

/**
 * The own sources the page keeps, of the kinds of title it offers: one whose
 * module the instance switched off has no panel, and sending it back would be
 * refused.
 */
export function offeredOwnSources(types: readonly TypePanel[], stored: Readonly<Record<string, OwnSources | undefined>>): Record<string, OwnSources> {
    const offered: Record<string, OwnSources> = {};

    for (const panel of types) {
        const own = stored[panel.type];

        if (own !== undefined) {
            offered[panel.type] = own;
        }
    }

    return offered;
}

/** The rows each surface's defaults switch on, as the page's form holds them. */
export function defaultSources(settings: Readonly<Pick<UserPreferences, SourcePreference>>): DefaultSources {
    const on = (surface: Surface): TrackingSource[] =>
        trackingSources()
            .filter((source) => settings[source.preferences[surface]] === true)
            .map((source) => source.key);

    return { calendar: on('calendar'), countdowns: on('countdowns'), notifications: on('notifications') };
}

/** The value of every preference a kind of title switches, as the page's form holds them. */
export function optionSettings(types: readonly TypePanel[], settings: Readonly<Record<string, unknown>>): Record<string, boolean | string> {
    const values: Record<string, boolean | string> = {};

    for (const at of types.flatMap((panel) => panel.options.flatMap((option) => option.switches))) {
        const value = settings[at.preference];

        if (typeof value === 'boolean' || typeof value === 'string') {
            values[at.preference] = value;
        }
    }

    return values;
}
