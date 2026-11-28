import AccountSourceCard from '@/components/settings/integrations/AccountSourceCard.vue';
import type { ConnectionCard, ConnectionSourceData } from '@/types/integrations';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const source: ConnectionSourceData = {
    code: 'tracearr',
    label: 'Tracearr',
    description: 'What your household watched.',
    notes: [{ text: 'Needs an API key.', url: null, linkText: null }],
    connect: {
        service: 'tracearr',
        name: 'Tracearr',
        method: 'form',
        fields: [],
        autoImportMinutes: 60,
        connection: null,
        errorKey: 'connection_tracearr',
    },
};

const card = (status: ConnectionCard['status']): ConnectionCard => ({
    id: 7,
    service: 'tracearr',
    account: 'alex',
    status,
    autoImport: false,
    lastImportedAt: null,
    lastError: null,
});

const mountCard = (connection: ConnectionCard | null) =>
    mount(AccountSourceCard, { props: { source, connection }, global: { stubs: { ConnectionPanel: true } } });

const importNow = (wrapper: ReturnType<typeof mountCard>) => wrapper.findAll('button').find((button) => button.text() === 'Import now');

describe('AccountSourceCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('says what the source is and how it is set up', () => {
        const wrapper = mountCard(null);

        expect(wrapper.text()).toContain('Tracearr');
        expect(wrapper.text()).toContain('Needs an API key.');
    });

    it('offers an import only on a connection that still reads', () => {
        expect(importNow(mountCard(null))).toBeUndefined();
        expect(importNow(mountCard(card('expired')))).toBeUndefined();
        expect(importNow(mountCard(card('active')))).toBeDefined();
    });

    it('queues the import the import page would, for this source', async () => {
        const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);

        await importNow(mountCard(card('active')))!.trigger('click');

        expect(post).toHaveBeenCalledWith('/settings.import.store', expect.objectContaining({ method: 'post', data: { source: 'tracearr' } }));
    });
});
