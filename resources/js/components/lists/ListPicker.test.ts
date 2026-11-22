import ListPicker from '@/components/lists/ListPicker.vue';
import type { UserList } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const LISTS: UserList[] = [
    { id: 1, title: 'Backlog', systemKey: 'watchlist', itemCount: 12 },
    { id: 2, title: 'Crime classics', systemKey: null, itemCount: 1 },
];

const picker = (on: number[] = [], waiting = false) => mount(ListPicker, { props: { lists: LISTS, on, waiting } });

describe('ListPicker', () => {
    it('ticks the lists the thing is on, each saying how much it holds', () => {
        const rows = picker([2]).findAll('button[aria-pressed]');

        expect(rows.map((row) => row.attributes('aria-pressed'))).toEqual(['false', 'true']);
        expect(rows[0]!.text()).toContain('12 items');
        expect(rows[1]!.text()).toContain('1 item');
    });

    it('asks for a tick to be written, and for a new list by its trimmed name, emptying the field', async () => {
        const wrapper = picker();

        await wrapper.findAll('button[aria-pressed]')[1]!.trigger('click');
        await wrapper.find('input').setValue('  Noir  ');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('toggle')).toEqual([[LISTS[1]]]);
        expect(wrapper.emitted('create')).toEqual([['Noir']]);
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('');
    });

    it('makes nothing while the membership is not known yet', () => {
        expect(picker([], true).find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
