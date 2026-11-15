import NeighbourArrow from '@/components/media/NeighbourArrow.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function arrow(props: { direction: 'previous' | 'next'; title: string; code?: string | null }) {
    return mount(NeighbourArrow, {
        props: { href: '/season/1/2', ...props },
        global: { stubs: { Link: { props: ['href', 'title'], template: '<a :href="href" :title="title"><slot /></a>' } } },
    });
}

describe('NeighbourArrow', () => {
    it('names the neighbour under its direction', () => {
        const wrapper = arrow({ direction: 'previous', title: 'Season 1' });

        expect(wrapper.text()).toContain('Previous');
        expect(wrapper.text()).toContain('Season 1');
        expect(wrapper.find('a').attributes('title')).toBe('Season 1');
        expect(wrapper.find('a').classes()).toContain('left-3');
    });

    it('adds an episode code to the caption and the hint, and points right when next', () => {
        const wrapper = arrow({ direction: 'next', title: 'The Return', code: 'S02E05' });

        expect(wrapper.text()).toContain('Next');
        expect(wrapper.text()).toContain('S02E05');
        expect(wrapper.find('a').attributes('title')).toBe('S02E05 – The Return');
        expect(wrapper.find('a').classes()).toContain('right-3');
    });
});
