import { registerMediaTypes, type MediaTypeDefinition } from '@/lib/mediaTypes';
import type { UserPreferences } from '@/types';
import type { ChannelData, ChannelKindData, CredentialField, CreditData, UserMediaState } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import ALL_MODULES_TYPES from '../../../tests/Fixtures/media-types/all-modules.json';
import DEFAULT_PREFERENCES from '../../../tests/Fixtures/preferences/defaults.json';

/**
 * The registry as a fully configured instance shares it (every optional
 * module on), in the backend's order — the file a PHP test checks against
 * `MediaTypeRegistry`, so the front's tests run on the server's types.
 */
export const MEDIA_TYPES = ALL_MODULES_TYPES as MediaTypeDefinition[];

export function useMediaTypes(types: MediaTypeDefinition[] = MEDIA_TYPES): void {
    registerMediaTypes(types);
}

/** A title as a card receives it; only what a test cares about needs saying. */
export function mediaSummary(overrides: Partial<MediaSummary> = {}): MediaSummary {
    return {
        type: 'movie',
        ref: '1',
        provider: 'tmdb',
        title: 'Title',
        year: 2026,
        posterPath: null,
        backdropPath: null,
        communityRating: null,
        releaseDate: null,
        releaseTime: null,
        number: null,
        totalRuntime: null,
        progressTotal: null,
        extra: {},
        ...overrides,
    };
}

/** A title nobody has touched: the server's default state, overridden where a test needs. */
export function userState(overrides: Partial<UserMediaState> = {}): UserMediaState {
    return {
        watched: false,
        watchedAt: null,
        droppedAt: null,
        plays: 0,
        cycle: 1,
        completedCycles: 0,
        rating: null,
        favorite: false,
        listIds: [],
        follow: null,
        followVia: null,
        followSource: null,
        status: null,
        rootStatus: null,
        statusInherited: false,
        progress: null,
        playedAmount: null,
        droppedAmount: null,
        latestDropped: false,
        watchedEpisodes: null,
        airedEpisodes: null,
        remainingEpisodes: null,
        skippedEpisodes: null,
        remainingMinutes: null,
        watchedMinutes: null,
        position: null,
        nextEpisode: null,
        inProgress: false,
        progression: true,
        ...overrides,
    };
}

/**
 * The settings of an account that never chose anything — what the server
 * shares for it, from the file the server's own test holds it to.
 */
export function userPreferences(overrides: Partial<UserPreferences> = {}): UserPreferences {
    return { ...(DEFAULT_PREFERENCES as UserPreferences), ...overrides };
}

/** A title's credit: a lead actor unless the test says otherwise. */
export function credit(overrides: Partial<CreditData> = {}): CreditData {
    return {
        personRef: '17419',
        provider: 'tmdb',
        name: 'Bryan Cranston',
        profilePath: null,
        department: 'acting',
        role: 'Walter White',
        order: 0,
        episodeCount: null,
        roles: [],
        ...overrides,
    };
}

/** One field of a form a module describes (an import source, a channel). */
export function credentialField(overrides: Partial<CredentialField> = {}): CredentialField {
    return { key: 'webhook_url', label: 'Webhook address', type: 'url', help: null, optional: false, secret: false, ...overrides };
}

/** A kind of notification channel the instance offers; Discord unless told otherwise. */
export function channelKind(overrides: Partial<ChannelKindData> = {}): ChannelKindData {
    return {
        driver: 'discord',
        code: 'discord',
        label: 'Discord',
        fields: [credentialField({ secret: true })],
        setupUrl: null,
        popular: true,
        ...overrides,
    };
}

/** One of the user's notification channels as the settings page lists it. */
export function channelData(overrides: Partial<ChannelData> = {}): ChannelData {
    return {
        id: 4,
        driver: 'discord',
        kind: 'discord',
        kindLabel: 'Discord',
        label: 'Movie night',
        mode: 'digest',
        enabled: true,
        values: {},
        lastFailure: null,
        ...overrides,
    };
}
