import AdaptationSources from '@/components/media/AdaptationSources.vue';
import { mediaSummary } from '@/test/factories';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function sources(list: ReturnType<typeof mediaSummary>[] | null) {
    return shallowMount(AdaptationSources, {
        props: { data: 'sourceBooks', sources: list, title: 'Based on the book' },
        global: { stubs: { Deferred: { template: '<div><slot /></div>' }, MediaGrid: { template: '<div><slot /></div>' } } },
    });
}

describe('AdaptationSources', () => {
    it('names and lists the sources once they arrive', () => {
        const wrapper = sources([mediaSummary({ type: 'book', ref: '1' })]);

        expect(wrapper.findComponent({ name: 'SectionHeading' }).props('title')).toBe('Based on the book');
        expect(wrapper.findAllComponents({ name: 'MediaCard' })).toHaveLength(1);
    });

    it('adds no section for a title based on nothing', () => {
        expect(sources([]).find('section').exists()).toBe(false);
        expect(sources(null).find('section').exists()).toBe(false);
    });
});
