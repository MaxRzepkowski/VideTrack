import Api from '@/pages/settings/Api.vue';
import type { ApiTokenItem } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function token(overrides: Partial<ApiTokenItem> = {}): ApiTokenItem {
    return { id: 1, name: 'Laptop script', abilities: ['read'], lastUsedAt: null, createdAt: '2026-10-01T10:00:00Z', expiresAt: null, ...overrides };
}

function page(tokens: ApiTokenItem[] = [], createdToken: string | null = null, mcpUrl: string | null = 'https://tracker.example.com/api/mcp') {
    return mount(Api, {
        props: { tokens, createdToken, docsUrl: 'https://github.com/example/fork/blob/main/docs/api.md', mcpUrl },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' }, Head: true } },
    });
}

describe('the API settings page', () => {
    it('links the API reference kept with the source, in a new tab', () => {
        const link = page()
            .findAll('a')
            .find((anchor) => anchor.text() === 'API documentation');

        expect(link?.attributes('href')).toBe('https://github.com/example/fork/blob/main/docs/api.md');
        expect(link?.attributes('target')).toBe('_blank');
        expect(link?.attributes('rel')).toBe('noopener noreferrer');
    });

    it('says there are no tokens yet', () => {
        expect(page().text()).toContain('No tokens yet.');
    });

    it('names what each token may do and whether it was ever used', () => {
        const text = page([token(), token({ id: 2, name: 'Sync job', abilities: ['read', 'write'] })]).text();

        expect(text).toContain('Laptop script');
        expect(text).toContain('Read only');
        expect(text).toContain('Read and write');
        expect(text).toContain('Never used');
    });

    it('shows a new token once, ready to copy', () => {
        const input = page([], 'plain-token-shown-once').find('#created-token');

        expect((input.element as HTMLInputElement).value).toBe('plain-token-shown-once');
    });

    it('names where an AI assistant connects and links how', () => {
        const wrapper = page();
        const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'How to connect it');

        expect((wrapper.find('#mcp-url').element as HTMLInputElement).value).toBe('https://tracker.example.com/api/mcp');
        expect(link?.attributes('href')).toBe('https://github.com/example/fork/blob/main/docs/api.md#mcp-server-for-ai-assistants');
    });

    it('says nothing of assistants when the instance closed the MCP server', () => {
        const wrapper = page([], null, null);

        expect(wrapper.find('#mcp-url').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('AI assistant');
    });
});
