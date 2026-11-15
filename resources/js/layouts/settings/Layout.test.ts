import SettingsLayout from '@/layouts/settings/Layout.vue';
import { testPage } from '@/test/page';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

describe('settings Layout', () => {
    it('marks the page being shown as the current one, following the visit', async () => {
        testPage.url = '/settings.preferences.edit?tab=1';
        const wrapper = mount(SettingsLayout);

        const current = () => wrapper.findAll('a[aria-current="page"]').map((link) => link.text());

        expect(current()).toEqual(['Preferences']);

        testPage.url = '/settings.tracking.edit';
        await nextTick();

        expect(current()).toEqual(['Tracking']);
    });
});
