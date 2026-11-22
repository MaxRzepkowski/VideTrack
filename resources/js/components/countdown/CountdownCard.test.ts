import CountdownCard from '@/components/countdown/CountdownCard.vue';
import { mediaSummary, useMediaTypes, userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { CountdownEntry } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

function card(media: Partial<CountdownEntry['media']>) {
    return mount(CountdownCard, {
        props: { entry: { media: mediaSummary({ type: 'game', ref: '7', title: 'The Witcher IV', ...media }), showTitle: null, filterType: 'game' } },
        global: { stubs: { Link } },
    });
}

beforeEach(() => {
    useMediaTypes();
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences() } } });
});

describe('CountdownCard', () => {
    it('ticks down to an exact day', () => {
        const wrapper = card({ releaseDate: '2026-10-20' });

        expect(wrapper.text()).toContain('9');
        expect(wrapper.text()).toContain('Releases October 20, 2026');
        expect(wrapper.text()).not.toContain('Out now');
    });

    it('names a year or a quarter instead of counting down to the day that stands in for it', () => {
        const year = card({ releaseDate: '2028-12-31', extra: { releasePrecision: 'year' } });

        expect(year.text()).toContain('Releases 2028');
        expect(year.text()).not.toContain('December 31');
        expect(year.findAll('.tabular-nums')).toHaveLength(0);

        // A stand-in day already behind it is not "out": the quarter may still be running.
        const quarter = card({ releaseDate: '2026-10-01', extra: { releasePrecision: 'quarter' } });

        expect(quarter.text()).toContain('Q4 2026');
        expect(quarter.text()).not.toContain('Out now');
    });
});
