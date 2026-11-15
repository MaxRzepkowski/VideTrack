import GameLanguagesSection from '@/components/media/game/GameLanguagesSection.vue';
import type { LanguageSupport } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const speaks = (locale: string): LanguageSupport => ({ locale, audio: true, subtitles: true, interface: false });

describe('GameLanguagesSection', () => {
    it('tables how the game speaks each language, a long table folded', async () => {
        const wrapper = mount(GameLanguagesSection, {
            props: { languages: ['en-US', 'de-DE', 'fr-FR', 'it-IT', 'ja-JP', 'pl-PL', 'ko-KR'].map(speaks) },
        });

        expect(wrapper.findAll('thead th').map((cell) => cell.text())).toEqual(['Languages', 'Interface', 'Audio', 'Subtitles']);
        expect(wrapper.findAll('tbody tr')).toHaveLength(5);
        // A mark where the game speaks it, nothing where it does not.
        expect(
            wrapper
                .findAll('tbody tr')[0]!
                .findAll('td')
                .map((cell) => cell.text()),
        ).toEqual(['', 'Yes', 'Yes']);

        await wrapper.find('button').trigger('click');
        expect(wrapper.findAll('tbody tr')).toHaveLength(7);
    });

    it('has no section without languages', () => {
        expect(
            mount(GameLanguagesSection, { props: { languages: [] } })
                .find('section')
                .exists(),
        ).toBe(false);
    });
});
