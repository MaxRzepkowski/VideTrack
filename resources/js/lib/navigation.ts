import type { NonEmpty } from '@/types';
import { trans } from 'laravel-vue-i18n';
import {
    Bell,
    Braces,
    CalendarDays,
    CirclePlay,
    Cog,
    Compass,
    Download,
    Heart,
    History,
    Info,
    KeyRound,
    LayoutGrid,
    List,
    ListChecks,
    Plug,
    Search,
    SlidersHorizontal,
    Sparkles,
    Star,
    Timer,
    TriangleAlert,
    Upload,
    UserRound,
    type LucideIcon,
} from 'lucide-vue-next';

/**
 * A place in the app. Every surface that links to places — the main menu, the
 * profile tabs, the account menu, the settings sidebar and the command palette
 * — renders from these lists, so a new page is one entry and its label, icon
 * and address read the same everywhere.
 */
export interface NavEntry {
    id: string;
    /** Route name; a user panel takes the username as its `user` parameter. */
    route: string;
    /** A thunk, so `trans()` stays a literal call yet re-runs per locale. */
    label: () => string;
    icon: LucideIcon;
    /**
     * Extra palette match terms, comma-separated, never rendered. Each
     * language lists what its speakers type, the English words a Polish UI is
     * searched with too ("watchlist", "dashboard").
     */
    keywords: () => string;
    /** Also lit on its subpages and on itself with a query. */
    prefix?: string;
}

/** The main menu, in its order. */
export const MAIN_NAV: NonEmpty<NavEntry> = [
    {
        id: 'dashboard',
        route: 'dashboard',
        label: () => trans('common.dashboard'),
        icon: LayoutGrid,
        keywords: () => trans('palette.keywords_dashboard'),
    },
    {
        id: 'discover',
        route: 'discover',
        label: () => trans('common.discover'),
        icon: Compass,
        keywords: () => trans('palette.keywords_discover'),
        prefix: '/discover',
    },
    {
        id: 'suggestions',
        route: 'suggestions.index',
        label: () => trans('suggestions.suggestions'),
        icon: Sparkles,
        keywords: () => trans('palette.keywords_suggestions'),
        prefix: '/suggestions',
    },
    {
        id: 'calendar',
        route: 'calendar',
        label: () => trans('common.calendar'),
        icon: CalendarDays,
        keywords: () => trans('palette.keywords_calendar'),
        prefix: '/calendar',
    },
    {
        id: 'countdowns',
        route: 'countdowns',
        label: () => trans('common.countdowns'),
        icon: Timer,
        keywords: () => trans('palette.keywords_countdowns'),
        prefix: '/countdowns',
    },
];

/** Places reached from elsewhere than a menu: the header's own buttons, the footer, the palette. */
export const OTHER_PLACES: NavEntry[] = [
    {
        id: 'lists',
        route: 'lists.index',
        label: () => trans('common.lists'),
        icon: List,
        keywords: () => trans('palette.keywords_lists'),
    },
    {
        id: 'search',
        route: 'search',
        label: () => trans('common.search'),
        icon: Search,
        keywords: () => trans('palette.keywords_search'),
    },
    {
        id: 'notifications',
        route: 'notifications.index',
        label: () => trans('common.notifications'),
        icon: Bell,
        keywords: () => trans('palette.keywords_notifications'),
    },
    {
        id: 'about',
        route: 'about',
        label: () => trans('common.about'),
        icon: Info,
        keywords: () => trans('palette.keywords_about'),
    },
];

export type UserPanel = 'show' | 'history' | 'ratings' | 'favorites' | 'progress' | 'lists' | 'following';

