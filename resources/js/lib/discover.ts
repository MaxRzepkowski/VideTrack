import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import type { DiscoverActiveFilters } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

/** The discover page's shelf of what is popular now, an anchor other pages link to. */
export const TRENDING_SHELF = 'trending';

/** Not a media type: the people grid shares the discover type select. */
export const DISCOVER_PEOPLE = 'person';

/** A discover type as the select and the page title name it. */
export function discoverTypeLabel(type: string): string {
    return type === DISCOVER_PEOPLE ? trans('common.people') : (MEDIA_TYPE_META[type]?.plural() ?? type);
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
