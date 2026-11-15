import TypeFilter from '@/components/TypeFilter.vue';
import { useMediaTypes } from '@/test/factories';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

function allOption(props: { options: { type: string; count?: number }[]; total?: number | null }): string {
    return mount(TypeFilter, { props: { modelValue: 'movie', ...props } })
        .find('option')
        .text();
}

describe('TypeFilter', () => {
    it('adds counted options up behind "All", whichever type is picked', () => {
        expect(
            allOption({
                options: [
                    { type: 'movie', count: 10 },
                    { type: 'show', count: 5 },
                ],
            }),
        ).toBe('All (15)');
    });

    it('takes the total it is given for options without counts, and none without either', () => {
        expect(allOption({ options: [{ type: 'movie' }], total: 7 })).toBe('All (7)');
        expect(allOption({ options: [{ type: 'movie' }] })).toBe('All');
    });
});
