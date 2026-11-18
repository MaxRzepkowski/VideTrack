import AddWebhookForm from '@/components/settings/webhooks/AddWebhookForm.vue';
import { outgoingWebhookData, webhookGroups } from '@/test/factories';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const SECRET = outgoingWebhookData().newSecret;

const groups = webhookGroups();

describe('AddWebhookForm', () => {
    afterEach(() => vi.restoreAllMocks());

    it('adds a webhook with the address, the generated secret and the events ticked', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const form = mount(AddWebhookForm, { props: { groups, newSecret: SECRET, labelMax: 60, urlMax: 2048 } });

        expect(form.find('#new-webhook-secret').attributes('type')).toBe('password');

        await form.find('#new-webhook-url').setValue('http://homeassistant.local:8123/api/webhook/vide');
        await form
            .findAll('button')
            .find((button) => button.text() === 'Generate')
            ?.trigger('click');
        await form.findAll('button[role="checkbox"]')[1]?.trigger('click');
        await form.find('form').trigger('submit');

        expect(visit).toHaveBeenCalledWith(
            '/settings.webhooks.store',
            expect.objectContaining({
                method: 'post',
                data: { label: '', url: 'http://homeassistant.local:8123/api/webhook/vide', secret: SECRET, events: ['ratings'] },
            }),
        );
    });
});
