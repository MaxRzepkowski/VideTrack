import ConnectionPanel from '@/components/settings/import/ConnectionPanel.vue';
import { vTooltip } from '@/directives/tooltip';
import { jsonFetch } from '@/lib/http';
import { testPage } from '@/test/page';
import type { ConnectDef, ConnectionCard } from '@/types/integrations';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));

const fetchMock = vi.mocked(jsonFetch);

const simkl: ConnectDef = {
    service: 'simkl',
    name: 'Simkl',
    method: 'device',
    fields: [],
    autoImportMinutes: 360,
    connection: null,
    errorKey: 'connection_simkl',
};

const json = (body: unknown, status = 200): Response =>
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

const started = (userCode: string) =>
    json({ userCode, verificationUri: 'https://simkl.com/pin', verificationUriComplete: null, expiresIn: 900, interval: 5 });

const tracearr: ConnectDef = {
    service: 'tracearr',
    name: 'Tracearr',
    method: 'form',
    fields: [
        { key: 'url', label: 'Address', type: 'url', help: null, optional: false, secret: false },
        { key: 'key', label: 'API key', type: 'password', help: 'Settings – API', optional: false, secret: false },
    ],
    autoImportMinutes: 60,
    connection: null,
    errorKey: 'connection_tracearr',
};

const card: ConnectionCard = {
    id: 7,
    service: 'tracearr',
    account: 'alex',
    status: 'active',
    autoImport: false,
    lastImportedAt: null,
    lastError: null,
};

// The page holds the connection; the panel hands a new one back through v-model.
const mountPanel = (connect: ConnectDef = simkl, connection: ConnectionCard | null = null) => {
    const panel = mount(ConnectionPanel, {
        props: { connect, connection, 'onUpdate:connection': (next: ConnectionCard | null) => panel.setProps({ connection: next }) },
        global: { directives: { tooltip: vTooltip } },
    });

    return panel;
};

