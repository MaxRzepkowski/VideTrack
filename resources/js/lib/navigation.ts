import { TRENDING_SHELF } from '@/lib/discover';
import { identityProviders, passwordLoginEnabled } from '@/lib/pageContext';
import type { NonEmpty } from '@/types';
import {
    Bell,
    Bookmark,
    Braces,
    Calendar,
    CalendarClock,
    CalendarDays,
    ChartNoAxesColumn,
    CirclePlay,
    Cog,
    Compass,
    Download,
    Heart,
    History,
    Info,
    KeyRound,
    Layers,
    LayoutGrid,
    Library,
    Link2,
    List,
    ListChecks,
    Plug,
    Search,
    Send,
    SlidersHorizontal,
    Sparkles,
    Star,
    Timer,
    TrendingUp,
    TriangleAlert,
    Upload,
    UserRound,
    Webhook,
    type LucideIcon,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

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
    /** What is there, in a few words: the line under the label in the main menu. */
    description?: () => string;
    /** A section of the page to land on, its element id. */
    fragment?: string;
    /**
     * Extra palette match terms, comma-separated, never rendered. Each
     * language lists what its speakers type, the English words a Polish UI is
     * searched with too ("watchlist", "dashboard").
     */
    keywords: () => string;
    /** Whether this instance has the place at all; absent, it always does. */
    exists?: () => boolean;
    /** A place whose actions cannot be undone reads in the danger colour, quietly. */
    destructive?: boolean;
}

/** The first word of the main menu, a place of its own rather than a group. */
export const DASHBOARD: NavEntry = {
    id: 'dashboard',
    route: 'dashboard',
    label: () => trans('common.dashboard'),
    icon: LayoutGrid,
    keywords: () => trans('palette.keywords_dashboard'),
};

export const DISCOVER: NavEntry = {
    id: 'discover',
    route: 'discover',
    label: () => trans('common.discover'),
    icon: Layers,
    description: () => trans('app.nav_discover_description'),
    keywords: () => trans('palette.keywords_discover'),
};

export const SUGGESTIONS: NavEntry = {
    id: 'suggestions',
    route: 'suggestions.index',
    label: () => trans('suggestions.suggestions'),
    icon: Sparkles,
    description: () => trans('app.nav_suggestions_description'),
    keywords: () => trans('palette.keywords_suggestions'),
};

// The trending shelf of the discover page, which opens on its first type; the
// reader switches the type there.
const TRENDING: NavEntry = {
    id: 'trending',
    route: 'discover',
    fragment: TRENDING_SHELF,
    label: () => trans('app.nav_trending'),
    icon: TrendingUp,
    description: () => trans('app.nav_trending_description'),
    keywords: () => trans('palette.keywords_trending'),
};

export const CALENDAR: NavEntry = {
    id: 'calendar',
    route: 'calendar',
    label: () => trans('common.calendar'),
    icon: CalendarDays,
    description: () => trans('app.nav_calendar_description'),
    keywords: () => trans('palette.keywords_calendar'),
};

export const COUNTDOWNS: NavEntry = {
    id: 'countdowns',
    route: 'countdowns',
    label: () => trans('common.countdowns'),
    icon: Timer,
    description: () => trans('app.nav_countdowns_description'),
    keywords: () => trans('palette.keywords_countdowns'),
};

export const STATISTICS: NavEntry = {
    id: 'statistics',
    route: 'statistics',
    label: () => trans('statistics.title'),
    icon: ChartNoAxesColumn,
    description: () => trans('app.nav_statistics_description'),
    keywords: () => trans('palette.keywords_statistics'),
};

// A list has an address by its id; the menu needs one that is the same for every account.
const BACKLOG: NavEntry = {
    id: 'backlog',
    route: 'lists.backlog',
    label: () => trans('common.backlog'),
    icon: Bookmark,
    description: () => trans('app.nav_backlog_description'),
    keywords: () => trans('palette.keywords_backlog'),
};

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
        description: () => trans('app.nav_profile_description'),
        keywords: () => trans('palette.keywords_profile'),
    },
    {
        id: 'history',
        route: 'users.history',
        label: () => trans('common.history'),
        icon: History,
        description: () => trans('app.nav_history_description'),
        keywords: () => trans('palette.keywords_history'),
    },
    {
        id: 'ratings',
        route: 'users.ratings',
        label: () => trans('common.ratings'),
        icon: Star,
        description: () => trans('app.nav_ratings_description'),
        keywords: () => trans('palette.keywords_ratings'),
    },
    {
        id: 'favorites',
        route: 'users.favorites',
        label: () => trans('common.favorites'),
        icon: Heart,
        description: () => trans('app.nav_favorites_description'),
        keywords: () => trans('palette.keywords_favorites'),
    },
    {
        id: 'progress',
        route: 'users.progress',
        label: () => trans('common.in_progress_2'),
        icon: CirclePlay,
        description: () => trans('app.nav_in_progress_description'),
        keywords: () => trans('palette.keywords_in_progress'),
    },
    {
        id: 'lists',
        route: 'users.lists',
        label: () => trans('common.lists'),
        icon: List,
        description: () => trans('app.nav_lists_description'),
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
        // Gone where identity providers are the only way in.
        exists: passwordLoginEnabled,
    },
    {
        id: 'settings.identities',
        route: 'settings.identities.index',
        label: () => trans('common.connected_accounts'),
        icon: Link2,
        keywords: () => trans('palette.keywords_settings_identities'),
        exists: () => identityProviders().length > 0,
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
        id: 'settings.channels',
        route: 'settings.channels.index',
        label: () => trans('common.notifications'),
        icon: Send,
        keywords: () => trans('palette.keywords_settings_channels'),
    },
    {
        id: 'settings.calendar',
        route: 'settings.calendar-subscription.edit',
        label: () => trans('settings.calendar_subscription'),
        icon: Calendar,
        keywords: () => trans('palette.keywords_settings_calendar'),
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
        id: 'settings.webhooks',
        route: 'settings.webhooks.index',
        label: () => trans('common.webhooks'),
        icon: Webhook,
        keywords: () => trans('palette.keywords_settings_webhooks'),
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
        destructive: true,
    },
];

