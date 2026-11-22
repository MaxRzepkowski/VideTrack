import MenuRow from '@/components/MenuRow.vue';
import { Bell } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

describe('MenuRow', () => {
    it('is a link to its place, with its name and what is there', () => {
        const row = mount(MenuRow, {
            props: { href: '/calendar', icon: Bell, label: 'Calendar', description: 'Day by day' },
            global: { stubs: { Link } },
        });

        expect(row.element.tagName).toBe('A');
        expect(row.attributes('href')).toBe('/calendar');
        expect(row.text()).toContain('Calendar');
        expect(row.text()).toContain('Day by day');
        expect(row.classes()).toContain('px-3');
    });

    it('is a button where it does something instead, standing further in inside a dialog', () => {
        const row = mount(MenuRow, { props: { icon: Bell, label: 'Remove rating', inset: 'dialog' } });

        expect(row.element.tagName).toBe('BUTTON');
        expect(row.attributes('type')).toBe('button');
        expect(row.classes()).toContain('px-5');
    });
});
