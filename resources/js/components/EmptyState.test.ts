import EmptyState from '@/components/EmptyState.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('EmptyState', () => {
    it('says its line, and makes room for an action only when given one', () => {
        expect(mount(EmptyState, { props: { message: 'Nothing here' } }).findAll('div')).toHaveLength(1);

        const withAction = mount(EmptyState, { props: { message: 'Nothing here' }, slots: { default: '<a href="/search">Search</a>' } });

        expect(withAction.text()).toContain('Nothing here');
        expect(withAction.find('a').text()).toBe('Search');
    });
});
