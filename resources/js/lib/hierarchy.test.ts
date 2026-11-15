import { placeOf, showRefOf } from '@/lib/hierarchy';
import { describe, expect, it } from 'vitest';

describe('hierarchy refs', () => {
    it('reads a show, a season and an episode out of their refs', () => {
        expect(placeOf('1399')).toEqual({ show: '1399', season: null, episode: null });
        expect(placeOf('1399:2')).toEqual({ show: '1399', season: '2', episode: null });
        expect(placeOf('1399:2:9')).toEqual({ show: '1399', season: '2', episode: '9' });
        expect(showRefOf('1399:2:9')).toBe('1399');
    });
});
