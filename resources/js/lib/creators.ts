import type { CreatorKind } from '@/types/generated';
import type { CreatorLink, MediaSummary } from '@/types/media';

/** Whoever makes titles and can be followed or put on a list. */
export interface Creator {
    kind: Exclude<CreatorKind, 'collection'>;
    provider: string;
    ref: string;
}

/** What the user has done with a creator: the bell and the lists. */
export interface CreatorState {
    followed: boolean;
    listIds: number[];
}

/** The page's `creatorStates`: one entry per creator the user ever touched. */
export type CreatorStatesMap = Record<string, CreatorState>;

/** The one spelling of a creator in maps keyed by creator — the server's `CreatorRef::key()`. */
export function creatorKey(creator: Creator): string {
    return `${creator.kind}:${creator.provider}:${creator.ref}`;
}

/**
 * The creator a card stands for: search results, list positions and discover
 * rows carry people and companies as summaries of type `person` or `company`
 * (a network says so in `extra.kind`) — the server's `CreatorRef::fromSummary`.
 * Null for a title.
 */
/** A card that stands for a collection (a saga, a series) rather than a title or a creator. */
export function isCollection(summary: MediaSummary): boolean {
    return summary.type === 'collection';
}

export function creatorOf(summary: MediaSummary): Creator | null {
    if (summary.type === 'person') {
        return { kind: 'person', provider: summary.provider, ref: summary.ref };
    }

    if (summary.type === 'company') {
        return { kind: summary.extra.kind === 'network' ? 'network' : 'company', provider: summary.provider, ref: summary.ref };
    }

    return null;
}

/** Who a credit names and where their page is; a credit has no wordmark to show. */
export type CreditPerson = Pick<CreatorLink, 'name' | 'kind' | 'ref' | 'provider'> & { roles: string[] };

/** People named on a card without a page of their own (a manga's authors): plain names under one role. */
export function plainCredits(names: string[], role: string): CreditPerson[] {
    return names.map((name) => ({ name, kind: 'person', ref: null, provider: null, roles: [role] }));
}
