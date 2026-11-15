import { discoverTypeLabel, discoverTypeLook, serializeDiscoverFilters } from '@/lib/discover';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import { useMediaTypes } from '@/test/factories';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

describe('discoverTypeLabel', () => {
    it('names media types in the plural and people as people', () => {
        expect(discoverTypeLabel('show')).toBe('Shows');
        expect(discoverTypeLabel('person')).toBe('People');
    });

    it('falls back to the code of a type it does not know', () => {
        expect(discoverTypeLabel('hologram')).toBe('hologram');
    });
});

describe('discoverTypeLook', () => {
    it('draws a media type with the glyph and colour it wears everywhere', () => {
        expect(discoverTypeLook('manga')).toMatchObject({
            icon: MEDIA_TYPE_META.manga?.icon,
            label: 'Manga',
            description: 'Japanese comics',
            color: 'var(--color-media-manga)',
        });
    });

    it('gives people the app colour, and nothing to a part of a series', () => {
        expect(discoverTypeLook('person')?.color).toBe('var(--color-brand)');
        expect(discoverTypeLook('season')).toBeNull();
    });
});

describe('serializeDiscoverFilters', () => {
    it('passes options through and splits a span into its two ends', () => {
        expect(
            serializeDiscoverFilters({
                genres: ['18', '35'],
                sort: 'vote_average.desc',
                year: { from: 1999, to: null },
                runtime: { from: 0, to: 90 },
            }),
        ).toEqual({
            genres: ['18', '35'],
            sort: 'vote_average.desc',
            year_from: '1999',
            runtime_from: '0',
            runtime_to: '90',
        });
    });

    it('leaves an empty set empty', () => {
        expect(serializeDiscoverFilters({})).toEqual({});
    });
});
