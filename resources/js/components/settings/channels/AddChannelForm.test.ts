import AddChannelForm from '@/components/settings/channels/AddChannelForm.vue';
import { channelKind, credentialField } from '@/test/factories';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/channelLogos', () => ({ channelIcon: () => null, channelLogo: () => null }));

const discord = channelKind({
    fields: [credentialField({ help: 'Channel settings → Integrations → Webhooks.', secret: true })],
    setupUrl: 'https://support.discord.com/hc/articles/228383668',
});

const telegram = channelKind({
    driver: 'apprise',
    code: 'tgram',
    label: 'Telegram',
    fields: [
        credentialField({ key: 'bot_token', label: 'Bot Token', type: 'password', secret: true }),
        credentialField({ key: 'targets', label: 'Target Chat ID', type: 'text', help: 'Several separated by spaces.', optional: true }),
    ],
    setupUrl: 'https://appriseit.com/services/telegram/',
    popular: false,
});

const DialogStub = { props: ['open', 'kinds'], emits: ['pick', 'update:open'], template: '<div v-if="open" class="dialog" />' };

const mountForm = () =>
    mount(AddChannelForm, {
        props: { kinds: [discord, telegram], labelMax: 60, digestHour: 8, defaultMode: 'digest' },
        global: { stubs: { ChannelKindDialog: DialogStub } },
    });

describe('AddChannelForm', () => {
    afterEach(() => vi.restoreAllMocks());

    it('starts as one block that opens the services, and no list', async () => {
        const form = mountForm();

        expect(form.text()).toContain('Choose a service');
        expect(form.find('.dialog').exists()).toBe(false);
        expect(form.find('form').exists()).toBe(false);

        await form.find('button').trigger('click');

        expect(form.find('.dialog').exists()).toBe(true);
    });

    it("puts the picked service in the block's place, with its own fields and guide below", async () => {
        const form = mountForm();

        form.findComponent(DialogStub).vm.$emit('pick', telegram);
        await form.vm.$nextTick();

        expect(form.find('button').text()).toContain('Telegram');
        expect(form.find('#new-channel-bot_token').attributes('type')).toBe('password');
        expect(form.find('label[for="new-channel-targets"]').text()).toContain('(optional)');
        expect(form.find('a').attributes('href')).toBe('https://appriseit.com/services/telegram/');
        expect(form.find('#new-channel-label').attributes('placeholder')).toBe('Telegram');

        await form.find('button').trigger('click');

        expect(form.find('.dialog').exists()).toBe(true);
    });

    it('adds the channel with its driver, kind, mode and fields', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const form = mountForm();
        form.findComponent(DialogStub).vm.$emit('pick', discord);
        await form.vm.$nextTick();

        await form.find('#new-channel-webhook_url').setValue('https://discord.com/api/webhooks/1/a');
        await form.find('#new-channel-mode').setValue('instant');
        await form.find('form').trigger('submit');

        expect(visit).toHaveBeenCalledWith(
            '/settings.channels.store',
            expect.objectContaining({
                method: 'post',
                data: {
                    driver: 'discord',
                    kind: 'discord',
                    label: '',
                    mode: 'instant',
                    config: { webhook_url: 'https://discord.com/api/webhooks/1/a' },
                },
            }),
        );
    });
});
