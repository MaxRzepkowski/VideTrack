import ChannelKindDialog from '@/components/settings/channels/ChannelKindDialog.vue';
import { channelKind } from '@/test/factories';
import type { ChannelKindData } from '@/types/generated';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/channelLogos', () => ({
    channelIcon: () => null,
    channelLogo: (kind: string) => (kind === 'tgram' ? { url: '/images/channels/tgram.svg', width: 40, height: 40 } : null),
}));

function kind(code: string, label: string, popular: boolean): ChannelKindData {
    return channelKind({ driver: 'apprise', code, label, fields: [], popular });
}

const kinds = [kind('tgram', 'Telegram', true), kind('ntfys', 'ntfy', true), kind('exotel', 'Exotel', false)];

afterEach(() => {
    document.body.innerHTML = '';
});

function tileNames(): string[] {
    return [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] li button')].map((tile) => tile.textContent?.trim() ?? '');
}

function mountDialog() {
    return mount(ChannelKindDialog, { props: { kinds, open: true }, attachTo: document.body });
}

describe('ChannelKindDialog', () => {
    it('opens on the popular services, each a tile with its logo over its name', async () => {
        const dialog = mountDialog();
        await flushPromises();

        expect(tileNames()).toEqual(['Telegram', 'ntfy']);
        expect(document.querySelector('[role="dialog"] li img')?.getAttribute('src')).toBe('/images/channels/tgram.svg');

        dialog.unmount();
    });

    it('shows every service on request, and the ones a search names', async () => {
        const dialog = mountDialog();
        await flushPromises();

        [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')]
            .find((button) => button.textContent?.includes('Show all 3 services'))
            ?.click();
        await flushPromises();

        expect(tileNames()).toEqual(['Telegram', 'ntfy', 'Exotel']);

        const search = document.querySelector<HTMLInputElement>('[role="dialog"] input[type="search"]');
        expect(search).not.toBeNull();
        if (search) {
            search.value = 'exo';
            search.dispatchEvent(new Event('input'));
        }
        await flushPromises();

        expect(tileNames()).toEqual(['Exotel']);

        dialog.unmount();
    });

    it('hands back the picked service and closes', async () => {
        const dialog = mountDialog();
        await flushPromises();

        document.querySelector<HTMLButtonElement>('[role="dialog"] li button')?.click();
        await flushPromises();

        expect(dialog.emitted('pick')?.[0]).toEqual([kinds[0]]);
        expect(dialog.emitted('update:open')?.[0]).toEqual([false]);

        dialog.unmount();
    });
});
