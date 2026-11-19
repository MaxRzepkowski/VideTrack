import Channels from '@/pages/settings/Channels.vue';
import { channelData, channelKind } from '@/test/factories';
import type { ChannelData } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const kinds = [channelKind()];

const channel = channelData({ id: 1, label: 'Friends' });

function mountPage(channels: ChannelData[]) {
    return mount(Channels, {
        props: { page: { channels, kinds, labelMax: 60, digestHour: 8, defaultMode: 'digest', push: null } },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                SettingsLayout: { template: '<div><slot /></div>' },
                ChannelPanel: {
                    props: ['channel', 'kind'],
                    template: '<div class="panel">{{ channel.label }}: {{ kind ? kind.label : "none" }}</div>',
                },
                AddChannelForm: { template: '<div class="add" />' },
            },
        },
    });
}

describe('the notification channels page', () => {
    it('says the bell is all there is until a channel is added', () => {
        const page = mountPage([]);

        expect(page.text()).toContain('No channels yet: notifications show only in the bell.');
        expect(page.find('.add').exists()).toBe(true);
    });

    it('lists every channel with its kind, none for a kind out of reach', () => {
        const page = mountPage([channel, { ...channel, id: 2, driver: 'apprise', kind: 'tgram', kindLabel: null, label: 'Old' }]);

        expect(page.findAll('.panel').map((panel) => panel.text())).toEqual(['Friends: Discord', 'Old: none']);
        expect(page.text()).not.toContain('No channels yet');
    });
});
