import LiveEventsButton from '@/components/LiveEventsButton.vue';
import { vTooltip } from '@/directives/tooltip';
import { setPageProps } from '@/test/page';
import type { EventEntry } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

// The clock is frozen at 2026-10-10T12:00Z.
const event = (ref: string, startsAt: string, endsAt: string): EventEntry => ({
    provider: 'igdb',
    ref,
    name: `Show ${ref}`,
    startsAt,
    endsAt,
    liveStreamUrl: `https://www.youtube.com/watch?v=${ref}`,
    logoPath: null,
});

function button(liveEvents: EventEntry[]) {
    setPageProps({ liveEvents });

    return mount(LiveEventsButton, { global: { stubs: { Link }, directives: { tooltip: vTooltip } } });
}

afterEach(() => window.localStorage.clear());

describe('LiveEventsButton', () => {
    it('shows the event on the air with its page and its stream, and nothing for one still ahead', () => {
        const wrapper = button([
            event('1', '2026-10-10T11:00:00Z', '2026-10-10T13:00:00Z'),
            event('2', '2026-10-10T18:00:00Z', '2026-10-10T20:00:00Z'),
        ]);
        const links = wrapper.findAll('a');

        expect(wrapper.text()).toContain('Live');
        expect(wrapper.text()).toContain('Show 1');
        expect(wrapper.text()).not.toContain('Show 2');
        expect(links.at(0)?.attributes('href')).toBe('/events.show/igdb/1');
        expect(links.at(1)?.attributes('href')).toBe('https://www.youtube.com/watch?v=1');
        expect(links.at(1)?.attributes('aria-label')).toBe('Watch the stream');
    });

    it('folds several into one button with the list behind it, and puts one away for good', async () => {
        const wrapper = button([
            event('1', '2026-10-10T11:00:00Z', '2026-10-10T13:00:00Z'),
            event('3', '2026-10-10T11:30:00Z', '2026-10-10T12:30:00Z'),
        ]);

        expect(wrapper.find('button').text()).toContain('Live: 2');

        await wrapper.find('button').trigger('click');
        await wrapper.find('button[aria-label]').trigger('click');

        expect(wrapper.text()).toContain('Show 3');
        expect(wrapper.text()).not.toContain('Show 1');
        expect(button([event('1', '2026-10-10T11:00:00Z', '2026-10-10T13:00:00Z')]).text()).toBe('');
    });
});
