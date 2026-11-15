import { listCsv, listFileName, listText } from '@/lib/listText';
import { mediaSummary } from '@/test/factories';
import type { ListItemEntry, MediaSummary } from '@/types/media';
import { describe, expect, it } from 'vitest';

describe('a list as text', () => {
    it('gives a position per line, a title with its year', () => {
        const text = listText([mediaSummary({ title: 'The Matrix', year: 1999 }), mediaSummary({ title: 'Untitled Sequel', year: null })]);

        expect(text).toBe('The Matrix (1999)\nUntitled Sequel\n');
    });

    it("names an episode and a season under their show, with the episode's code", () => {
        const episode = mediaSummary({
            type: 'episode',
            title: 'Ozymandias',
            year: 2013,
            number: 14,
            extra: { showTitle: 'Breaking Bad', seasonNumber: 5 },
        });
        const season = mediaSummary({
            type: 'season',
            title: 'Season 2',
            year: 2009,
            number: 2,
            extra: { showTitle: 'Breaking Bad', seasonNumber: 2 },
        });

        expect(listText([episode, season])).toBe('Breaking Bad – S05E14 – Ozymandias (2013)\nBreaking Bad – Season 2 (2009)\n');
    });

    it('names a person and a company alone', () => {
        const person = mediaSummary({ type: 'person', title: 'Lana Wachowski', year: 1965 });
        const company = mediaSummary({ type: 'company', title: 'Warner Bros.', extra: {} });

        expect(listText([person, company])).toBe('Lana Wachowski\nWarner Bros.\n');
    });

    it('is empty for an empty list', () => {
        expect(listText([])).toBe('');
    });
});

describe('the file the list is saved as', () => {
    it('is named after the list, without what a file name cannot hold', () => {
        expect(listFileName('Films: 2024/2025?', 'txt', 'list')).toBe('Films 20242025.txt');
        expect(listFileName('...', 'csv', 'list')).toBe('list.csv');
    });
});

describe('a list as a spreadsheet', () => {
    const entry = (media: MediaSummary, imdbId: string | null, mediaItemId: number | null): ListItemEntry => ({
        id: 1,
        addedAt: null,
        genres: [],
        media,
        imdbId,
        mediaItemId,
    });

    it('gives a header and a row per position, with the ids the title is known by', () => {
        const csv = listCsv([
            entry(mediaSummary({ title: 'The Matrix', year: 1999, ref: '603' }), 'tt0133093', 42),
            entry(mediaSummary({ type: 'person', title: 'Lana Wachowski', year: null, ref: '9340' }), null, null),
        ]);

        expect(csv).toBe(
            '\uFEFFtitle,year,type,provider,ref,imdb_id,id\r\nThe Matrix,1999,movie,tmdb,603,tt0133093,42\r\nLana Wachowski,,person,tmdb,9340,,\r\n',
        );
    });

    it('quotes a field a comma or a quote would split', () => {
        const csv = listCsv([entry(mediaSummary({ title: 'Crouching Tiger, "Hidden" Dragon', year: 2000, ref: '146' }), null, 7)]);

        expect(csv.split('\r\n')[1]).toBe('"Crouching Tiger, ""Hidden"" Dragon",2000,movie,tmdb,146,,7');
    });
});
