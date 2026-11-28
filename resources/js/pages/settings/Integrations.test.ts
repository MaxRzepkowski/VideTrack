import Integrations from '@/pages/settings/Integrations.vue';
import type { ConnectionCard, ConnectionSourceData, IntegrationsPage, ScrobbleSourceData } from '@/types/integrations';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';

const held: ConnectionCard = { id: 3, service: 'simkl', account: 'alex', status: 'active', autoImport: true, lastImportedAt: null, lastError: null };

const webhook = (code: string, name: string): ScrobbleSourceData => ({
    code,
    name,
    description: '',
    notice: null,
    instructions: [],
    canTest: false,
    endpointLimit: 3,
    endpoints: [],
});

const account = (code: string, label: string, connection: ConnectionCard | null = null): ConnectionSourceData => ({
    code,
    label,
    description: '',
    notes: [],
    connect: { service: code, name: label, method: 'form', fields: [], autoImportMinutes: 60, connection, errorKey: `connection_${code}` },
});

const page = (overrides: Partial<IntegrationsPage> = {}): IntegrationsPage => ({
    webhooks: [webhook('jellyfin', 'Jellyfin'), webhook('plex', 'Plex')],
    accounts: [account('simkl', 'Simkl', held), account('tracearr', 'Tracearr')],
    connected: [
        { kind: 'webhook', code: 'jellyfin' },
        { kind: 'account', code: 'simkl' },
    ],
    available: [
        { kind: 'webhook', code: 'plex' },
        { kind: 'account', code: 'tracearr' },
    ],
    selected: null,
    ...overrides,
});

// The cards stand in by name, and an account card can be told it connected.
const Card = (name: string) =>
    defineComponent({
        props: { source: { type: Object, required: true } },
        emits: ['update:connection'],
        setup:
            (props, { emit }) =>
            () =>
                h('button', { 'data-card': `${name}:${(props.source as { code: string }).code}`, onClick: () => emit('update:connection', held) }),
    });

const mountPage = (data: IntegrationsPage = page()) =>
    mount(Integrations, {
        props: { page: data },
        attachTo: document.body,
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                SettingsLayout: { template: '<div><slot /></div>' },
                WebhookSourceCard: Card('webhook'),
                AccountSourceCard: Card('account'),
            },
        },
    });

const sections = (wrapper: ReturnType<typeof mountPage>) =>
    wrapper.findAll('section').map((section) => ({
        title: section.find('h3').text(),
        cards: section.findAll('[data-card]').map((card) => card.attributes('data-card')),
        tiles: section.findAll('li').map((tile) => tile.text()),
    }));

describe('Integrations', () => {
    it('shows what is set up on top and the rest as tiles to add, with no card open', () => {
        expect(sections(mountPage())).toEqual([
            { title: 'Connected', cards: ['webhook:jellyfin', 'account:simkl'], tiles: [] },
            { title: 'Add', cards: [], tiles: ['Plex', 'Tracearr'] },
        ]);
    });

    it('opens a tile’s card under the grid, and closes it on a second click', async () => {
        const wrapper = mountPage();
        const tile = () => wrapper.findAll('li button')[1]!;

        await tile().trigger('click');

        expect(sections(wrapper)[1]?.cards).toEqual(['account:tracearr']);
        expect(tile().attributes('aria-pressed')).toBe('true');

        await tile().trigger('click');

        expect(sections(wrapper)[1]?.cards).toEqual([]);
    });

    it('moves an account connected on its card to the top without a reload', async () => {
        const wrapper = mountPage();

        await wrapper.findAll('li button')[1]!.trigger('click');
        await wrapper.find('[data-card="account:tracearr"]').trigger('click');

        expect(sections(wrapper)).toEqual([
            { title: 'Connected', cards: ['webhook:jellyfin', 'account:simkl', 'account:tracearr'], tiles: [] },
            { title: 'Add', cards: [], tiles: ['Plex'] },
        ]);
    });

    it('opens the card a trip to the service came back to without connecting', () => {
        expect(sections(mountPage(page({ selected: 'tracearr' })))[1]?.cards).toEqual(['account:tracearr']);
    });

    it('leaves out a section with nothing in it', () => {
        const wrapper = mountPage(page({ connected: [], accounts: [account('tracearr', 'Tracearr')] }));

        expect(sections(wrapper).map((section) => section.title)).toEqual(['Add']);
    });
});
