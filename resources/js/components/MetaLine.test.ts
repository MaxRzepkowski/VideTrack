import MetaLine from '@/components/MetaLine.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const BREAK = '\u200B';

describe('MetaLine', () => {
    it('holds each rule on one line with the word before it, so no wrapped line opens with one', () => {
        const wrapper = mount(MetaLine, { props: { parts: ['January 1 – December 31', null, 'about every other day', ''], wrap: true } });
        const held = wrapper.findAll('span.whitespace-nowrap');

        // Two facts left once the empty ones drop out: one rule, after the first.
        expect(held).toHaveLength(1);
        expect(held[0]?.text()).toBe('31');
        expect(held[0]?.find('[aria-hidden="true"]').exists()).toBe(true);
        expect(wrapper.text()).toBe(`January 1 – December 31${BREAK}about every other day`);
    });

    it('sets the leading parts brighter', () => {
        const wrapper = mount(MetaLine, { props: { parts: ['8 books', '1,355 pages', '5min a day on average'], wrap: true, lead: 2 } });
        const bright = wrapper.findAll('.font-semibold').map((part) => part.text());

        expect(bright).toEqual(['8 books', '1,355 pages']);
    });

    it('keeps the facts on one line, set apart by rules, when it does not wrap', () => {
        const wrapper = mount(MetaLine, { props: { parts: ['S02E04', 'Pilot'] } });

        expect(wrapper.findAll('[aria-hidden="true"]')).toHaveLength(1);
        expect(wrapper.text()).toMatch(/S02E04\s*Pilot/);
    });
});
