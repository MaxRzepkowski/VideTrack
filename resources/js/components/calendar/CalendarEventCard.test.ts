import CalendarEventCard from '@/components/calendar/CalendarEventCard.vue';
import type { CalendarEvent } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

// The clock is frozen at 2026-10-10T12:00Z.
const card = (startsAt: string, endsAt: string) =>
    mount(CalendarEventCard, {
        props: {
            entry: {
                event: { provider: 'igdb', ref: '7', name: 'Showcase', startsAt, endsAt, liveStreamUrl: null, logoPath: null },
                mediumType: 'game',
            } satisfies CalendarEvent,
        },
        global: { stubs: { Link } },
    });

describe('CalendarEventCard', () => {
    it('says what it is and gives its hour way to the live mark while the show is on the air', () => {
        const ahead = card('2026-10-10T18:00:00Z', '2026-10-10T19:00:00Z');

        expect(ahead.text()).toContain('Event');
        expect(ahead.text()).not.toContain('Live');
        expect(ahead.find('a').attributes('href')).toBe('/events.show/igdb/7');
        expect(card('2026-10-10T11:30:00Z', '2026-10-10T12:30:00Z').text()).toContain('Live');
    });

    it("wears its glyph in the colour of the medium it shows, as a release card wears its type's", () => {
        expect(card('2026-10-10T18:00:00Z', '2026-10-10T19:00:00Z').find('svg').attributes('style')).toContain('var(--color-media-game)');
    });
});
