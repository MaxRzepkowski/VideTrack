import ExternalLinks from '@/components/media/ExternalLinks.vue';
import { vTooltip } from '@/directives/tooltip';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const mountLinks = (links: Record<string, string>) => mount(ExternalLinks, { props: { links }, global: { directives: { tooltip: vTooltip } } });

describe('ExternalLinks', () => {
    it('opens each service in a new tab, a brand glyph where one exists', () => {
        const wrapper = mountLinks({
            imdb: 'https://www.imdb.example/title/tt1',
            homepage: 'https://film.example',
            xbox: 'https://xbox.example/game',
        });
        const anchors = wrapper.findAll('a');

        expect(anchors.map((anchor) => anchor.attributes('href'))).toEqual([
            'https://www.imdb.example/title/tt1',
            'https://film.example',
            'https://xbox.example/game',
        ]);
        expect(anchors.every((anchor) => anchor.attributes('target') === '_blank' && anchor.attributes('rel') === 'noopener noreferrer')).toBe(true);
        expect(wrapper.find('a svg path').exists()).toBe(true);
    });

    it('drops a link whose address is not a web address', () => {
        const wrapper = mountLinks({ imdb: 'javascript:alert(1)', tmdb: 'https://tmdb.example/movie/603' });

        expect(wrapper.findAll('a').map((anchor) => anchor.attributes('href'))).toEqual(['https://tmdb.example/movie/603']);
    });
});