describe('ConnectionPanel', () => {
    beforeEach(() => vi.useFakeTimers());

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        fetchMock.mockReset();
    });

    it('says why the service would not start, under the card', async () => {
        fetchMock.mockResolvedValueOnce(
            json({ message: 'Simkl is unreachable: timed out', errors: { connection: ['Simkl is unreachable: timed out'] } }, 422),
        );
        const panel = mountPanel();

        await panel.find('button').trigger('click');
        await flushPromises();

        expect(panel.text()).toContain('Simkl is unreachable: timed out');
    });

    it('shows the code to approve and asks again on the service’s interval', async () => {
        fetchMock.mockResolvedValueOnce(started('ABCD-EFGH')).mockResolvedValueOnce(json({ status: 'pending' }));
        const panel = mountPanel();

        await panel.find('button').trigger('click');
        await flushPromises();
        expect(panel.text()).toContain('ABCD-EFGH');

        await vi.advanceTimersByTimeAsync(5000);

        expect(fetchMock).toHaveBeenLastCalledWith('/settings.connections.device.poll/simkl', { method: 'POST', body: undefined });
        expect(panel.text()).toContain('ABCD-EFGH');
    });

    it('ends the attempt when the code expired, or the service refused it, and says so', async () => {
        fetchMock
            .mockResolvedValueOnce(started('ABCD-EFGH'))
            .mockResolvedValueOnce(json({ status: 'expired' }))
            .mockResolvedValueOnce(started('IJKL-MNOP'))
            .mockResolvedValueOnce(json({ status: 'failed', message: 'Simkl does not accept this instance.' }));
        const panel = mountPanel();

        await panel.find('button').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(5000);

        expect(panel.text()).toContain('The code expired. Start again.');
        expect(panel.text()).not.toContain('ABCD-EFGH');

        await panel.find('button').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(5000);

        expect(panel.text()).toContain('Simkl does not accept this instance.');
        expect(panel.text()).not.toContain('IJKL-MNOP');
    });

    it('lets a late answer about a cancelled code go, so it cannot end the attempt begun since', async () => {
        let answerOldPoll: (response: Response) => void = () => undefined;
        fetchMock
            .mockResolvedValueOnce(started('OLD-CODE'))
            .mockImplementationOnce(() => new Promise<Response>((resolve) => (answerOldPoll = resolve)))
            .mockResolvedValueOnce(json({ status: 'cancelled' }))
            .mockResolvedValueOnce(started('NEW-CODE'));
        const panel = mountPanel();

        await panel.find('button').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(5000);

        await panel.find('button').trigger('click'); // Cancel
        await flushPromises();
        await panel.find('button').trigger('click'); // Connect again
        await flushPromises();

        answerOldPoll(json({ status: 'expired' }));
        await flushPromises();

        expect(panel.text()).toContain('NEW-CODE');
        expect(panel.text()).not.toContain('The code expired. Start again.');
    });

    it('checks a pasted key, names a field the service would not take, and says under the form why connecting failed', async () => {
        fetchMock
            .mockResolvedValueOnce(json({ message: 'The key is wrong.', errors: { 'values.key': ['The key is wrong.'] } }, 422))
            .mockResolvedValueOnce(
                json({
                    choices: [
                        { id: 'u1', label: 'alex' },
                        { id: 'u2', label: 'guest' },
                    ],
                }),
            )
            .mockResolvedValueOnce(json({ message: 'Tracearr is unreachable.', errors: { connection: ['Tracearr is unreachable.'] } }, 422));
        const panel = mountPanel(tracearr);

        await panel.find('input[type="url"]').setValue('https://tracearr.example.test');
        await panel.find('input[type="password"]').setValue('trr_pub_wrong');
        await panel.find('form').trigger('submit');
        await flushPromises();
        expect(panel.text()).toContain('The key is wrong.');

        await panel.find('form').trigger('submit');
        await flushPromises();
        expect(panel.text()).toContain('Which user are you?');

        await panel.find('select').setValue('u1');
        await panel.find('form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenLastCalledWith('/settings.connections.connect/tracearr', {
            method: 'POST',
            body: { values: { url: 'https://tracearr.example.test', key: 'trr_pub_wrong' }, choice: 'u1' },
        });
        expect(panel.text()).toContain('Tracearr is unreachable.');
        expect(panel.emitted('update:connection')).toBeUndefined();
    });

    it('lets go of a connection once the user confirms', async () => {
        const destroy = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        const panel = mountPanel(tracearr, card);

        expect(panel.text()).toContain('alex');

        await panel.find('button').trigger('click');
        await flushPromises();

        expect(destroy).toHaveBeenCalledWith('/settings.connections.destroy/7', { preserveScroll: true });
    });

    it('sends the browser to the service for a trip there and back, and keeps nothing of it in the page', async () => {
        const trip: ConnectDef = {
            service: 'fake-redirect',
            name: 'Fake Redirect',
            method: 'redirect',
            fields: [],
            autoImportMinutes: 1440,
            connection: null,
            errorKey: 'connection_fake-redirect',
        };
        const assign = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign });
        fetchMock.mockResolvedValueOnce(json({ url: 'https://example.test/authorize?state=state-of-alex' }));
        const panel = mountPanel(trip);

        await panel.find('button').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/settings.connections.redirect.begin/fake-redirect', { method: 'POST', body: undefined });
        expect(assign).toHaveBeenCalledWith('https://example.test/authorize?state=state-of-alex');
    });

    it('says why a trip to the service came back refused, and why one could not start', async () => {
        const trip: ConnectDef = {
            service: 'fake-redirect',
            name: 'Fake Redirect',
            method: 'redirect',
            fields: [],
            autoImportMinutes: 1440,
            connection: null,
            errorKey: 'connection_fake-redirect',
        };
        testPage.props.errors = { 'connection_fake-redirect': 'You turned the connection down.', connection_other: 'Not this card.' };
        const panel = mountPanel(trip);

        expect(panel.text()).toContain('You turned the connection down.');
        expect(panel.text()).not.toContain('Not this card.');

        fetchMock.mockResolvedValueOnce(json({ errors: { connection: ['Fake Redirect is unreachable.'] } }, 422));
        await panel.find('button').trigger('click');
        await flushPromises();

        expect(panel.text()).toContain('Fake Redirect is unreachable.');
    });
});
