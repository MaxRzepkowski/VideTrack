import { changedWebhook, withGroup } from '@/lib/webhooks';
import type { WebhookEventGroup } from '@/types/generated';
import { describe, expect, it } from 'vitest';

const ORDER: WebhookEventGroup[] = ['activity', 'ratings', 'favorites', 'status', 'lists', 'notifications'];

describe('withGroup', () => {
    it("ticks a group on or off and keeps the form's order", () => {
        expect(withGroup(['lists'], 'activity', true, ORDER)).toEqual(['activity', 'lists']);
        expect(withGroup(['activity', 'lists'], 'activity', false, ORDER)).toEqual(['lists']);
        expect(withGroup(['lists'], 'lists', true, ORDER)).toEqual(['lists']);
    });
});

describe('changedWebhook', () => {
    it('leaves out an address or a secret left empty, so the saved ones stay', () => {
        expect(changedWebhook({ label: 'n8n', url: ' ', secret: '', events: ['ratings'] })).toEqual({ label: 'n8n', events: ['ratings'] });
        expect(changedWebhook({ label: '', url: 'http://ha.local/hook', secret: 'whsec_x', events: [] })).toEqual({
            label: '',
            url: 'http://ha.local/hook',
            secret: 'whsec_x',
            events: [],
        });
    });
});
