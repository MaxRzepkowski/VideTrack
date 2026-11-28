import { creatorHref, displayUrl, feedVariantUrl, listUrl, mediaUrl, seasonUrlOf, webcalUrl } from '@/lib/urls';
import { useMediaTypes } from '@/test/factories';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

describe('mediaUrl', () => {
    it('routes a type with a page of its own there, and every other root to the shared title page', () => {
        expect(mediaUrl({ type: 'movie', ref: '42', provider: 'x' })).toBe('/movie.details/42');
        expect(mediaUrl({ type: 'show', ref: '42', provider: 'x' })).toBe('/show.details/42');
        expect(mediaUrl({ type: 'manga', ref: '42', provider: 'x' })).toBe('/title.details/manga/42');
    });

    it('splits hierarchical refs into show, season and episode', () => {
        expect(mediaUrl({ type: 'season', ref: '10:2', provider: 'tmdb' })).toBe('/season.details/10/2');
        expect(mediaUrl({ type: 'episode', ref: '10:2:5', provider: 'tmdb' })).toBe('/episode.details/10/2/5');
    });

    it('qualifies creators with their provider, a network apart from a company', () => {
        expect(mediaUrl({ type: 'person', ref: '7', provider: 'tmdb' })).toBe('/person.details/tmdb/7');
        expect(mediaUrl({ type: 'company', ref: '7', provider: 'tmdb' })).toBe('/company.details/tmdb/7');
        expect(mediaUrl({ type: 'company', ref: '7', provider: 'tmdb', extra: { kind: 'network' } })).toBe('/network.details/tmdb/7');
    });

    it('opens a user list under its owner', () => {
        expect(listUrl('ana', 12)).toBe('/users.lists.show/ana/12');
    });

    it('opens a collection at its provider address', () => {
        expect(mediaUrl({ type: 'collection', ref: '10194', provider: 'tmdb' })).toBe('/collections.show/tmdb/10194');
    });

    it('sends an unknown type to search rather than a broken page', () => {
        expect(mediaUrl({ type: 'podcast', ref: '1', provider: 'x' })).toBe('/search');
    });
});

describe('creatorHref', () => {
    it('links a creator by kind', () => {
        expect(creatorHref({ kind: 'person', ref: '1', provider: 'tmdb' })).toBe('/person.details/tmdb/1');
        expect(creatorHref({ kind: 'company', ref: '2', provider: 'igdb' })).toBe('/company.details/igdb/2');
        expect(creatorHref({ kind: 'network', ref: '3', provider: 'tmdb' })).toBe('/network.details/tmdb/3');
    });

    it('leaves a creator without a reachable page as plain text', () => {
        expect(creatorHref({ kind: 'person', ref: null, provider: 'mal' })).toBeNull();
        expect(creatorHref({ kind: 'person', ref: '5', provider: null })).toBeNull();
    });
});

describe('seasonUrlOf', () => {
    it('opens the season an episode belongs to', () => {
        expect(seasonUrlOf({ ref: '1399:2:9' })).toBe(route('season.details', { ref: '1399', number: '2' }));
    });
});

describe('feedVariantUrl', () => {
    const calendar = { url: 'https://example.com/calendar/abc.ics', defaultScope: 'all' } as const;
    const rss = { url: 'https://example.com/calendar/abc.rss', defaultScope: 'premieres' } as const;
    const offered = ['show', 'movie', 'game'];

    it('keeps the plain address for every type in the feed default scope', () => {
        expect(feedVariantUrl(calendar, { scope: 'all', types: ['game', 'movie', 'show'] }, offered)).toBe(calendar.url);
        expect(feedVariantUrl(rss, { scope: 'premieres', types: offered }, offered)).toBe(rss.url);
    });

    it('names the chosen types in the offered order, and a scope other than the default', () => {
        expect(feedVariantUrl(calendar, { scope: 'all', types: ['game', 'show'] }, offered)).toBe(`${calendar.url}?types=show,game`);
        expect(feedVariantUrl(calendar, { scope: 'premieres', types: ['movie'] }, offered)).toBe(`${calendar.url}?types=movie&scope=premieres`);
        expect(feedVariantUrl(rss, { scope: 'all', types: offered }, offered)).toBe(`${rss.url}?scope=all`);
    });

    it('leaves out a type not offered here, and narrows nothing when none is left', () => {
        expect(feedVariantUrl(calendar, { scope: 'all', types: ['podcast', 'movie'] }, offered)).toBe(`${calendar.url}?types=movie`);
        expect(feedVariantUrl(calendar, { scope: 'all', types: ['podcast'] }, offered)).toBe(calendar.url);
    });
});

describe('displayUrl', () => {
    it('drops the scheme and keeps the rest', () => {
        expect(displayUrl('https://trakt.tv/settings/data')).toBe('trakt.tv/settings/data');
        expect(displayUrl('http://192.168.1.10:3000/')).toBe('192.168.1.10:3000/');
        expect(displayUrl('example.com/pin')).toBe('example.com/pin');
    });
});

describe('webcalUrl', () => {
    it('swaps the scheme so a calendar app offers to subscribe', () => {
        expect(webcalUrl('https://example.com/calendar/abc.ics?types=movie')).toBe('webcal://example.com/calendar/abc.ics?types=movie');
        expect(webcalUrl('http://localhost:8080/calendar/abc.ics')).toBe('webcal://localhost:8080/calendar/abc.ics');
    });
});
