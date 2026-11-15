import PhotosSection from '@/components/media/PhotosSection.vue';
import { vTooltip } from '@/directives/tooltip';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

beforeEach(() => {
    vi.stubGlobal(
        'Image',
        class {
            src = '';
        },
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

function lightboxImages(): string[] {
    return [...document.querySelectorAll<HTMLImageElement>('[role="dialog"] img')].map((image) => image.getAttribute('src') ?? '');
}

describe('the photo lightbox', () => {
    it("opens on the tile's own size under the full image, and steps to the next photo", async () => {
        const wrapper = mount(PhotosSection, {
            props: { backdrops: ['/a.jpg', '/b.jpg'], posters: [] },
            global: { directives: { tooltip: vTooltip } },
            attachTo: document.body,
        });

        await wrapper.findAll('button')[0]?.trigger('click');
        await flushPromises();

        expect(lightboxImages()).toEqual(['https://image.tmdb.org/t/p/w780/a.jpg', 'https://image.tmdb.org/t/p/original/a.jpg']);
        // Shown 1280 px wide, however large the original file.
        expect(document.querySelector('[role="dialog"] img:last-child')?.getAttribute('width')).toBe('1280');

        [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')]
            .find((button) => button.getAttribute('aria-label') === 'Next')
            ?.click();
        await flushPromises();

        expect(lightboxImages()).toEqual(['https://image.tmdb.org/t/p/w780/b.jpg', 'https://image.tmdb.org/t/p/original/b.jpg']);

        wrapper.unmount();
    });

    describe('the previews', () => {
        const tiles = (backdrops: number, posters: number) =>
            mount(PhotosSection, {
                props: {
                    backdrops: Array.from({ length: backdrops }, (_, n) => `/b${n}.jpg`),
                    posters: Array.from({ length: posters }, (_, n) => `/p${n}.jpg`),
                },
                global: { directives: { tooltip: vTooltip } },
            })
                .findAll('section > div > button')
                .map((tile) => (tile.find('img').exists() ? 'photo' : tile.text()));

        it('end on the "more" tile closing the row, not a tile left alone under it', () => {
            expect(tiles(4, 3)).toEqual(['photo', 'photo', 'photo', '+4']);
            expect(tiles(6, 0)).toEqual(['photo', 'photo', 'photo', 'photo', 'photo', 'photo']);
            expect(tiles(30, 10)).toEqual([...Array(7).fill('photo'), '+33']);
        });
    });
});
