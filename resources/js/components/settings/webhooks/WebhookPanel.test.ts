import WebhookPanel from '@/components/settings/webhooks/WebhookPanel.vue';
import { outgoingWebhookData, webhookGroups } from '@/test/factories';
import type { OutgoingWebhookData } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));

const SECRET = outgoingWebhookData().newSecret;

const groups = webhookGroups();

const webhook = outgoingWebhookData;

const mountPanel = (data: OutgoingWebhookData = webhook()) => mount(WebhookPanel, { props: { webhook: data, groups, labelMax: 60, urlMax: 2048 } });

function button(wrapper: ReturnType<typeof mountPanel>, text: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text() === text);
}

describe('WebhookPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('names the webhook, its host and what it hears about, never its address or secret', () => {
        const panel = mountPanel();

        expect(panel.text()).toContain('Home Assistant');
        expect(panel.text()).toContain('homeassistant.local:8123');
        expect(panel.text()).toContain('Ratings');
        expect((panel.find('#webhook-3-url').element as HTMLInputElement).value).toBe('');
        expect((panel.find('#webhook-3-secret').element as HTMLInputElement).value).toBe('');
    });

    it('sends the events ticked in the form order, and the address and secret only when typed in', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const panel = mountPanel();

        await panel.findAll('button[role="checkbox"]')[0]?.trigger('click');
        await button(panel, 'Save')?.trigger('click');

        expect(visit).toHaveBeenCalledWith(
            '/settings.webhooks.update/3',
            expect.objectContaining({ method: 'patch', data: { label: 'Home Assistant', events: ['activity', 'ratings'] } }),
        );
    });

    it('puts in a new secret readable to copy when Generate is clicked', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const panel = mountPanel();

        await button(panel, 'Generate')?.trigger('click');

        const secret = panel.find('#webhook-3-secret');
        expect((secret.element as HTMLInputElement).value).toBe(SECRET);
        expect(secret.attributes('type')).toBe('text');

        await button(panel, 'Save')?.trigger('click');

        expect(visit).toHaveBeenCalledWith(
            '/settings.webhooks.update/3',
            expect.objectContaining({ data: expect.objectContaining({ secret: SECRET }) }),
        );
    });

    it('tells how the last message failed', () => {
        expect(mountPanel(webhook({ lastFailure: 'The service refused the last message.' })).text()).toContain(
            'The service refused the last message.',
        );
    });

    it('switches the webhook at once, without the form', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
        const panel = mountPanel();

        await panel.find('input[role="switch"]').setValue(false);

        expect(patch).toHaveBeenCalledWith('/settings.webhooks.update/3', { enabled: false }, { preserveScroll: true });
    });

    it('sends a test and says whether it arrived', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => options?.onSuccess?.({} as never));
        const panel = mountPanel();

        await button(panel, 'Send a test message')?.trigger('click');

        expect(post).toHaveBeenCalledWith('/settings.webhooks.test/3', {}, expect.anything());
        expect(panel.text()).toContain('Test message sent.');

        vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => options?.onError?.({ test: 'The test message did not arrive.' }));
        await button(panel, 'Send a test message')?.trigger('click');

        expect(panel.text()).toContain('The test message did not arrive.');
    });

    it('removes the webhook once the user confirms', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        const panel = mountPanel();

        await button(panel, 'Remove')?.trigger('click');
        await flushPromises();

        expect(remove).toHaveBeenCalledWith('/settings.webhooks.destroy/3', { preserveScroll: true });
    });
});
