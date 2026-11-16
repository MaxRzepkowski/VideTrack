import {
    hasUserStatus,
    inPersonPageOrder,
    inRegistryOrder,
    kindPlural,
    kindsLabel,
    MEDIA_TYPE_META,
    mediaTypeDefinition,
    mediaTypeMeta,
    rootMediaTypes,
} from '@/lib/mediaTypes';
import { MEDIA_TYPES, mediaSummary, useMediaTypes } from '@/test/factories';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

describe('mediaTypeMeta', () => {
    it('gives every registry type a label, glyph and colour token', () => {
        for (const { code } of MEDIA_TYPES) {
            const meta = mediaTypeMeta(code);

            expect(meta.label()).not.toContain('.');
            expect(meta.color).toMatch(/^var\(--color-media-/);
        }

        expect(mediaTypeMeta('movie').plural()).toBe('Movies');
    });

    it('falls back to the show entry for a type it does not know', () => {
        expect(mediaTypeMeta('podcast')).toBe(MEDIA_TYPE_META.show);
    });
});

describe('definitions from the registry', () => {
    it('reads what a type can do', () => {
        expect(mediaTypeDefinition('game').progressUnit).toBe('minutes');
        expect(mediaTypeDefinition('season').parentType).toBe('show');
        expect(hasUserStatus(mediaSummary({ type: 'show' }))).toBe(true);
        expect(hasUserStatus(mediaSummary({ type: 'episode' }))).toBe(false);
    });

    it('lets a type this instance does not run do nothing', () => {
        useMediaTypes(MEDIA_TYPES.filter((type) => type.code !== 'game'));

        expect(mediaTypeDefinition('game')).toEqual({
            code: 'game',
            parentType: null,
            childType: null,
            playable: false,
            userStatus: false,
            progressUnit: null,
            ownPage: false,
            genreBrowsable: false,
        });
    });

    it('orders codes the way the registry does, unknown ones last as they came', () => {
        expect(inRegistryOrder(['book', 'movie', 'podcast', 'show', 'zine'])).toEqual(['show', 'movie', 'book', 'podcast', 'zine']);
        expect(inRegistryOrder(new Set(['game', 'show']))).toEqual(['show', 'game']);
    });

    it('names the types a title is stored under as a whole, in the registry order', () => {
        expect(rootMediaTypes()).toEqual(MEDIA_TYPES.filter((type) => type.parentType === null).map((type) => type.code));
        expect(rootMediaTypes()).not.toContain('season');
        expect(rootMediaTypes()).not.toContain('episode');
    });

    it("puts films before shows on a person's page, the rest in the registry's order", () => {
        expect(inPersonPageOrder(['book', 'show', 'game', 'movie'])).toEqual(['movie', 'show', 'game', 'book']);
        expect(inPersonPageOrder(new Set(['book', 'show']))).toEqual(['show', 'book']);
    });
});

describe('naming kinds', () => {
    it('names a media type and the kinds beside them in their plural', () => {
        expect(kindPlural('movie')).toBe('Movies');
        expect(kindPlural('person')).toBe('People');
        expect(kindPlural('collection')).toBe('Collections');
    });

    it('names a group of kinds in the registry order, with the language conjunction', () => {
        expect(kindsLabel(['movie', 'show'])).toBe('Shows and movies');
        expect(kindsLabel(['company'])).toBe('Companies');
    });
});
