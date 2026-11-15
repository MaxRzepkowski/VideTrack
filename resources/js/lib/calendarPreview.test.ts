import { eventPreview, releasePreview } from '@/lib/calendarPreview';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { CalendarEntry } from '@/types/media';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

const episode = (number: number): CalendarEntry => ({
    media: mediaSummary({
        type: 'episode',
        ref: `10:1:${number}`,
        number,
        title: `Chapter ${number}`,
        extra: { seasonNumber: 1, showPoster: '/poster.jpg' },
    }),
    showTitle: 'Severance',
});

describe('releasePreview', () => {
    it("shows an episode under its show's poster, with its code and title", () => {
        const preview = releasePreview('2026-10-14', { key: 'show:10', entries: [episode(4)] });

        expect(preview).toMatchObject({ title: 'Severance', badge: { type: 'show' }, context: 'S01E04 – Chapter 4', note: null });
        expect(preview.image).toMatchObject({ shape: 'poster' });
        expect(preview.image.src).toContain('/poster.jpg');
        expect(preview.airs).toBeUndefined();
    });

    it("counts a show's episodes of the day", () => {
        expect(releasePreview('2026-10-14', { key: 'show:10', entries: [episode(1), episode(2)] }).context).toBe('2 episodes');
    });
});

describe('eventPreview', () => {
    const event = {
        provider: 'igdb',
        ref: '7',
        name: 'Showcase',
        startsAt: '2026-10-14T18:00:00Z',
        endsAt: '2026-10-14T20:00:00Z',
        liveStreamUrl: null,
        logoPath: 'https://images.example.com/logo.png',
    };

    it('wears the medium it shows, its wide art, its hours and its span', () => {
        const preview = eventPreview({ event, mediumType: 'game' });

        expect(preview).toMatchObject({
            href: '/events.show/igdb/7',
            title: 'Showcase',
            badge: { type: 'game', label: 'Event' },
            context: null,
            airs: event,
        });
        expect(preview.image).toEqual({ src: 'https://images.example.com/logo.png', shape: 'wide', fallback: 'var(--color-media-game)' });
        expect(preview.time).toMatch(/^.+–.+$/);
    });
});
