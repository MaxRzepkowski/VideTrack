import type { MediaTypeDefinition } from '@/lib/mediaTypes';
import type {
    CompanyFollowScope,
    DiscordMode,
    EpisodeImage,
    EventEntry,
    IdentityProviderOption,
    PersonFollowScope,
    ReleaseDateMode,
    TimeFormat,
    UpNextSort,
    UpNextTarget,
    UserMediaState,
    WeekStart,
} from '@/types/generated';
import type { UserList } from '@/types/media';
import type { NotificationFeed } from '@/types/notifications';

export type { EpisodeImage, ReleaseDateMode };

/** A list with at least one element, so its first one is no guess. */
export type NonEmpty<T> = [T, ...T[]];

export interface Auth {
    /** Null for a guest — who only ever sees the sign-in pages; every app page is behind the login. */
    user: User | null;
}

/**
 * The props the server shares with every page (HandleInertiaRequests). A type
 * rather than an interface, so it fits Inertia's own page props without an
 * index signature of its own: a prop only some pages carry is declared by the
 * code that reads it, never read as `unknown` and cast.
 */
export type SharedData = {
    auth: Auth;
    registrationEnabled: boolean;
    /** Whether a password signs in here; false where identity providers are the only way in. */
    passwordLogin: boolean;
    /** The identity providers this instance signs in with, in the order the login page offers them. */
    identityProviders: IdentityProviderOption[];
    locale: string;
    imageBase: string;
    /** The running release ("1.4.0"), or a build label such as "dev". */
    version: string;
    /** Where the running code can be read. */
    sourceUrl: string;
    discoverTypes: string[];
    mediaTypes: MediaTypeDefinition[];
    userLists: UserList[];
    /** The state of a title the user never touched; null for guests. */
    untouchedState: UserMediaState | null;
    /** Unread counter + latest items for the header bell; null for guests. */
    notifications: NotificationFeed | null;
    /** The events on the viewer's calendar running now or starting within hours: the "live" button's own clock reads them. */
    liveEvents: EventEntry[];
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
};

export interface User {
    id: number;
    name: string;
    username: string;
    email: string;
    bio: string | null;
    avatar: string | null;
    is_private: boolean;
    locale: string | null;
    preferences: UserPreferences;
    /** The password is a way in, so it confirms what cannot be undone; otherwise the username does. */
    password_sign_in: boolean;
}

/**
 * Every setting of the signed-in user, their choice or its default — the
 * server fills in the defaults, so the front keeps none of its own.
 */
export interface UserPreferences {
    spoiler_protection: boolean;
    /** null = automatic: the browser's own timezone. */
    timezone: string | null;
    /** What the browser resolved "automatic" to — a fallback for `timezone`, not a choice. */
    detected_timezone: string | null;
    time_format: TimeFormat;
    week_start: WeekStart;
    /** ISO 3166-1 country; null = derived from the UI language. */
    region: string | null;
    up_next_target: UpNextTarget;
    up_next_keep_finished: boolean;
    /** Order of the dashboard's in-progress sections (Up Next, Currently Playing / Reading). */
    up_next_sort: UpNextSort;
    calendar_active: boolean;
    calendar_dropped: boolean;
    calendar_on_hold: boolean;
    calendar_watchlist: boolean;
    calendar_followed: boolean;
    calendar_creators: boolean;
    calendar_game_platforms: boolean;
    calendar_release_mode: ReleaseDateMode;
    calendar_episode_image: EpisodeImage;
    countdown_active: boolean;
    countdown_dropped: boolean;
    countdown_on_hold: boolean;
    countdown_watchlist: boolean;
    countdown_followed: boolean;
    countdown_creators: boolean;
    countdown_game_platforms: boolean;
    countdown_release_mode: ReleaseDateMode;
    countdown_episode_image: EpisodeImage;
    notifications_active: boolean;
    notifications_dropped: boolean;
    notifications_on_hold: boolean;
    notifications_watchlist: boolean;
    notifications_followed: boolean;
    notifications_creators: boolean;
    notifications_release_mode: ReleaseDateMode;
    notify_episodes: boolean;
    notify_premieres: boolean;
    notify_date_changes: boolean;
    notify_show_status: boolean;
    notify_person_titles: boolean;
    notify_company_titles: boolean;
    notify_collection_items: boolean;
    person_follow_scope: PersonFollowScope;
    company_follow_scope: CompanyFollowScope;
    discord_mode: DiscordMode;
}
