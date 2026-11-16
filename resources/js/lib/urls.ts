import { placeOf } from '@/lib/hierarchy';
import { hasMediaType, mediaTypeDefinition } from '@/lib/mediaTypes';
import type { FeedScope, SubscriptionFeed } from '@/types/generated';
import type { CreatorLink, MediaSummary } from '@/types/media';

/**
 * Details URL for any media summary. Understands hierarchical refs
 * (season "show:n", episode "show:s:e" — episodes link to their season).
 *
 * Asks for the four fields it reads rather than a whole summary, so lean
 * payloads (the command palette's rows) route through the same rule instead
 * of padding themselves out with nulls.
 */
export function mediaUrl(media: Pick<MediaSummary, 'type' | 'ref' | 'provider'> & { extra?: Record<string, unknown> }): string {
    const place = placeOf(media.ref);

    switch (media.type) {
        case 'season':
            return route('season.details', { ref: place.show, number: place.season });
        case 'episode':
            return route('episode.details', { ref: place.show, number: place.season, episode: place.episode });
        case 'person':
            return route('person.details', { provider: media.provider, ref: media.ref });
        case 'company':
            return media.extra?.kind === 'network'
                ? route('network.details', { provider: media.provider, ref: media.ref })
                : route('company.details', { provider: media.provider, ref: media.ref });
        case 'collection':
            // Its address at the provider, materialized or not: the page
            // never names the local list.
            return route('collections.show', { provider: media.provider, ref: media.ref });
    }

    // Any other type is a root of the registry: on a page of its own where it
    // has one, else on the title page shared by every type with nothing beneath it.
    if (!hasMediaType(media.type)) {
        return route('search');
    }

    return mediaTypeDefinition(media.type).ownPage
        ? route(`${media.type}.details`, { ref: media.ref })
        : route('title.details', { type: media.type, ref: media.ref });
}

/** What a calendar subscription keeps: how much of the calendar, and of which types. */
export interface FeedVariant {
    scope: FeedScope;
    /** Root type codes; the ones not offered here are left out. */
    types: string[];
}

/**
 * A feed's address for a variant. What narrows nothing stays out of it (every
 * offered type, the feed's own default scope), so the plain subscription
 * keeps its plain address. Types go in the offered order, so one variant
 * has one address.
 */
export function feedVariantUrl(feed: SubscriptionFeed, variant: FeedVariant, offeredTypes: string[]): string {
    const types = offeredTypes.filter((type) => variant.types.includes(type));
    const query = [
        ...(types.length > 0 && types.length < offeredTypes.length ? [`types=${types.map(encodeURIComponent).join(',')}`] : []),
        ...(variant.scope === feed.defaultScope ? [] : [`scope=${encodeURIComponent(variant.scope)}`]),
    ];

    return query.length === 0 ? feed.url : `${feed.url}?${query.join('&')}`;
}

/** The same address under webcal://, which makes a calendar app offer to subscribe on click. */
export function webcalUrl(url: string): string {
    return url.replace(/^https?:\/\//, 'webcal://');
}

/** A user's list lives under its owner; a collection has an address of its own ({@link mediaUrl}). */
export function listUrl(owner: string, list: number): string {
    return route('users.lists.show', { user: owner, list });
}

/** The page of the season an episode belongs to, read from the same hierarchical ref. */
export function seasonUrlOf(episode: Pick<MediaSummary, 'ref'>): string {
    const place = placeOf(episode.ref);

    return route('season.details', { ref: place.show, number: place.season });
}

/**
 * Link to a creator's own page — person or company — provider-qualified like
 * the collection routes. Returns null when the provider exposes no reachable
 * page (ref/provider absent, e.g. MAL authors), so callers render the name as
 * plain text instead of a broken link.
 */
export function creatorHref(link: Pick<CreatorLink, 'kind' | 'ref' | 'provider'>): string | null {
    if (!link.ref || !link.provider) {
        return null;
    }

    const target = { provider: link.provider, ref: link.ref };

    switch (link.kind) {
        case 'company':
            return route('company.details', target);
        case 'network':
            return route('network.details', target);
        default:
            return route('person.details', target);
    }
}
