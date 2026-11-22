import ClampedText from '@/components/ClampedText.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function render(overview: string | null) {
    return mount(OverviewSection, { props: { overview }, global: { stubs: { CreditHighlights: true } } });
}

describe('OverviewSection', () => {
    it('clamps the synopsis, keeping the paragraphs the provider wrote', () => {
        const clamped = render('First paragraph.\n\nSecond paragraph.').findComponent(ClampedText);

        expect(clamped.props('text')).toBe('First paragraph.\n\nSecond paragraph.');
        expect(clamped.find('p').classes()).toEqual(expect.arrayContaining(['line-clamp-6', 'whitespace-pre-line']));
    });

    it('says so when the provider has no synopsis', () => {
        expect(render(null).findComponent(ClampedText).props('text')).toBe('No overview available.');
    });
});
