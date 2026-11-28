import { heldAccounts, integrationKey, integrationSections } from '@/lib/integrations';
import type { ConnectionCard, ConnectionSourceData, IntegrationsPage } from '@/types/integrations';
import { describe, expect, it } from 'vitest';

const card = (service: string): ConnectionCard => ({
    id: 1,
    service,
    account: 'alex',
    status: 'active',
    autoImport: false,
    lastImportedAt: null,
    lastError: null,
});

const account = (code: string, connection: ConnectionCard | null = null): ConnectionSourceData => ({
    code,
    label: code,
    description: '',
    notes: [],
    connect: { service: code, name: code, method: 'form', fields: [], autoImportMinutes: 60, connection, errorKey: `connection_${code}` },
});

const page = (overrides: Partial<IntegrationsPage> = {}): IntegrationsPage => ({
    webhooks: [],
    accounts: [account('simkl', card('simkl')), account('tracearr')],
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

describe('integrationSections', () => {
    it('keeps the server order while nothing changed on the page', () => {
        const sections = integrationSections(page(), heldAccounts(page()));

        expect(sections.connected.map(integrationKey)).toEqual(['webhook-jellyfin', 'account-simkl']);
        expect(sections.available.map(integrationKey)).toEqual(['webhook-plex', 'account-tracearr']);
    });

    it('moves an account connected on its card to the end of what is set up', () => {
        const sections = integrationSections(page(), { simkl: card('simkl'), tracearr: card('tracearr') });

        expect(sections.connected.map(integrationKey)).toEqual(['webhook-jellyfin', 'account-simkl', 'account-tracearr']);
        expect(sections.available.map(integrationKey)).toEqual(['webhook-plex']);
    });

    it('never moves a server, which has no account to hold', () => {
        const sections = integrationSections(page(), { plex: card('plex') });

        expect(sections.available.map(integrationKey)).toContain('webhook-plex');
    });
});

describe('heldAccounts', () => {
    it('reads each account card, connected or not', () => {
        expect(heldAccounts(page())).toEqual({ simkl: card('simkl'), tracearr: null });
    });
});
