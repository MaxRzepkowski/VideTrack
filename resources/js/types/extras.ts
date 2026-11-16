// What the `extra` bag of a card or a details page carries, per media type —
// the contract between a type's provider module and the type's own page,
// written once here instead of cast at every read. The bag is built as an
// array in the provider (and kept in a title's stored metadata), so no PHP
// class exists to generate it from. Every key is optional: a title read back
// from the library may predate a key, and a provider may leave it out.
import type {
    AgeRatingData,
    CatalogLink,
    CollectionLink,
    LanguageSupport,
    MangaAuthorRole,
    MangaFormat,
    MediaSummary,
    MultiplayerSupport,
    ProviderRating,
    StoreLink,
} from '@/types/generated';
import type { CreatorLink } from '@/types/media';

/** On any card, whoever built it: the typed fields of a summary and the context a surface adds. */
export type SummaryExtra = MediaSummary['extra'];

/**
 * What the shared title header reads, whichever type's page it heads: the
 * certification chip's facts and the companies behind the title. Every
 * type's extra builds on it.
 */
export interface TitleExtra {
    certificationRegion?: string | null;
    certificationMeaning?: string | null;
    certificationDescriptors?: string[];
    networks?: CreatorLink[];
    studios?: CreatorLink[];
    developerLinks?: CreatorLink[];
    publisherLinks?: CreatorLink[];
    portingLinks?: CreatorLink[] | null;
    supportingLinks?: CreatorLink[] | null;
    /** The title in its own language, only when it differs from the one shown. */
    originalTitle?: string | null;
    /** Other names it goes by (an acronym, another spelling, a title in another language). */
    alternativeTitles?: string[] | null;
}

interface TmdbTitleExtra extends TitleExtra {
    imdbId?: string | null;
    wikidataId?: string | null;
    logoPath?: string | null;
    spokenLanguages?: string[];
}

export interface MovieExtra extends TmdbTitleExtra {
    budget?: number | null;
    revenue?: number | null;
    productionCountries?: string[];
    collection?: CollectionLink | null;
    homeReleaseNote?: string | null;
}

export interface ShowExtra extends TmdbTitleExtra {
    /** The people credited as the show's creators. */
    createdBy?: CreatorLink[];
    seasonCount?: number | null;
    episodeCount?: number | null;
    tvdbId?: number | null;
    originCountry?: string[];
    /** TMDB's own shape of the production (Scripted, Miniseries, Reality…). */
    showType?: string | null;
    /** Episodes that also played in cinemas. */
    screenedTheatrically?: { seasonNumber: number; episodeNumber: number }[];
    lastEpisodeToAir?: MediaSummary | null;
    nextEpisodeToAir?: MediaSummary | null;
}

/** The season beside this one, for the side arrows; specials left out. */
export interface SeasonNeighbour {
    ref: string;
    number: number;
    title: string;
}

export interface SeasonExtra extends TitleExtra {
    showRef?: string;
    showTitle?: string;
    episodeCount?: number;
    prevSeason?: SeasonNeighbour | null;
    nextSeason?: SeasonNeighbour | null;
}

/** The episode beside this one, across a season boundary too. */
export interface EpisodeNeighbour {
    ref: string;
    seasonNumber: number;
    episodeNumber: number;
    title: string;
}

export interface EpisodeExtra extends TitleExtra {
    showRef?: string;
    showTitle?: string;
    seasonNumber?: number;
    seasonTitle?: string | null;
    /** The hero backdrop: episodes and seasons have none of their own. */
    showBackdrop?: string | null;
    /** Whether this episode also played in cinemas. */
    screenedTheatrically?: boolean;
    prev?: EpisodeNeighbour | null;
    next?: EpisodeNeighbour | null;
}

export interface GameExtra extends TitleExtra {
    /** The storyline, where the game has a summary beside it (alone, it stands as the overview). */
    storyline?: string | null;
    platforms?: string[];
    gameModes?: string[];
    perspectives?: string[] | null;
    languages?: LanguageSupport[] | null;
    multiplayer?: MultiplayerSupport[] | null;
    /** Critics' and players' scores apart; `communityRating` is the two together. */
    criticsRating?: ProviderRating | null;
    playersRating?: ProviderRating | null;
    /** Where it is sold or played by subscription, each store's own page. */
    stores?: StoreLink[] | null;
    /** Every board's rating, with the content behind it; `certification` is the viewer's board's. */
    ageRatings?: AgeRatingData[] | null;
    engines?: string[];
    franchises?: string[];
    /** The franchises it belongs to, each opening the franchise's page. */
    franchiseLinks?: CatalogLink[] | null;
    /** The platforms it is out on, each opening the platform's page. */
    platformLinks?: CatalogLink[] | null;
    /** Minutes to beat it, at three paces. */
    timeToBeat?: { hastily?: number | null; normally?: number | null; completely?: number | null } | null;
    dlcs?: MediaSummary[] | null;
    otherReleases?: MediaSummary[] | null;
    /** The game's editions (Game of the Year, Deluxe), each a game of its own. */
    editions?: MediaSummary[] | null;
    /** What a bundle holds: its games and expansions. */
    bundleContents?: MediaSummary[] | null;
    parentGameRef?: string | null;
    parentGameTitle?: string | null;
}

export interface BookExtra extends TitleExtra {
    authors?: string[];
    authorLinks?: CreatorLink[];
    pages?: number | null;
    series?: { name: string; position: number | null } | null;
    contentWarnings?: string[] | null;
    ratingsCount?: number | null;
    readersCount?: number | null;
    /** True for non-fiction, false for fiction; null where Hardcover does not say. */
    nonfiction?: boolean | null;
    /** The ISBN-13 of the edition the book's page shows. */
    isbn?: string | null;
    /** The book's Goodreads id, which the Goodreads export keys it by. */
    goodreadsId?: string | null;
    /** The edition in the reader's language the page names the book by; null in the book's own. */
    localEdition?: { publisher: string | null; year: number | null } | null;
}

export interface MangaExtra extends TitleExtra {
    authors?: string[];
    chapters?: number | null;
    volumes?: number | null;
    mangaFormat?: MangaFormat | null;
    /** The magazine it was serialized in. */
    magazine?: string | null;
    ratingsCount?: number | null;
    readersCount?: number | null;
    /** What each author did, by name, for the authors MAL says it of. */
    authorRoles?: Record<string, MangaAuthorRole[]> | null;
    endDate?: string | null;
    /** Whether more is coming: the years then read "since", not a bare start year. */
    stillPublishing?: boolean;
    background?: string | null;
}

export interface ComicExtra extends TitleExtra {
    creators?: string[];
    publisher?: string | null;
    issues?: number | null;
}
