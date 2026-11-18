import { pageLocale } from '@/lib/pageContext';
import type { MediaSummary, ProgressUnit } from '@/types/media';
import { BookImage, BookOpen, BookOpenText, Building2, Clapperboard, Gamepad2, Tv, type LucideIcon } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';

/**
 * One source of truth per media type: label, glyph and colour. A new type is
 * one entry here, not a parallel switch per surface — badges, dashboard tiles
 * and anything else that names a type read the same vocabulary, so a show is
 * the same glyph in the same violet wherever it appears.
 *
 * `color` is the CSS variable, not a literal: the palette lives in `app.css`
 * (`--color-media-*`) and copying the hex out is how the two drift apart.
 * Labels are thunks so `trans()` stays a literal call (i18n extraction) yet
 * re-runs when the locale changes.
 */
export interface MediaTypeMeta {
    label: () => string;
    /** The label counting a group of them ("3 movies" in a breakdown). */
    plural: () => string;
    /** How many of them, spelled out: "3 movies", "1 episode". */
    count: (count: number) => string;
    /** The heading over how many of them a collection holds ("Number of games"); absent for parts of a series. */
    countHeading?: () => string;
    icon: LucideIcon;
    /** Tailwind utility for a solid fill of the type colour (badges). */
    background: string;
    /** `var(--color-media-*)` — bind it, don't inline the hex. */
    color: string;
    /** What a release of a title of this type is called on a release card; absent for parts of a series. */
    release?: () => string;
    /** What a company making titles of this type is called on its card; absent where no company page exists. */
    maker?: { label: () => string; icon: LucideIcon };
    /** A line on what the type holds, under its name in the Discover menu; absent for parts of a series. */
    description?: () => string;
    /** The statistics page's length card, for a type watched whole: its title and what its two figures are. */
    length?: { title: () => string; longest: () => string; average: () => string };
}

export const MEDIA_TYPE_META: Record<string, MediaTypeMeta | undefined> = {
    movie: {
        label: () => trans('media.movie'),
        plural: () => trans('common.movies'),
        count: (count) => transChoice('common.count_movie', count),
        countHeading: () => trans('lists.number_of_movies'),
        icon: Clapperboard,
        background: 'bg-media-movie',
        color: 'var(--color-media-movie)',
        release: () => trans('calendar.movie_release'),
        maker: { label: () => trans('media.studio'), icon: Building2 },
        description: () => trans('app.films_cinema'),
        length: {
            title: () => trans('statistics.movie_length'),
            longest: () => trans('statistics.longest_movie'),
            average: () => trans('statistics.average_movie_length'),
        },
    },
    show: {
        label: () => trans('media.tv'),
        plural: () => trans('common.shows'),
        count: (count) => transChoice('dashboard.count_show', count),
        countHeading: () => trans('lists.number_of_shows'),
        icon: Tv,
        background: 'bg-media-tv',
        color: 'var(--color-media-tv)',
        description: () => trans('app.tv_series_episodes'),
    },
    season: {
        label: () => trans('media.season'),
        plural: () => trans('media.seasons'),
        count: (count) => transChoice('media.count_season', count),
        icon: Tv,
        background: 'bg-media-tv',
        color: 'var(--color-media-tv)',
    },
    episode: {
        label: () => trans('media.episode'),
        plural: () => trans('media.episodes'),
        count: (count) => transChoice('common.count_episode', count),
        icon: Tv,
        background: 'bg-media-tv',
        color: 'var(--color-media-tv)',
    },
    game: {
        label: () => trans('media.game'),
        plural: () => trans('common.games'),
        count: (count) => transChoice('dashboard.count_game', count),
        countHeading: () => trans('lists.number_of_games'),
        icon: Gamepad2,
        background: 'bg-media-game',
        color: 'var(--color-media-game)',
        release: () => trans('calendar.game_release'),
        maker: { label: () => trans('media.developer_publisher'), icon: Gamepad2 },
        description: () => trans('app.video_games'),
    },
    book: {
        label: () => trans('media.book'),
        plural: () => trans('common.books'),
        count: (count) => transChoice('dashboard.count_book', count),
        countHeading: () => trans('lists.number_of_books'),
        icon: BookOpen,
        background: 'bg-media-book',
        color: 'var(--color-media-book)',
        release: () => trans('calendar.book_release'),
        description: () => trans('app.novels_non_fiction'),
    },
    manga: {
        label: () => trans('common.manga'),
        plural: () => trans('common.manga'),
        count: (count) => transChoice('dashboard.count_manga', count),
        countHeading: () => trans('lists.number_of_manga'),
        icon: BookOpenText,
        background: 'bg-media-manga',
        color: 'var(--color-media-manga)',
        release: () => trans('calendar.manga_release'),
        description: () => trans('app.japanese_comics'),
    },
    comic: {
        label: () => trans('media.comic'),
        plural: () => trans('common.comics'),
        count: (count) => transChoice('dashboard.count_comic', count),
        countHeading: () => trans('lists.number_of_comics'),
        icon: BookImage,
        background: 'bg-media-comic',
        color: 'var(--color-media-comic)',
        release: () => trans('calendar.comic_release'),
        description: () => trans('app.comic_books'),
    },
};

