import PushDevices from '@/components/settings/channels/PushDevices.vue';
import { confirm } from '@/composables/useConfirm';
import type { NewPushSubscription } from '@/composables/usePushSubscription';
import { jsonFetch } from '@/lib/http';
import type { PushSupport } from '@/lib/push';
import type { PushDeviceData } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

vi.mock('@/lib/channelLogos', () => ({ channelIcon: () => null, channelLogo: () => null }));
vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));
vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));

const browser = vi.hoisted(() => ({
    support: null as unknown as ReturnType<typeof ref<PushSupport | null>>,
    fingerprint: null as unknown as ReturnType<typeof ref<string | null>>,
    subscribe: vi.fn<() => Promise<NewPushSubscription | null>>(),
    unsubscribe: vi.fn(() => Promise.resolve()),
}));

vi.mock('@/composables/usePushSubscription', () => ({ usePushSubscription: () => browser }));

const BODY = {
    endpoint: 'https://push.example.com/new',
    public_key: 'browser-key',
    auth_token: 'browser-auth',
    content_encoding: 'aes128gcm' as const,
};

function device(overrides: Partial<PushDeviceData> = {}): PushDeviceData {
    return {
        id: 3,
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
        fingerprint: 'here',
        subscribedAt: '2026-10-01T12:00:00+00:00',
        stale: false,
        ...overrides,
    };
}

const mountDevices = (devices: PushDeviceData[] = []) => mount(PushDevices, { props: { push: { publicKey: 'KEY', devices } } });

function button(wrapper: ReturnType<typeof mountDevices>, text: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text() === text);
}

beforeEach(() => {
    browser.support = ref<PushSupport | null>('ready');
    browser.fingerprint = ref<string | null>(null);
});

afterEach(() => vi.restoreAllMocks());

describe('PushDevices', () => {
    it('lists each device by its browser and system, the one it is open in marked', () => {
        browser.fingerprint.value = 'here';
        const devices = mountDevices([device(), device({ id: 4, fingerprint: 'there', userAgent: null })]);

        expect(devices.findAll('li').map((row) => row.text())).toEqual([
            expect.stringContaining('FirefoxLinuxThis device'),
            expect.stringContaining('Unknown browser'),
        ]);
        expect(devices.text()).toContain('Since October 1, 2026');
        // Here and heard already: nothing to switch on.
        expect(button(devices, 'Turn on for this device')).toBeUndefined();
    });

    it('switches push on here after the click, and the server lists the device', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
        browser.subscribe.mockResolvedValue({ body: BODY, replaced: null });
        const devices = mountDevices([device({ fingerprint: 'there' })]);

        expect(browser.subscribe).not.toHaveBeenCalled();
        await button(devices, 'Turn on for this device')?.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/settings.channels.devices.store', BODY, expect.objectContaining({ preserveScroll: true }));
    });

    it("puts a subscription to new keys in the old one's place", async () => {
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);
        vi.mocked(jsonFetch).mockResolvedValue(new Response(null, { status: 204 }));
        browser.fingerprint.value = 'here';
        browser.subscribe.mockResolvedValue({ body: BODY, replaced: 'https://push.example.com/old' });
        const devices = mountDevices([device({ stale: true })]);

        expect(devices.text()).toContain('Gets no notifications since the instance’s keys changed.');
        await button(devices, 'Turn on again')?.trigger('click');
        await flushPromises();

        expect(jsonFetch).toHaveBeenCalledWith('/settings.channels.devices.renew', {
            method: 'POST',
            body: { ...BODY, previous_endpoint: 'https://push.example.com/old' },
        });
        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['page'] }));
    });

    it('says why push cannot be switched on, instead of the button', () => {
        browser.support.value = 'install-first';
        const devices = mountDevices();

        expect(devices.text()).toContain('On iPhone and iPad, notifications work in the app added to the Home Screen');
        expect(button(devices, 'Turn on for this device')).toBeUndefined();
    });

    it('stops listening here before this device leaves the list', async () => {
        const destroy = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        browser.fingerprint.value = 'here';
        const devices = mountDevices([device()]);

        await button(devices, 'Remove')?.trigger('click');
        await flushPromises();

        expect(confirm).toHaveBeenCalled();
        expect(browser.unsubscribe).toHaveBeenCalled();
        expect(destroy).toHaveBeenCalledWith('/settings.channels.devices.destroy/3', { preserveScroll: true });
    });
});
