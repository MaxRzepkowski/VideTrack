import type { BookRole, CreditData, Department } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

/**
 * Crew jobs as the provider spells them, and what the title pages do with
 * them: who directed, who wrote the screenplay and who wrote the source it
 * adapts, and each job in the reader's language. A job not named here comes
 * through as the provider wrote it.
 */

const DIRECTOR = 'Director';

/**
 * What a cast tile reads of a credit. A title's credit (CreditData) and a
 * person gathered across a collection (CollectionCredit) are both one, so one
 * cast section shows either.
 */
export interface CastPerson {
    personRef: string;
    provider: string;
    name: string;
    profilePath: string | null;
    department: Department;
    role?: string | null;
    roles: { role: string | null; episodeCount?: number | null }[];
    episodeCount?: number | null;
}

/** Jobs that credit the adapted source (its IP), not the film's screenplay. */
const SOURCE_JOBS = new Set(['Characters', 'Comic Book', 'Novel', 'Book', 'Graphic Novel', 'Short Story', 'Theatre Play']);

// One literal key per job, so every label is checked for a translation.
const JOB_LABELS: Record<string, () => string> = {
    Director: () => trans('media.job_director'),
    Screenplay: () => trans('media.job_screenplay'),
    Writer: () => trans('media.job_writer'),
    Story: () => trans('media.job_story'),
    'Original Story': () => trans('media.job_original_story'),
    Teleplay: () => trans('media.job_teleplay'),
    Characters: () => trans('media.job_characters'),
    'Comic Book': () => trans('media.job_comic_book'),
    Novel: () => trans('media.job_novel'),
    Book: () => trans('media.job_book'),
    'Graphic Novel': () => trans('media.job_graphic_novel'),
    'Short Story': () => trans('media.job_short_story'),
    'Theatre Play': () => trans('media.job_theatre_play'),
    Creator: () => trans('media.job_creator'),
    Producer: () => trans('media.job_producer'),
    'Executive Producer': () => trans('media.job_executive_producer'),
    'Original Music Composer': () => trans('media.job_original_music_composer'),
    'Director of Photography': () => trans('media.job_director_of_photography'),
    Editor: () => trans('media.job_editor'),
    Casting: () => trans('media.job_casting'),
    'Production Design': () => trans('media.job_production_design'),
    'Costume Design': () => trans('media.job_costume_design'),
};

/** A word in the reader's language where `labels` names it; else as the provider wrote it. */
function labelled(labels: Readonly<Record<string, (() => string) | undefined>>, word: string): string {
    return Object.hasOwn(labels, word) ? (labels[word]?.() ?? word) : word;
}

/** A crew job in the reader's language; one not named here as the provider wrote it. */
export function jobLabel(job: string): string {
    return labelled(JOB_LABELS, job);
}

/** Every job a crew credit holds. */
export function jobsOf(credit: CreditData): string[] {
    return credit.roles.length ? credit.roles.map((role) => role.role).filter((role): role is string => !!role) : credit.role ? [credit.role] : [];
}

/** Directed it — the job itself, not the assistants and supervisors of the department. */
export function isDirector(credit: CreditData): boolean {
    return credit.department === 'directing' && jobsOf(credit).includes(DIRECTOR);
}

/** Wrote the film's own screenplay, not only the source it adapts. */
export function wroteScreenplay(credit: CreditData): boolean {
    return credit.department === 'writing' && jobsOf(credit).some((job) => !SOURCE_JOBS.has(job));
}

/** One person of the key crew with every job they held, translated. */
export interface KeyCrewPerson {
    name: string;
    ref: string;
    provider: string;
    kind: 'person';
    roles: string[];
}

/** Credits merged by person, each with every job they held, worded by `label`. */
function byPerson(credits: CreditData[], label: (job: string) => string): KeyCrewPerson[] {
    const byRef = new Map<string, KeyCrewPerson>();

    for (const credit of credits) {
        const person = byRef.get(credit.personRef) ?? {
            name: credit.name,
            ref: credit.personRef,
            provider: credit.provider,
            kind: 'person' as const,
            roles: [],
        };

        for (const job of jobsOf(credit).map(label)) {
            if (!person.roles.includes(job)) {
                person.roles.push(job);
            }
        }

        byRef.set(credit.personRef, person);
    }

    return [...byRef.values()];
}

/**
 * Directors and writers, merged by person, each with their real job(s):
 * source creators (Characters, Comic Book…) are not flattened into "Writer"
 * but appear with their own role beside the screenwriters.
 */
export function keyCrew(crew: CreditData[]): KeyCrewPerson[] {
    return byPerson(
        crew.filter((c) => isDirector(c) || c.department === 'writing'),
        jobLabel,
    );
}

// A book's parts beside its authors, by the server's `BookRole`; their own
// keys, since a book's editor edits the text, not the film.
const BOOK_ROLE_LABELS: Record<BookRole, () => string> = {
    translator: () => trans('media.book_role_translator'),
    illustrator: () => trans('media.book_role_illustrator'),
    narrator: () => trans('media.book_role_narrator'),
    cover_artist: () => trans('media.book_role_cover_artist'),
    editor: () => trans('media.book_role_editor'),
    foreword: () => trans('media.book_role_foreword'),
    afterword: () => trans('media.book_role_afterword'),
    adaptation: () => trans('media.book_role_adaptation'),
    original_author: () => trans('media.book_role_original_author'),
};

/** A part in a book in the reader's language; one the server has no `BookRole` for as the provider wrote it. */
export function bookRoleLabel(role: string): string {
    return labelled(BOOK_ROLE_LABELS, role);
}

/** Everyone credited on a book beside its authors, merged by person, each with every part they had. */
export function bookContributors(crew: CreditData[]): KeyCrewPerson[] {
    return byPerson(crew, bookRoleLabel);
}
