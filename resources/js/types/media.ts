// The wire types the pages import, re-exported from types/generated.ts (one
// PHP class each). Written here: what the front builds itself (`@view`), the
// details generic over a type's own `extra` bag, and the few page shapes no
// class describes (`ProgressEntry`, `DiscoverRange`), counted as debt by `sweep`.
import type { AgeRatingImage } from '@/lib/ageRatings';
import type {
    ActivityEntry,
    ActivityFilters,
    CalendarDay,
    CalendarEntry,
    CalendarMonth,
    CertificationData,
    CollectionBannerData,
    CompanyCredit,
    CompanyDetails,
    CompanyRole,
    CountdownEntry,
    CreatorLink,
    CreditData,
    DiscoverFilter,
    DiscoverFilterKind,
    DiscoverFilterOption,
    EpisodeRating,
    EpisodeSegment,
    KeywordData,
    ListDetailsData,
    ListItemEntry,
    ListOverview,
    ListStatisticsData,
    ListUnitTotal,
    MediaDetails as MediaDetailsWire,
    MediaSummary,
    MediaUserStatus,
    PersonCredit,
    PersonDetails,
    PlayEntry,
    PlayingEntry,
    ProfileData,
    ProgressUnit,
    ReleaseEntry,
    SeasonRatings,
    TitleTie,
    TrackingSource,
    UpNextEntry,
    UserList,
    UserMediaState,
    VideoData,
    WatchAvailability,
    WatchProviderData,
    WatchStats,
} from '@/types/generated';

export type {
    ActivityEntry,
    ActivityFilters,
    CalendarDay,
    CalendarEntry,
    CalendarMonth,
    CertificationData,
    CollectionBannerData,
    CompanyCredit,
    CompanyDetails,
    CompanyRole,
    CountdownEntry,
    CreatorLink,
    CreditData,
    DiscoverFilter,
    DiscoverFilterKind,
    DiscoverFilterOption,
    EpisodeRating,
    EpisodeSegment,
    KeywordData,
    ListDetailsData,
    ListItemEntry,
    ListOverview,
    ListStatisticsData,
    ListUnitTotal,
    MediaSummary,
    PersonCredit,
    PersonDetails,
    PlayEntry,
    PlayingEntry,
    ProfileData,
    ProgressUnit,
    ReleaseEntry,
    SeasonRatings,
    TitleTie,
    TrackingSource,
    UpNextEntry,
    UserList,
    VideoData,
    WatchAvailability,
    WatchProviderData,
    WatchStats,
};

/**
 * A title's details page payload; `E` is what its type's provider keeps in
 * `extra` (types/extras.ts), on top of what any card and any details carry.
 */
export type MediaDetails<E extends object = object> = Omit<MediaDetailsWire, 'extra'> & {
    readonly extra: MediaDetailsWire['extra'] & E;
};

/** What a write addresses: a title by its coordinates, nothing more. */
export type MediaPosition = Pick<MediaSummary, 'type' | 'ref'>;

/** A universal instant as `Date.prototype.toISOString()` writes it. */
export type IsoInstant = `${string}T${string}Z`;

/** When a play or a drop happened, as API v1 takes it: now, on the release date, or at an instant. */
export type WatchedAtChoice = 'now' | 'release' | IsoInstant;

/**
 * Discover filters. The provider describes them, the page renders
 * them generically — adding a filter is a backend-only change.
 */

/** A year span; either end may stand alone. */
export interface DiscoverRange {
    from: number | null;
    to: number | null;
}

/** The normalized, server-validated filter state — one entry per set filter. */
export type DiscoverActiveFilters = Record<string, string | string[] | DiscoverRange>;

export type UserStatesMap = Record<string, UserMediaState>;

/** One card of the progress tab: a title under way, with the counters its card captions. */
export interface ProgressEntry {
    id: number | null;
    at: string | null;
    media: MediaSummary;
    watchedEpisodes?: number | null;
    airedEpisodes?: number | null;
    /** The same counters the dashboard cards caption. */
    remainingEpisodes?: number | null;
    position?: number | null;
    /** What the bar's hover hint reads. */
    skippedEpisodes?: number | null;
    remainingMinutes?: number | null;
    watchedMinutes?: number | null;
    /** A show in the middle rides on its Up Next card. */
    upNext?: UpNextEntry | null;
    nextEpisode?: UserMediaState['nextEpisode'];
    /** Games: total session minutes. */
    progress?: number | null;
    /** Which shelf the card belongs to. */
    section?: MediaUserStatus;
}

/** @view One labelled fact in a title's details dialog, as the page words it. */
export interface DetailRow {
    label: string;
    value: string | null;
    /** Names that open a page of their own (a game's franchises), shown instead of `value`. */
    links?: { name: string; href: string }[];
}

/** @view One line of the classification dialog: a country's (a film) or a board's (a game) rating and what it says. */
export interface CertificationRow {
    key: string;
    /** The country or the board. */
    name: string;
    code: string;
    meaning: string | null;
    /** The content behind the rating, where the source names it, with the board's mark for it where one is kept. */
    descriptors: { text: string; image: AgeRatingImage | null }[];
    /** The board's own summary of the content (ESRB). */
    synopsis: string | null;
    /** The board's mark for it, where one is kept; a film's countries have none. */
    image: AgeRatingImage | null;
}

/**
 * A meta chip under a title: plain text, a clickable one that runs an action
 * (the "N seasons" chip), or the certification trigger with its own dialog —
 * fed the film's countries unless the chip brings its own rows (a game's boards).
 */
export type Chip =
    | string
    | { label: string; onClick: () => void }
    | { label: string; certification: true; rows?: CertificationRow[]; image?: AgeRatingImage | null };
