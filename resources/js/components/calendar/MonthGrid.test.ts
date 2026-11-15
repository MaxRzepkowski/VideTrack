import MonthGrid from '@/components/calendar/MonthGrid.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { CalendarMonth } from '@/types/media';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

const month: CalendarMonth = {
    month: '2026-10-01',
    start: '2026-10-01',
    end: '2026-10-31',
    days: [
        {
            date: '2026-10-14',
            entries: [{ media: mediaSummary({ type: 'movie', ref: '603', title: 'Premiere' }), showTitle: null }],
            events: [
                {
                    event: {
                        provider: 'igdb',
                        ref: '7',
                        name: 'Showcase',
                        startsAt: '2026-10-14T18:00:00Z',
                        endsAt: '2026-10-14T20:00:00Z',
                        liveStreamUrl: null,
                        logoPath: null,
                    },
                    mediumType: 'game',
                },
            ],
        },
    ],
};

/** A screen that hovers (a desktop) or one that is a phone: which of the grid's two queries match. */
function screen(kind: 'desktop' | 'phone'): void {
    vi.stubGlobal('matchMedia', (query: string) => ({
        matches: kind === 'desktop' ? query.includes('hover: hover') : query.includes('max-width'),
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    }));
}

const grid = () =>
    mount(MonthGrid, {
        props: { grid: month, today: '2026-10-10', label: 'October' },
        global: { directives: { tooltip: vTooltip }, stubs: { Link, teleport: true } },
    });

const card = (wrapper: ReturnType<typeof grid>, href: string) => wrapper.find(`a[href="${href}"]`);
const previewText = (wrapper: ReturnType<typeof grid>) => wrapper.find('aside').text();

beforeEach(() => useMediaTypes());
afterEach(() => vi.unstubAllGlobals());

describe('MonthGrid preview', () => {
    it('opens on hover, for an event as for a release', async () => {
        screen('desktop');
        const wrapper = grid();

        await card(wrapper, '/events.show/igdb/7').trigger('mouseenter');
        expect(previewText(wrapper)).toContain('Showcase');
        expect(previewText(wrapper)).toContain('Event');

        const release = wrapper.findAll('a').find((link) => link.text().includes('Premiere'));

        expect(release).toBeDefined();
        await release?.trigger('mouseenter');
        expect(previewText(wrapper)).toContain('Premiere');
    });

    it('opens on a first tap on a phone, and lets the second one through to the event', async () => {
        screen('phone');
        const wrapper = grid();
        const event = card(wrapper, '/events.show/igdb/7');

        const first = new MouseEvent('click', { bubbles: true, cancelable: true });
        event.element.dispatchEvent(first);
        await wrapper.vm.$nextTick();

        expect(first.defaultPrevented).toBe(true);
        expect(previewText(wrapper)).toContain('Showcase');

        const second = new MouseEvent('click', { bubbles: true, cancelable: true });
        event.element.dispatchEvent(second);

        expect(second.defaultPrevented).toBe(false);
    });
});
