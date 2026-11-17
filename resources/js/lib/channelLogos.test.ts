import { channelIcon, channelLogo } from '@/lib/channelLogos';
import manifest from '@/lib/channelLogos.json';
import { describe, expect, it } from 'vitest';

describe('channelLogos', () => {
    it('serves a kind’s logo from the channels folder with the proportions the list measured', () => {
        expect(channelLogo('discord')).toEqual({
            url: `/images/channels/${manifest.discord.file}`,
            width: manifest.discord.width,
            height: manifest.discord.height,
        });
        expect(channelLogo('carrier-pigeon')).toBeNull();
    });

    it('measures every logo it lists', () => {
        for (const entry of Object.values(manifest)) {
            expect(entry.width).toBeGreaterThan(0);
            expect(entry.height).toBeGreaterThan(0);
        }
    });

    it('gives what has no brand a plain icon instead of a logo', () => {
        expect(channelIcon('mail')).not.toBeNull();
        expect(channelIcon('tgram')).toBeNull();
        expect(channelLogo('mail')).toBeNull();
    });
});