/** The panels of a user, in tab order; the id is the route name without `users.`. */
export const USER_PANELS: (NavEntry & { id: UserPanel; ownerOnly?: boolean })[] = [
    {
        id: 'show',
        route: 'users.show',
        label: () => trans('common.profile'),
        icon: UserRound,
        keywords: () => trans('palette.keywords_profile'),
    },
    {
        id: 'history',
        route: 'users.history',
        label: () => trans('common.history'),
        icon: History,
        keywords: () => trans('palette.keywords_history'),
    },
    {
        id: 'ratings',
        route: 'users.ratings',
        label: () => trans('common.ratings'),
        icon: Star,
        keywords: () => trans('palette.keywords_ratings'),
    },
    {
        id: 'favorites',
        route: 'users.favorites',
        label: () => trans('common.favorites'),
        icon: Heart,
        keywords: () => trans('palette.keywords_favorites'),
    },
    {
        id: 'progress',
        route: 'users.progress',
        label: () => trans('common.in_progress_2'),
        icon: CirclePlay,
        keywords: () => trans('palette.keywords_in_progress'),
    },
    {
        id: 'lists',
        route: 'users.lists',
        label: () => trans('common.lists'),
        icon: List,
        keywords: () => trans('palette.keywords_profile_lists'),
    },
    {
        // The bell of "Follow releases": what you follow is personal, so the
        // tab shows on your own profile only.
        id: 'following',
        route: 'users.following',
        label: () => trans('common.following'),
        icon: Bell,
        keywords: () => trans('palette.keywords_following'),
        ownerOnly: true,
    },
];

/** The settings sidebar, in its order. */
export const SETTINGS_NAV: NonEmpty<NavEntry> = [
    {
        id: 'settings.profile',
        route: 'settings.profile.edit',
        label: () => trans('common.profile'),
        icon: UserRound,
        keywords: () => trans('palette.keywords_settings_profile'),
    },
    {
        id: 'settings.password',
        route: 'settings.password.edit',
        label: () => trans('common.password'),
        icon: KeyRound,
        keywords: () => trans('palette.keywords_settings_password'),
    },
    {
        id: 'settings.preferences',
        route: 'settings.preferences.edit',
        label: () => trans('common.preferences'),
        icon: SlidersHorizontal,
        keywords: () => trans('palette.keywords_settings_preferences'),
    },
    {
        id: 'settings.tracking',
        route: 'settings.tracking.edit',
        label: () => trans('common.tracking'),
        icon: ListChecks,
        keywords: () => trans('palette.keywords_settings_tracking'),
    },
    {
        id: 'settings.integrations',
        route: 'settings.integrations.index',
        label: () => trans('common.integrations'),
        icon: Plug,
        keywords: () => trans('palette.keywords_settings_integrations'),
    },
    {
        id: 'settings.import',
        route: 'settings.import.index',
        label: () => trans('common.import_data'),
        icon: Upload,
        keywords: () => trans('palette.keywords_settings_import'),
    },
    {
        id: 'settings.export',
        route: 'settings.export.index',
        label: () => trans('common.export_data'),
        icon: Download,
        keywords: () => trans('palette.keywords_settings_export'),
    },
    {
        id: 'settings.api',
        route: 'settings.api-tokens.index',
        label: () => trans('common.api'),
        icon: Braces,
        keywords: () => trans('palette.keywords_settings_api'),
    },
    {
        id: 'settings.tasks',
        route: 'settings.tasks.index',
        label: () => trans('common.tasks'),
        icon: Cog,
        keywords: () => trans('palette.keywords_settings_tasks'),
    },
    {
        id: 'settings.danger',
        route: 'settings.danger.index',
        label: () => trans('common.danger_zone'),
        icon: TriangleAlert,
        keywords: () => trans('palette.keywords_settings_danger'),
    },
];

/** The address of a place, as a path; a user panel needs whose. */
export function navHref(entry: NavEntry, username?: string): string {
    return route(entry.route, username === undefined ? {} : { user: username }, false);
}

/** One user panel by id. */
export function userPanel(id: UserPanel): NavEntry {
    return USER_PANELS.find((panel) => panel.id === id)!;
}
