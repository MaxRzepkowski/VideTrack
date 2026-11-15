import WebhookSourceCard from '@/components/settings/integrations/WebhookSourceCard.vue';
import type { ScrobbleSourceData, WebhookEndpointData } from '@/types/integrations';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

function endpoint(id: number): WebhookEndpointData {
    return {
        id,
        label: null,
        enabled: true,
        serverUsername: null,
        url: `https://example.test/hooks/${id}`,
        loopback: false,
        lastReceivedAt: null,
        lastError: null,
        deliveries: [],
    };
}

function source(endpoints: number, endpointLimit = 3): ScrobbleSourceData {
    return {
        code: 'jellyfin',
        name: 'Jellyfin',
        description: 'What you finish on your Jellyfin server.',
        notice: null,
        instructions: [],
        canTest: false,
        endpointLimit,
        endpoints: Array.from({ length: endpoints }, (_, index) => endpoint(index + 1)),
    };
}

const mountCard = (data: ScrobbleSourceData) =>
    mount(WebhookSourceCard, { props: { source: data }, global: { stubs: { WebhookEndpointPanel: true } } });

describe('WebhookSourceCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('offers to connect a server not connected yet, and asks for its first address', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
        const card = mountCard(source(0));

        await card.find('button').trigger('click');

        expect(card.find('button').text()).toBe('Connect');
        expect(post).toHaveBeenCalledWith('/settings.integrations.store/jellyfin', {}, { preserveScroll: true });
    });

    it('offers another address below the limit', () => {
        expect(mountCard(source(2)).find('button').text()).toBe('Add another address');
    });

    it('says how many addresses one server takes once they are all in use, instead of offering another', () => {
        const card = mountCard(source(3));

        expect(card.findAll('button')).toHaveLength(0);
        expect(card.text()).toContain('Up to 3 addresses for one server.');
    });
});
