/*
 * The webhooks page. A webhook's address and secret never come back from the
 * server, so on a saved webhook both fields start empty and an empty one
 * keeps what is saved.
 */
import type { WebhookEventGroup } from '@/types/generated';

export interface WebhookFormValues {
    label: string;
    url: string;
    secret: string;
    events: WebhookEventGroup[];
}

/** The events ticked, with one group ticked on or off; the order is the form's, not the clicks'. */
export function withGroup(events: WebhookEventGroup[], group: WebhookEventGroup, ticked: boolean, order: WebhookEventGroup[]): WebhookEventGroup[] {
    const chosen = new Set(events);

    if (ticked) {
        chosen.add(group);
    } else {
        chosen.delete(group);
    }

    return order.filter((code) => chosen.has(code));
}

/** What a change to a saved webhook sends: an address or a secret left empty is not sent, so the saved one stays. */
export function changedWebhook(values: WebhookFormValues): Partial<WebhookFormValues> {
    const { url, secret, ...rest } = values;

    return { ...rest, ...(url.trim() === '' ? {} : { url }), ...(secret.trim() === '' ? {} : { secret }) };
}
