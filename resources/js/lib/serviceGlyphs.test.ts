import { glyphFor } from '@/lib/serviceGlyphs';
import { describe, expect, it } from 'vitest';

describe('glyphFor', () => {
    it('gives the brand path of a known service', () => {
        expect(glyphFor('imdb')).toMatch(/^M/);
        expect(glyphFor('tmdb')).not.toBe(glyphFor('imdb'));
    });

    it('has nothing for a service without a mark, so the caller falls back', () => {
        expect(glyphFor('nuvio')).toBeNull();
    });
});
