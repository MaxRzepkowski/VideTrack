import { serviceIcon, serviceLogo } from '@/lib/serviceLogos';
import manifest from '@/lib/serviceLogos.json';
import { describe, expect, it } from 'vitest';

describe('serviceLogos', () => {
    it('serves a kind’s logo from the logos folder with the proportions the list measured', () => {
        expect(serviceLogo('discord')).toEqual({
            url: `/images/logos/${manifest.discord.file}`,
            width: manifest.discord.width,
            height: manifest.discord.height,
        });
        expect(serviceLogo('carrier-pigeon')).toBeNull();
    });

    it('names where every logo came from and on what terms', () => {
        for (const entry of Object.values(manifest)) {
            expect(entry.source.trim()).not.toBe('');
            expect(entry.licence.trim()).not.toBe('');
        }
    });

    it('measures every logo it lists', () => {
        for (const entry of Object.values(manifest)) {
            expect(entry.width).toBeGreaterThan(0);
            expect(entry.height).toBeGreaterThan(0);
        }
    });

    it('gives what has no brand a plain icon instead of a logo', () => {
        expect(serviceIcon('mail')).not.toBeNull();
        expect(serviceIcon('tgram')).toBeNull();
        expect(serviceLogo('mail')).toBeNull();
    });
});
