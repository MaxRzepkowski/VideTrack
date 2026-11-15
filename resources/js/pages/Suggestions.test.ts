import Suggestions from '@/pages/Suggestions.vue';
import { setPageProps } from '@/test/page';
import type { SuggestionShelf } from '@/types/suggestions';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    setPageProps({});
});

function page(shelves: SuggestionShelf[]) {
    return mount(Suggestions, {
        props: { shelves },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true } },
    });
}

describe('the suggestions page', () => {
    it('lists each kind with its rule and count, as the way to its titles', () => {
        const wrapper = page([
            {
                key: 'released-since-added',
                title: 'Out since you added them',
                description: 'Titles you added before their release.',
                total: 12,
                posters: ['/a.jpg', '/b.jpg'],
            },
        ]);

        const tile = wrapper.find('a[href="/suggestions.show/released-since-added"]');

        expect(tile.find('h2').text()).toBe('Out since you added them');
        expect(tile.text()).toContain('Titles you added before their release.');
        expect(tile.text()).toContain('12 items');
        expect(tile.findAll('img')).toHaveLength(2);
    });

    it('explains where suggestions come from when there are none', () => {
        expect(page([]).text()).toContain('Nothing to suggest yet.');
    });
});
