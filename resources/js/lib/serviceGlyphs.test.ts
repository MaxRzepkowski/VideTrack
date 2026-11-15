import { glyphFor } from '@/lib/serviceGlyphs';
import { describe, expect, it } from 'vitest';

describe('glyphFor', () => {
    it('gives the brand path of a known service', () => {
        expect(glyphFor('plex')).toMatch(/^M/);
        expect(glyphFor('jellyfin')).not.toBe(glyphFor('plex'));
    });

    it('has nothing for a service without a mark, so the caller falls back', () => {
        expect(glyphFor('nuvio')).toBeNull();
    });
});
