import { showTypeLabel } from '@/lib/showTypes';
import { describe, expect, it } from 'vitest';

describe('showTypeLabel', () => {
    it('names a known kind, passes a new one through, and nothing for none', () => {
        expect(showTypeLabel('Talk Show')).toBe('Talk show');
        expect(showTypeLabel('Hologram')).toBe('Hologram');
        expect(showTypeLabel('')).toBeNull();
        expect(showTypeLabel(null)).toBeNull();
    });
});
