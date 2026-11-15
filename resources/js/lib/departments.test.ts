import { departmentLabel, isDepartment, type Department } from '@/lib/departments';
import { describe, expect, it } from 'vitest';

const ALL: Department[] = [
    'acting',
    'guest_star',
    'directing',
    'writing',
    'author',
    'production',
    'camera',
    'editing',
    'sound',
    'art',
    'costume_make_up',
    'visual_effects',
    'lighting',
    'crew',
];

describe('departments', () => {
    it('names every department in words, never as its key', () => {
        for (const department of ALL) {
            expect(departmentLabel(department)).not.toContain('department.');
        }

        expect(departmentLabel('directing')).toBe('Directing');
    });

    it('recognises a code from an untyped payload', () => {
        expect(isDepartment('writing')).toBe(true);
        expect(isDepartment('Writing')).toBe(false);
        expect(isDepartment(3)).toBe(false);
        expect(isDepartment(null)).toBe(false);
    });
});
