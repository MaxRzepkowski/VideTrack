import ChannelPanel from '@/components/settings/channels/ChannelPanel.vue';
import { channelData, channelKind, credentialField } from '@/test/factories';
import type { ChannelData, ChannelKindData } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/serviceLogos', () => ({ serviceIcon: () => null, serviceLogo: () => null }));

vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));

const discord = channelKind();

const channel = channelData;

const mountPanel = (data: ChannelData = channel(), kind: ChannelKindData | null = discord) =>
    mount(ChannelPanel, { props: { channel: data, kind, labelMax: 60, digestHour: 8 } });

function button(wrapper: ReturnType<typeof mountPanel>, text: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text() === text);
}

describe('ChannelPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('names the channel and its kind, and says its settings are saved without showing them', () => {
        const panel = mountPanel();

        expect(panel.text()).toContain('Movie night');
        expect(panel.text()).toContain('Discord');
        expect(panel.text()).toContain('Saved');
        expect(panel.find('#channel-4-webhook_url').exists()).toBe(false);
    });

    it('opens the plain settings filled in and a secret empty, which keeps what is saved', async () => {
        const email = channelKind({
            driver: 'apprise',
            code: 'mailtos',
            label: 'E-Mail',
            fields: [
                credentialField({ key: 'host', label: 'Domain', type: 'text' }),
                credentialField({ key: 'password', label: 'Password', type: 'password', secret: true }),
            ],
        });
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const panel = mountPanel(channel({ kind: 'mailtos', values: { host: 'gmail.com' } }), email);

        await button(panel, 'Edit settings')?.trigger('click');

        expect((panel.find('#channel-4-host').element as HTMLInputElement).value).toBe('gmail.com');
        expect((panel.find('#channel-4-password').element as HTMLInputElement).value).toBe('');
        expect(panel.find('#channel-4-password').attributes('placeholder')).toBe('Saved. Leave empty to keep it.');

        await panel.find('#channel-4-host').setValue('example.com');
        await button(panel, 'Save')?.trigger('click');

        expect(visit).toHaveBeenCalledWith(
            '/settings.channels.update/4',
            expect.objectContaining({ data: { label: 'Movie night', mode: 'digest', config: { host: 'example.com', password: '' } } }),
        );
    });

    it('sends a new address whole, only once its fields were opened', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const panel = mountPanel();

        await button(panel, 'Edit settings')?.trigger('click');
        await panel.find('#channel-4-webhook_url').setValue('https://discord.com/api/webhooks/9/new');
        await button(panel, 'Save')?.trigger('click');

        expect(visit).toHaveBeenCalledWith(
            '/settings.channels.update/4',
            expect.objectContaining({
                data: { label: 'Movie night', mode: 'digest', config: { webhook_url: 'https://discord.com/api/webhooks/9/new' } },
            }),
        );
    });

    it('opens an empty field for a new address on request, and closes it on discard', async () => {
        const panel = mountPanel();

        await button(panel, 'Edit settings')?.trigger('click');

        expect((panel.find('#channel-4-webhook_url').element as HTMLInputElement).value).toBe('');

        await panel.find('#channel-4-webhook_url').setValue('https://discord.com/api/webhooks/9/new');
        await button(panel, 'Discard')?.trigger('click');

        expect(panel.find('#channel-4-webhook_url').exists()).toBe(false);
    });

    it('keeps the address as it is while the instance cannot list the services', () => {
        const panel = mountPanel(channel(), null);

        expect(button(panel, 'Edit settings')).toBeUndefined();
        expect(button(panel, 'Send a test message')).toBeDefined();
    });

    it('tells how the last message failed', () => {
        expect(mountPanel(channel({ lastFailure: 'The service refused the last message.' })).text()).toContain(
            'The service refused the last message.',
        );
    });

    it('switches the channel at once, without the form', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
        const panel = mountPanel();

        await panel.find('input[role="switch"]').setValue(false);

        expect(patch).toHaveBeenCalledWith('/settings.channels.update/4', { enabled: false }, { preserveScroll: true });
    });

    it('saves the form only once something changed', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const panel = mountPanel();

        expect(button(panel, 'Save')).toBeUndefined();

        await panel.find('#channel-4-mode').setValue('instant');

        expect(panel.text()).toContain('You have unsaved changes');
        await button(panel, 'Save')?.trigger('click');

        expect(visit).toHaveBeenCalledWith(
            '/settings.channels.update/4',
            expect.objectContaining({ method: 'patch', data: { label: 'Movie night', mode: 'instant' } }),
        );
    });

    it('sends a test and says when it arrived', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => options?.onSuccess?.({} as never));
        const panel = mountPanel();

        await button(panel, 'Send a test message')?.trigger('click');

        expect(post).toHaveBeenCalledWith('/settings.channels.test/4', {}, expect.anything());
        expect(panel.text()).toContain('Test message sent.');
    });

    it('says when the test did not arrive', async () => {
        vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => options?.onError?.({ test: 'The test message did not arrive.' }));
        const panel = mountPanel();

        await button(panel, 'Send a test message')?.trigger('click');

        expect(panel.text()).toContain('The test message did not arrive.');
    });

    it('removes the channel once the user confirms', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        const panel = mountPanel();

        await button(panel, 'Remove')?.trigger('click');
        await flushPromises();

        expect(remove).toHaveBeenCalledWith('/settings.channels.destroy/4', { preserveScroll: true });
    });
});
