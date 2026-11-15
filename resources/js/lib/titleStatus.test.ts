import { titleStatusLabel, type TitleStatus } from '@/lib/titleStatus';
import { describe, expect, it } from 'vitest';

const ALL: TitleStatus[] = [
    'rumored',
    'planned',
    'in_production',
    'post_production',
    'pilot',
    'alpha',
    'beta',
    'early_access',
    'not_yet_published',
    'released',
    'returning_series',
    'publishing',
    'on_hiatus',
    'ended',
    'finished',
    'canceled',
    'discontinued',
    'offline',
    'delisted',
];

describe('titleStatusLabel', () => {
    it('names every status in words, never as its key', () => {
        for (const status of ALL) {
            expect(titleStatusLabel(status)).not.toContain('status.');
        }

        expect(titleStatusLabel('returning_series')).toBe('Returning series');
    });

    it('has nothing to say without a status', () => {
        expect(titleStatusLabel(null)).toBeNull();
    });
});
