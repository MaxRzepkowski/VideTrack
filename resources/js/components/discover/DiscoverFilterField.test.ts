import DiscoverFilterField from '@/components/discover/DiscoverFilterField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { nth } from '@/test/lists';
import type { DiscoverFilter, DiscoverRange } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function definition(overrides: Partial<DiscoverFilter>): DiscoverFilter {
    return {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: [
            { value: '0', label: 'Returning Series' },
            { value: '3', label: 'Ended' },
        ],
        default: null,
        unavailable: false,
        open: false,
        rangeMin: null,
        rangeMax: null,
        ...overrides,
    };
}

function field(filter: DiscoverFilter, value?: string | string[] | DiscoverRange) {
    return mount(DiscoverFilterField, { props: { filter, value } });
}

const updates = (wrapper: ReturnType<typeof field>) => wrapper.emitted('update')?.map(([value]) => value);

describe('DiscoverFilterField', () => {
    it('says a filter whose dictionary is down is unavailable', () => {
        expect(field(definition({ unavailable: true })).text()).toContain('Temporarily unavailable');
    });

    it('emits a picked option, and nothing for "any" — "0" is a value like any other', async () => {
        const wrapper = field(definition({}));
        const select = wrapper.find('select');

        await select.setValue('0');
        await select.setValue('');

        expect(updates(wrapper)).toEqual(['0', undefined]);
    });

    it('adds and removes options of a multi-select, and empties to nothing', () => {
        const wrapper = field(
            definition({
                kind: 'multi_select',
                options: [
                    { value: '18', label: 'Drama' },
                    { value: '35', label: 'Comedy' },
                ],
            }),
            ['18'],
        );
        const checkboxes = wrapper.findAllComponents(Checkbox);
        const drama = nth(checkboxes, 0);
        const comedy = nth(checkboxes, 1);

        comedy.vm.$emit('update:checked', true);
        drama.vm.$emit('update:checked', false);

        expect(updates(wrapper)).toEqual([['18', '35'], undefined]);
    });

    it('draws a short span as two selects, newest first, and keeps the other end', async () => {
        const wrapper = field(definition({ key: 'year', kind: 'range', options: [], rangeMin: 2020, rangeMax: 2026 }), { from: null, to: 2024 });
        const selects = wrapper.findAll('select');
        const from = nth(selects, 0);
        const to = nth(selects, 1);

        expect(from.findAll('option').map((option) => option.text())).toEqual(['From', '2026', '2025', '2024', '2023', '2022', '2021', '2020']);

        await from.setValue('2021');
        await to.setValue('');

        expect(updates(wrapper)).toEqual([{ from: 2021, to: 2024 }, undefined]);
    });

    it('draws a broad span as number inputs', async () => {
        const wrapper = field(definition({ key: 'runtime', kind: 'range', options: [], rangeMin: 0, rangeMax: 1440 }));
        const inputs = wrapper.findAll('input[type="number"]');

        expect(inputs).toHaveLength(2);

        await nth(inputs, 1).setValue('90');

        expect(updates(wrapper)).toEqual([{ from: null, to: 90 }]);
    });
});
