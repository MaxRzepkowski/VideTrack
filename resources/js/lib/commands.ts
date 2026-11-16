import { MAIN_NAV, navHref, OTHER_PLACES, settingsNav, USER_PANELS, type NavEntry } from '@/lib/navigation';
import { viewerUsername } from '@/lib/pageContext';
import { router } from '@inertiajs/vue3';
import { LogOut, type LucideIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/**
 * A palette row pointing at a title — the lean shape `PaletteController` sends
 * (enough for a row and for `mediaUrl()`, nothing more).
 */
export interface PaletteItem {
    type: string;
    ref: string;
    provider: string;
    title: string;
    year: number | null;
    posterPath: string | null;
}

/** A row of the command palette that is not a media hit: a place to go or a thing to do. */
export interface Command {
    /** Stable across renders: it is the combobox item's value. */
    id: string;
    group: 'navigation' | 'actions';
    /** A thunk, so `trans()` stays a literal call yet re-runs per locale. */
    label: () => string;
    icon?: LucideIcon;
    /** Extra match terms, comma-separated ({@see NavEntry.keywords}). */
    keywords: () => string;
    perform: () => void;
}

function goTo(id: string, entry: NavEntry, label: () => string = entry.label, username?: () => string): Command {
    return {
        id,
        group: 'navigation',
        label,
        icon: entry.icon,
        keywords: entry.keywords,
        perform: () => router.visit(navHref(entry, username?.())),
    };
}

/**
 * Every command, in the order the palette lists them when nothing is typed:
 * the menu's places, the rest of the app, your own panels, then settings.
 */
export function allCommands(): Command[] {
    return [
        ...[...MAIN_NAV, ...OTHER_PLACES].map((entry) => goTo(`nav.${entry.id}`, entry)),
        // Your profile's lists panel is not the Lists manager, and both would
        // otherwise read "Lists".
        ...USER_PANELS.map((panel) =>
            goTo(
                `nav.profile.${panel.id}`,
                panel,
                panel.id === 'lists' ? () => trans('palette.profile_section', { section: panel.label() }) : panel.label,
                // Read at perform() time, so a command declared once still
                // points at whoever is signed in now.
                viewerUsername,
            ),
        ),
        ...settingsNav().map((entry) => goTo(`nav.${entry.id}`, entry, () => trans('palette.settings_section', { section: entry.label() }))),
        {
            id: 'action.logout',
            group: 'actions',
            label: () => trans('palette.log_out'),
            icon: LogOut,
            keywords: () => trans('palette.keywords_log_out'),
            perform: () => router.post(route('logout')),
        },
    ];
}

/**
 * Case- and diacritic-insensitive form for matching: a Polish label typed
 * without its diacritics ("sledzenie") has to find "Śledzenie".
 */
function normalize(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .replace(/ł/g, 'l');
}

/**
 * Commands matching what was typed — substring, no fuzzy matching: the list is
 * short and a fuzzy score turns "list" into a lottery.
 */
export function matchCommands(commands: Command[], query: string): Command[] {
    const term = normalize(query.trim());

    if (term === '') {
        return commands;
    }

    return commands.filter((command) => [command.label(), ...command.keywords().split(',')].some((candidate) => normalize(candidate).includes(term)));
}
