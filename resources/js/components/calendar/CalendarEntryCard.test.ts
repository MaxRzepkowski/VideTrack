import CalendarEntryCard from '@/components/calendar/CalendarEntryCard.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes, userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { CalendarEntry } from '@/types/media';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

function card(entry: CalendarEntry) {
    return mount(CalendarEntryCard, {
        props: { entry, noImage: true },
        global: { stubs: { Link }, directives: { tooltip: vTooltip } },
    });
}

beforeEach(() => {
    useMediaTypes();
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences() } } });
});

describe('CalendarEntryCard', () => {
    it("badges a game's DLC and names the game it belongs to", () => {
        const wrapper = card({
            media: mediaSummary({
                type: 'game',
                ref: '5000',
                title: 'The Master Trials',
                extra: { gameType: 'dlc', relatedTo: 'Breath of the Wild', platform: 'Switch, PC' },
            }),
            showTitle: null,
        });

        expect(wrapper.text()).toContain('The Master Trials');
        expect(wrapper.text()).toContain('Breath of the Wild');
        expect(wrapper.text()).toContain('Switch, PC');
        expect(wrapper.text()).toContain('DLC');
        expect(wrapper.text()).not.toContain('Game release');
    });

    it('marks the episode opening a season', () => {
        const wrapper = card({
            media: mediaSummary({
                type: 'episode',
                ref: '1399:2:1',
                number: 1,
                title: 'Opening',
                extra: { seasonNumber: 2, episodeBadge: 'season_premiere' },
            }),
            showTitle: 'Severance',
        });

        expect(wrapper.text()).toContain('Severance');
        expect(wrapper.text()).toContain('S02E01');
        expect(wrapper.text()).toContain('Season premiere');
    });
});