/**
 * What a group of positions of one kind is called. Lists, follows and search
 * also hold people, companies and collections, which are not media types but
 * still need a name beside them.
 */
const OTHER_KINDS: Record<string, () => string> = {
    person: () => trans('common.people'),
    company: () => trans('common.companies'),
    collection: () => trans('common.collections'),
};

export function kindPlural(kind: string): string {
    return (OTHER_KINDS[kind] ?? MEDIA_TYPE_META[kind]?.plural ?? (() => kind))();
}

/**
 * Several kinds named as one group ("Shows and movies"), in the registry's
 * order and with the language's own list conjunction; only the first word
 * keeps its capital.
 */
export function kindsLabel(kinds: string[]): string {
    const locale = pageLocale();
    const names = inRegistryOrder(kinds).map((kind, index) => (index === 0 ? kindPlural(kind) : kindPlural(kind).toLocaleLowerCase(locale)));

    return new Intl.ListFormat(locale, { type: 'conjunction' }).format(names);
}

/** Meta of a media type; unknown types fall back to the show entry. */
export function mediaTypeMeta(type: string): MediaTypeMeta {
    return MEDIA_TYPE_META[type] ?? (MEDIA_TYPE_META.show as MediaTypeMeta);
}

/**
 * What a type can do, as the backend registry defines it — shared with every
 * page and registered once when the app boots (app.ts). Display order is the
 * registry's order.
 */
export interface MediaTypeDefinition {
    code: string;
    parentType: string | null;
    childType: string | null;
    /** A play is logged on it directly (movie, episode, game…), not through children. */
    playable: boolean;
    /** It takes a dropped / on-hold / in-progress status of its own. */
    userStatus: boolean;
    /** The unit its progress counter runs in; null when progress is derived or absent. */
    progressUnit: ProgressUnit | null;
    /** It has a page of its own, `{code}.details`; otherwise a root shares the title page. */
    ownPage: boolean;
    /** Its genres link to a discover listing: its provider browses by genre. */
    genreBrowsable: boolean;
}

let definitions: MediaTypeDefinition[] = [];

/** Called once from app.ts with the shared `mediaTypes` prop. */
export function registerMediaTypes(types: MediaTypeDefinition[]): void {
    definitions = types;
}

/** The definition of a type; a type this instance does not run can do nothing. */
export function mediaTypeDefinition(type: string): MediaTypeDefinition {
    return (
        definitions.find((definition) => definition.code === type) ?? {
            code: type,
            parentType: null,
            childType: null,
            playable: false,
            userStatus: false,
            progressUnit: null,
            ownPage: false,
            genreBrowsable: false,
        }
    );
}

/** Whether this instance runs the type. */
export function hasMediaType(type: string): boolean {
    return definitions.some((definition) => definition.code === type);
}

/** The types a title is stored under as a whole (a film, a show; not a season), in the registry's order. */
export function rootMediaTypes(): string[] {
    return definitions.filter((definition) => definition.parentType === null).map((definition) => definition.code);
}

/** The given type codes in the registry's order; codes it does not know keep their order at the end. */
export function inRegistryOrder<T extends string>(codes: Iterable<T>): T[] {
    const present = [...codes];
    const order = definitions.map((definition) => definition.code);
    const known = order.filter((code): code is T => present.includes(code as T));

    return [...known, ...present.filter((code) => !order.includes(code))];
}

/**
 * The types a person's credits come in, films before shows whatever the
 * registry's order, as a person is first known by their films. Every other type follows in the registry's order.
 */
const FIRST_ON_A_PERSON_PAGE = ['movie', 'show'];

export function inPersonPageOrder(codes: Iterable<string>): string[] {
    const present = [...codes];
    const first = FIRST_ON_A_PERSON_PAGE.filter((code) => present.includes(code));

    return [...first, ...inRegistryOrder(present.filter((code) => !first.includes(code)))];
}

/**
 * The playable type at the bottom of a type's hierarchy: a show's episodes.
 * A type with no children is its own leaf.
 */
export function leafType(type: string): string {
    let code = type;

    for (let child = mediaTypeDefinition(code).childType; child !== null; child = mediaTypeDefinition(code).childType) {
        code = child;
    }

    return code;
}

/** The unit a type is read in (pages, chapters, issues); null for what is watched or played, counted in time. */
export function readUnitOf(type: string): Exclude<ProgressUnit, 'minutes'> | null {
    const unit = mediaTypeDefinition(type).progressUnit;

    return unit === 'minutes' ? null : unit;
}

/** The type whose progress counts in this unit — the medium a counter of pages, chapters or hours belongs to. */
export function typeCountingIn(unit: ProgressUnit): string | null {
    return definitions.find((definition) => definition.progressUnit === unit)?.code ?? null;
}

/** Types carrying a manual viewing status (dropped / on hold / playing). */
export function hasUserStatus(media: MediaSummary): boolean {
    return mediaTypeDefinition(media.type).userStatus;
}
