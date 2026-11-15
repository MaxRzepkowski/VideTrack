import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import type { DiscoverActiveFilters } from '@/types/media';
import { Users, type LucideIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/** Not a media type: the people grid shares the discover type select. */
export const DISCOVER_PEOPLE = 'person';

/** A discover type as the select and the page title name it. */
export function discoverTypeLabel(type: string): string {
    return type === DISCOVER_PEOPLE ? trans('common.people') : (MEDIA_TYPE_META[type]?.plural() ?? type);
}

/** How the Discover menu draws one type: its glyph, name, line and accent. */
export interface DiscoverTypeLook {
    icon: LucideIcon;
    label: string;
    description: string;
    /** A CSS colour, `var(--color-…)`. */
    color: string;
}

/**
 * The menu's entry for a type, from the one vocabulary of media types; people
 * are no media type and wear the app's own colour. Null for a type with no
 * line to show.
 */
export function discoverTypeLook(type: string): DiscoverTypeLook | null {
    if (type === DISCOVER_PEOPLE) {
        return { icon: Users, label: trans('common.people'), description: trans('app.actors_crew'), color: 'var(--color-brand)' };
    }

    const meta = MEDIA_TYPE_META[type];

    return meta?.description ? { icon: meta.icon, label: meta.plural(), description: meta.description(), color: meta.color } : null;
}

/** The sparse filter map as query parameters; a numeric span travels as two keys. */
export function serializeDiscoverFilters(state: DiscoverActiveFilters): Record<string, string | string[]> {
    const query: Record<string, string | string[]> = {};

    for (const [key, value] of Object.entries(state)) {
        if (typeof value === 'string' || Array.isArray(value)) {
            query[key] = value;

            continue;
        }

        if (value.from !== null) {
            query[`${key}_from`] = String(value.from);
        }

        if (value.to !== null) {
            query[`${key}_to`] = String(value.to);
        }
    }

    return query;
}
