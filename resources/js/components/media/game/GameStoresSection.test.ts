import GameStoresSection from '@/components/media/game/GameStoresSection.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('GameStoresSection', () => {
    it('lists the stores that sell it, a subscription behind its own choice, each opening the game there', async () => {
        const wrapper = mount(GameStoresSection, {
            props: {
                stores: [
                    { store: 'steam', url: 'https://store.steampowered.com/app/1', subscription: false },
                    { store: 'xbox', url: 'https://www.xbox.com/games/x', subscription: false },
                    { store: 'gamepass', url: 'https://www.xbox.com/play/x', subscription: true },
                    { store: 'gog', url: 'javascript:alert(1)', subscription: false },
                ],
            },
        });

        expect(wrapper.findAll('a').map((link) => [link.text(), link.attributes('href')])).toEqual([
            ['Steam', 'https://store.steampowered.com/app/1'],
            ['Xbox', 'https://www.xbox.com/games/x'],
        ]);
        expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['Buy', 'In a subscription']);

        await wrapper.find('select').setValue('subscription');
        expect(wrapper.findAll('a').map((link) => link.text())).toEqual(['Game Pass']);
    });

    it('needs no choice with one kind and no card with none', () => {
        expect(
            mount(GameStoresSection, { props: { stores: [{ store: 'steam', url: 'https://store.steampowered.com/app/1', subscription: false }] } })
                .find('select')
                .exists(),
        ).toBe(false);
        expect(
            mount(GameStoresSection, { props: { stores: [] } })
                .find('section')
                .exists(),
        ).toBe(false);
    });
});
