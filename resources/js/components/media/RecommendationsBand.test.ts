import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import { mediaSummary } from '@/test/factories';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const titles = (count: number) => Array.from({ length: count }, (_, i) => mediaSummary({ ref: String(i), title: `T${i}` }));

function band(props: { related: ReturnType<typeof titles>; similar?: ReturnType<typeof titles> }) {
    return shallowMount(RecommendationsBand, {
        props: { relatedTitle: 'Related', similarTitle: 'Similar', ...props },
        global: { stubs: { PageSection: false } },
    });
}

describe('RecommendationsBand', () => {
    it('is not drawn when there is nothing to recommend', () => {
        expect(band({ related: [] }).html()).not.toContain('section');
    });

    it('heads each list it has, related first', () => {
        const wrapper = band({ related: titles(2), similar: titles(1) });
        const headings = wrapper.findAllComponents({ name: 'SectionHeading' }).map((heading) => heading.props('title'));

        expect(headings).toEqual(['Related', 'Similar']);
    });

    it('shows similar titles alone without a gap above them', () => {
        const wrapper = band({ related: [], similar: titles(1) });

        expect(wrapper.find('section').classes()).not.toContain('mt-10');
    });
});
