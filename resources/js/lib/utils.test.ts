import { cn } from '@/lib/utils';
import { describe, expect, it } from 'vitest';

describe('cn', () => {
    it('joins conditional classes and lets the later Tailwind utility win', () => {
        expect(cn('text-ink px-2', false && 'hidden', { 'font-bold': true }, 'px-4')).toBe('text-ink font-bold px-4');
    });
});