/** The settings sidebar of this instance: the places it has, in their order. */
export function settingsNav(): NavEntry[] {
    return SETTINGS_NAV.filter((entry) => entry.exists?.() ?? true);
}

/** The address of a place, as a path; a user panel needs whose. */
export function navHref(entry: NavEntry, username?: string): string {
    const path = route(entry.route, username === undefined ? {} : { user: username }, false);

    return entry.fragment === undefined ? path : `${path}#${entry.fragment}`;
}

/** One user panel by id. */
export function userPanel(id: UserPanel): NavEntry {
    return USER_PANELS.find((panel) => panel.id === id)!;
}

export type NavGroupId = 'library' | 'discover' | 'releases';

/**
 * A row of a main-menu group: a place of the app, or a panel of the signed-in
 * user's own profile, which needs their username for its address.
 */
export type NavGroupItem = { kind: 'place'; entry: NavEntry } | { kind: 'own-panel'; entry: NavEntry };

export interface NavGroup {
    id: NavGroupId;
    label: () => string;
    icon: LucideIcon;
    items: NonEmpty<NavGroupItem>;
}

const place = (entry: NavEntry): NavGroupItem => ({ kind: 'place', entry });
const ownPanel = (id: UserPanel): NavGroupItem => ({ kind: 'own-panel', entry: userPanel(id) });

/**
 * The main menu after Dashboard: what you have, what to find, what is coming.
 * A place sits in one group only, so the word lit in the bar always names the
 * group of the page you are on.
 */
export const NAV_GROUPS: NonEmpty<NavGroup> = [
    {
        id: 'library',
        label: () => trans('app.nav_library'),
        icon: Library,
        items: [
            ownPanel('progress'),
            place(BACKLOG),
            ownPanel('lists'),
            ownPanel('favorites'),
            ownPanel('history'),
            ownPanel('ratings'),
            place(STATISTICS),
        ],
    },
    {
        id: 'discover',
        label: () => trans('common.discover'),
        icon: Compass,
        items: [place(DISCOVER), place(SUGGESTIONS), place(TRENDING)],
    },
    {
        id: 'releases',
        label: () => trans('app.nav_releases'),
        icon: CalendarClock,
        items: [place(CALENDAR), place(COUNTDOWNS)],
    },
];

/** The group of the main menu a place or a panel sits in; null for one the menu does not hold. */
export function groupOf(entry: NavEntry): NavGroupId | null {
    return NAV_GROUPS.find((group) => group.items.some((item) => item.entry.id === entry.id))?.id ?? null;
}

/** Where a menu row leads, for the user signed in as `username`. */
export function groupItemHref(item: NavGroupItem, username: string): string {
    return item.kind === 'own-panel' ? navHref(item.entry, username) : navHref(item.entry);
}

/** Places of the main menu that are not someone's profile panel: Dashboard, then the groups' own places in order. */
export function menuPlaces(): NavEntry[] {
    return [DASHBOARD, ...NAV_GROUPS.flatMap((group) => group.items.flatMap((item) => (item.kind === 'place' ? [item.entry] : [])))];
}

const withoutQuery = (url: string): string => url.replace(/[?#].*$/, '');

/** Whether `url` is the page at `href` or one under it, whatever its query or fragment. */
export function isAtOrUnder(url: string, href: string): boolean {
    const path = withoutQuery(url);
    const target = withoutQuery(href);

    return path === target || path.startsWith(`${target}/`);
}

/**
 * The word of the main menu the page at `url` belongs to: Dashboard, a group,
 * or nothing (settings, a title, someone else's profile).
 */
export function activeMenuWord(url: string, username: string): 'dashboard' | NavGroupId | null {
    if (isAtOrUnder(url, navHref(DASHBOARD))) {
        return 'dashboard';
    }

    const group = NAV_GROUPS.find((candidate) => candidate.items.some((item) => isAtOrUnder(url, groupItemHref(item, username))));

    return group?.id ?? null;
}

/**
 * The one row of the menu drawer the page at `url` is: Dashboard or a group's
 * place, by its entry id. A row leading to a section of a page shares the page
 * with another row, which is the one lit there; null off the menu.
 */
export function currentMenuRow(url: string, username: string): string | null {
    if (isAtOrUnder(url, navHref(DASHBOARD))) {
        return DASHBOARD.id;
    }

    const row = NAV_GROUPS.flatMap((group) => group.items).find(
        (item) => item.entry.fragment === undefined && isAtOrUnder(url, groupItemHref(item, username)),
    );

    return row?.entry.id ?? null;
}
