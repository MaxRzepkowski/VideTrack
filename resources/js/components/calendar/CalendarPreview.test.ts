import CalendarPreview from '@/components/calendar/CalendarPreview.vue';
import type { CalendarPreview as Preview } from '@/lib/calendarPreview';
import { useMediaTypes } from '@/test/factories';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

beforeEach(() => useMediaTypes());

const preview = (overrides: Partial<Preview> = {}): Preview => ({
    href: '/somewhere',
    title: 'Showcase',
    badge: { type: 'game' },
    image: { src: 'https://images.example.com/art.png', shape: 'wide', fallback: 'var(--color-media-game)' },
    context: null,
    date: 'Wednesday, 14 October',
    time: '18:00–20:00',
    note: null,
    ...overrides,
});

const box = (content: Preview) => mount(CalendarPreview, { props: { preview: content }, global: { stubs: { Link } } });

// The clock is frozen at 2026-10-10T12:00Z.
describe('CalendarPreview', () => {
    it('spans wide art above the text, and stands a poster beside it', () => {
        expect(box(preview()).find('img').attributes()).toMatchObject({ width: '889', height: '500' });
        expect(
            box(preview({ image: { src: '/poster.jpg', shape: 'poster', fallback: '' } }))
                .find('img')
                .attributes(),
        ).toMatchObject({
            width: '342',
            height: '513',
        });
    });

    it('marks an event live only while it is on the air', () => {
        expect(box(preview({ airs: { startsAt: '2026-10-10T11:30:00Z', endsAt: '2026-10-10T12:30:00Z' } })).text()).toContain('Live');
        expect(box(preview({ airs: { startsAt: '2026-10-14T18:00:00Z', endsAt: '2026-10-14T20:00:00Z' } })).text()).not.toContain('Live');
    });
});
