import { blankConfig, channelModes, configError, kindsMatching, savedConfig } from '@/lib/channels';
import { channelKind, credentialField } from '@/test/factories';
import { describe, expect, it } from 'vitest';

describe('channels', () => {
    it('starts every field of a kind blank', () => {
        expect(blankConfig([credentialField(), credentialField({ key: 'tag', label: 'Tag', type: 'text' })])).toEqual({ webhook_url: '', tag: '' });
    });

    it('offers the digest at the server’s hour before the instant mode', () => {
        expect(channelModes(8)).toEqual([
            { value: 'digest', label: 'Daily digest at 8:00 AM' },
            { value: 'instant', label: 'Instantly, one message per event' },
        ]);
    });

    it('reads a field’s error under the config key', () => {
        expect(configError({ 'config.webhook_url': 'Wrong address.' }, 'webhook_url')).toBe('Wrong address.');
        expect(configError({}, 'webhook_url')).toBeUndefined();
    });

    it('finds a service by every word of the search, whatever their case', () => {
        const kind = (label: string) => channelKind({ code: label, label });
        const kinds = [kind('Nextcloud Talk'), kind('Nextcloud'), kind('Telegram')];

        expect(kindsMatching(kinds, 'next TALK').map((found) => found.label)).toEqual(['Nextcloud Talk']);
        expect(kindsMatching(kinds, '  ')).toEqual(kinds);
    });

    it('starts an edit form with the saved plain values and every secret empty', () => {
        const fields = [credentialField({ key: 'host' }), credentialField({ key: 'password', secret: true }), credentialField({ key: 'port' })];

        expect(savedConfig(fields, { host: 'gmail.com', password: 'never sent' })).toEqual({ host: 'gmail.com', password: '', port: '' });
    });
});
