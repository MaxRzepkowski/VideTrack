import MediaCard from '@/components/media/MediaCard.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { mediaSummary } from '@/test/factories';
import type { TitleTie } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function section(titles: TitleTie[]) {
    return mount(RelatedTitles, { props: { titles }, global: { stubs: { MediaCard: true } } });
}

const fellow = (ref: number): TitleTie => ({ relation: 'same_universe', label: null, media: mediaSummary({ ref: String(ref) }) });

describe('the titles of the same universe', () => {
    it('says how a title stands to this one where that is known, and nothing where it only shares the universe', () => {
        const wrapper = section([{ relation: 'spin_off', label: 'Spin-off', media: mediaSummary({ type: 'show', ref: '60059' }) }, fellow(1)]);

        expect(wrapper.find('h2').text()).toBe('From the same universe');
        expect(wrapper.findAllComponents(MediaCard).map((card) => card.props('subtitle'))).toEqual(['Spin-off', null]);
        expect(wrapper.findComponent(ShowMoreToggle).exists()).toBe(false);
    });

    it('opens two rows deep and unfolds the rest on demand', async () => {
        const wrapper = section(Array.from({ length: 30 }, (_, index) => fellow(index)));
        const shownAtFirst = wrapper.findAllComponents(MediaCard).length;

        expect(shownAtFirst).toBeLessThan(30);
        expect(wrapper.findComponent(ShowMoreToggle).props('hiddenCount')).toBe(30 - shownAtFirst);

        await wrapper.findComponent(ShowMoreToggle).find('button').trigger('click');

        expect(wrapper.findAllComponents(MediaCard)).toHaveLength(30);
    });

    it('is not there at all for a title with nothing tied to it', () => {
        expect(section([]).find('section').exists()).toBe(false);
    });
});
