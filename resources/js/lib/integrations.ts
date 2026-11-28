import type { ConnectionCard, IntegrationRef, IntegrationsPage } from '@/types/integrations';

/** What each account source holds right now, by source code: null for none. */
export type HeldAccounts = Record<string, ConnectionCard | null>;

/** One card's address on the page and in the DOM: a server and an account may share a code. */
export function integrationKey(ref: IntegrationRef): string {
    return `${ref.kind}-${ref.code}`;
}

/**
 * The page's two sections as they stand. The server orders both (what is
 * set up by when, the rest by name); an account connected on the card since
 * moves to the end of what is set up, as the newest, without a reload.
 */
export function integrationSections(page: IntegrationsPage, held: HeldAccounts): { connected: IntegrationRef[]; available: IntegrationRef[] } {
    const joined = (ref: IntegrationRef): boolean => ref.kind === 'account' && held[ref.code] != null;

    return {
        connected: [...page.connected, ...page.available.filter(joined)],
        available: page.available.filter((ref) => !joined(ref)),
    };
}

/** What every account card holds as the page props say, for the page to keep while cards connect. */
export function heldAccounts(page: IntegrationsPage): HeldAccounts {
    return Object.fromEntries(page.accounts.map((account) => [account.code, account.connect.connection]));
}
