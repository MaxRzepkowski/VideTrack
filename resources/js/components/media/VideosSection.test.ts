import VideosSection from '@/components/media/VideosSection.vue';
import type { VideoData } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function video(overrides: Partial<VideoData>): VideoData {
    return { key: 'k', site: 'YouTube', type: 'Trailer', name: 'Trailer', official: true, publishedAt: null, ...overrides };
}

const titles = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('img').map((image) => image.attributes('alt'));

describe('VideosSection', () => {
    it('offers the kinds present, in offer order, named in the reader’s language', () => {
        const wrapper = mount(VideosSection, {
            props: { videos: [video({ key: 'a', type: 'Featurette' }), video({ key: 'c', type: 'Teaser' })] },
        });

        expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['Teaser', 'Featurette']);
    });

    it('shows the first kind on offer, newest first, the undated last', () => {
        const wrapper = mount(VideosSection, {
            props: {
                videos: [
                    video({ key: 'old', name: 'Old', publishedAt: '2015-01-01' }),
                    video({ key: 'undated', name: 'Undated' }),
                    video({ key: 'new', name: 'New', publishedAt: '2024-05-01' }),
                    video({ key: 'clip', name: 'Clip', type: 'Clip', publishedAt: '2025-01-01' }),
                ],
            },
        });

        expect(titles(wrapper)).toEqual(['New', 'Old', 'Undated']);
    });

    it('switches to another kind from the select', async () => {
        const wrapper = mount(VideosSection, {
            props: { videos: [video({ key: 't', name: 'The trailer' }), video({ key: 'c', name: 'A clip', type: 'Clip' })] },
        });

        await wrapper.find('select').setValue('Clip');

        expect(titles(wrapper)).toEqual(['A clip']);
    });

    it('renders nothing for a title without videos', () => {
        const wrapper = mount(VideosSection, { props: { videos: [] } });

        expect(wrapper.find('section').exists()).toBe(false);
    });
});
