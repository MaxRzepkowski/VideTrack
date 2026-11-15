import { safeUrl } from '@/lib/url';
import { describe, expect, it } from 'vitest';

describe('safeUrl', () => {
    it('lets http and https through as they are', () => {
        expect(safeUrl('https://example.com/a?b=1')).toBe('https://example.com/a?b=1');
        expect(safeUrl('HTTP://example.com')).toBe('HTTP://example.com');
        expect(safeUrl('  https://example.com')).toBe('  https://example.com');
    });

    it('drops anything that could run in our origin or is empty', () => {
        expect(safeUrl('javascript:alert(1)')).toBeNull();
        expect(safeUrl('data:text/html,hi')).toBeNull();
        expect(safeUrl('//example.com')).toBeNull();
        expect(safeUrl('')).toBeNull();
        expect(safeUrl(null)).toBeNull();
        expect(safeUrl(undefined)).toBeNull();
    });
});
