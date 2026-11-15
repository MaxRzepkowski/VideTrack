import { webhookChange, webhookRemovedOnSave, webhookToTest } from '@/lib/discord';
import { describe, expect, it } from 'vitest';

const NEW = 'https://discord.com/api/webhooks/1/new';

describe('webhookChange', () => {
    it('keeps the saved webhook when the field is left empty', () => {
        expect(webhookChange({ typed: '', remove: false })).toEqual({});
    });

    it('replaces it with a typed address, even one typed after asking to remove', () => {
        expect(webhookChange({ typed: NEW, remove: false })).toEqual({ discord_webhook_url: NEW });
        expect(webhookChange({ typed: NEW, remove: true })).toEqual({ discord_webhook_url: NEW });
    });

    it('turns Discord off when removed', () => {
        expect(webhookChange({ typed: '', remove: true })).toEqual({ discord_webhook_url: null });
    });

    it('reads a field of blanks as empty, never as "off"', () => {
        expect(webhookChange({ typed: '  ', remove: false })).toEqual({});
        expect(webhookChange({ typed: ` ${NEW} `, remove: false })).toEqual({ discord_webhook_url: NEW });
    });
});

describe('webhookRemovedOnSave', () => {
    it('says Discord goes off only while no new address replaces the removed one', () => {
        expect(webhookRemovedOnSave({ typed: '', remove: true })).toBe(true);
        expect(webhookRemovedOnSave({ typed: ' ', remove: true })).toBe(true);
        expect(webhookRemovedOnSave({ typed: NEW, remove: true })).toBe(false);
        expect(webhookRemovedOnSave({ typed: '', remove: false })).toBe(false);
    });
});

describe('webhookToTest', () => {
    it('tests a typed address before it is saved', () => {
        expect(webhookToTest({ typed: NEW, remove: false }, true)).toEqual({ webhook_url: NEW });
    });

    it('tests the saved webhook when nothing is typed', () => {
        expect(webhookToTest({ typed: '', remove: false }, true)).toEqual({ webhook_url: null });
    });

    it('has nothing to test without a saved webhook, or once it is marked for removal', () => {
        expect(webhookToTest({ typed: '', remove: false }, false)).toBeUndefined();
        expect(webhookToTest({ typed: '', remove: true }, true)).toBeUndefined();
        expect(webhookToTest({ typed: ' ', remove: false }, false)).toBeUndefined();
    });
});
