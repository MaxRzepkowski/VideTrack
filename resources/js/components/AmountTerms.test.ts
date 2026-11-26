import AmountTerms from '@/components/AmountTerms.vue';
import { durationTerms } from '@/lib/format';
import { amountTerms } from '@/lib/progress';
import { useMediaTypes } from '@/test/factories';
import { mount } from '@vue/test-utils';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { describe, expect, it } from 'vitest';

describe('AmountTerms', () => {
    it('sets the number apart and spaces the terms the way English writes them', () => {
        const wrapper = mount(AmountTerms, { props: { terms: durationTerms(3 * 1440 + 7 * 60), valueClass: 'font-bold', wordClass: 'word' } });

        expect(wrapper.findAll('.font-bold').map((value) => value.text())).toEqual(['3', '7']);
        expect(wrapper.classes()).toContain('gap-x-2');
        expect(wrapper.findAll('.word').every((word) => word.classes().includes('ml-1'))).toBe(true);
    });

    it('leaves out the spaces a language does not write', async () => {
        await loadLanguageAsync('ja');

        try {
            const wrapper = mount(AmountTerms, { props: { terms: durationTerms(3 * 1440 + 7 * 60), wordClass: 'word' } });

            expect(wrapper.text()).toBe('3日7時間');
            expect(wrapper.classes()).not.toContain('gap-x-2');
            expect(wrapper.findAll('.word').some((word) => word.classes().includes('ml-1'))).toBe(false);
        } finally {
            await loadLanguageAsync('en');
        }
    });

    it('puts words before the number where the language does, each side spaced as it writes it', async () => {
        useMediaTypes();
        await loadLanguageAsync('ko');

        try {
            const term = mount(AmountTerms, { props: { terms: amountTerms('manga', 320), valueClass: 'value' } }).find(':scope > span');
            const [before, value, after] = term.findAll('span');

            expect([before?.text(), value?.text(), after?.text()]).toEqual(['챕터', '320', '개']);
            expect(before?.classes()).toContain('mr-1');
            expect(after?.classes()).not.toContain('ml-1');
        } finally {
            await loadLanguageAsync('en');
        }
    });
});
