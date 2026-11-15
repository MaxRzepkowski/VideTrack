import { bookContributors, bookRoleLabel, isDirector, jobLabel, keyCrew, wroteScreenplay } from '@/lib/credits';
import { credit } from '@/test/factories';
import type { CreditData } from '@/types/generated';
import { describe, expect, it } from 'vitest';

function crew(name: string, department: CreditData['department'], ...jobs: string[]): CreditData {
    return credit({ personRef: name, name, department, role: jobs[0] ?? null, roles: jobs.map((role) => ({ role, episodeCount: null })) });
}

describe('crew', () => {
    const director = crew('Lana', 'directing', 'Director', 'Screenplay');
    const assistant = crew('Ann', 'directing', 'First Assistant Director');
    const novelist = crew('Philip', 'writing', 'Novel');
    const writer = crew('Lana', 'writing', 'Screenplay');

    it('tells the director from the department, and the screenwriter from the source', () => {
        expect([director, assistant].filter(isDirector)).toEqual([director]);
        expect([novelist, writer].filter(wroteScreenplay)).toEqual([writer]);
    });

    it('merges the key crew by person with every job, translated', () => {
        expect(keyCrew([director, assistant, novelist, writer])).toEqual([
            { name: 'Lana', ref: 'Lana', provider: 'tmdb', kind: 'person', roles: ['Director', 'Screenplay'] },
            { name: 'Philip', ref: 'Philip', provider: 'tmdb', kind: 'person', roles: ['Novel'] },
        ]);
    });

    it('says a job it does not know as the provider wrote it', () => {
        expect(jobLabel('Comic Book')).toBe('Comic book');
        expect(jobLabel('Gaffer')).toBe('Gaffer');
    });
});

describe('book contributors', () => {
    it("words a book's parts in the reader's language, a book's editor apart from a film's", () => {
        expect(bookRoleLabel('translator')).toBe('Translator');
        expect(bookRoleLabel('editor')).toBe('Editor');
        expect(jobLabel('Editor')).toBe('Editing');
        expect(bookRoleLabel('Ghost')).toBe('Ghost');
    });

    it('merges one person credited in two parts', () => {
        const people = bookContributors([
            crew('Alan Lee', 'art', 'illustrator'),
            crew('Alan Lee', 'art', 'cover_artist'),
            crew('Anna Nowak', 'translation', 'translator'),
        ]);

        expect(people).toEqual([
            { name: 'Alan Lee', ref: 'Alan Lee', provider: 'tmdb', kind: 'person', roles: ['Illustrator', 'Cover artist'] },
            { name: 'Anna Nowak', ref: 'Anna Nowak', provider: 'tmdb', kind: 'person', roles: ['Translator'] },
        ]);
    });
});
