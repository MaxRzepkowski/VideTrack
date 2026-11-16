import { creatorKey, creatorOf, isCollection, mangaAuthorCredits, plainCredits } from '@/lib/creators';
import { mediaSummary } from '@/test/factories';
import { describe, expect, it } from 'vitest';

describe('creators', () => {
    it('keys a creator by kind, provider and ref — a studio and a network can share a ref', () => {
        const studio = { kind: 'company', provider: 'tmdb', ref: '174' } as const;

        expect(creatorKey(studio)).toBe('company:tmdb:174');
        expect(creatorKey({ ...studio, kind: 'network' })).toBe('network:tmdb:174');
    });

    it('reads the creator a card stands for, and nothing from a title', () => {
        expect(creatorOf(mediaSummary({ type: 'person', ref: '6384' }))).toEqual({ kind: 'person', provider: 'tmdb', ref: '6384' });
        expect(creatorOf(mediaSummary({ type: 'company', ref: '49', extra: { kind: 'network' } }))).toEqual({
            kind: 'network',
            provider: 'tmdb',
            ref: '49',
        });
        expect(creatorOf(mediaSummary({ type: 'company', ref: '174' }))?.kind).toBe('company');
        expect(creatorOf(mediaSummary({ type: 'movie' }))).toBeNull();
    });
});

describe('plainCredits and isCollection', () => {
    it('names people without a page under one role', () => {
        expect(plainCredits(['Kentaro Miura'], 'Author')).toEqual([
            { name: 'Kentaro Miura', kind: 'person', ref: null, provider: null, roles: ['Author'] },
        ]);
    });

    it('tells a collection card from a title or a creator', () => {
        expect(isCollection(mediaSummary({ type: 'collection' }))).toBe(true);
        expect(isCollection(mediaSummary({ type: 'person' }))).toBe(false);
    });
});

describe('mangaAuthorCredits', () => {
    it('credits each author under what they did, and one the provider says nothing of as an author', () => {
        const credits = mangaAuthorCredits(['Tsugumi Ohba', 'Kentarou Miura', 'Kouji Mori'], {
            'Tsugumi Ohba': ['story'],
            'Kentarou Miura': ['story', 'art'],
        });

        expect(credits.map((person) => [person.name, person.roles])).toEqual([
            ['Tsugumi Ohba', ['Story']],
            ['Kentarou Miura', ['Story', 'Art']],
            ['Kouji Mori', ['Author']],
        ]);
        expect(credits[0]).toMatchObject({ kind: 'person', ref: null, provider: null });
    });
});
