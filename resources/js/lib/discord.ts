/*
 * The Discord webhook on the Preferences page. A saved webhook is a
 * credential and never comes back to the page — only its last characters do.
 * The field holds a new address, which replaces the saved one; leaving it
 * empty keeps what is saved; removing turns Discord off.
 */

export interface WebhookDraft {
    /** A new address typed into the field; empty keeps the saved one. */
    typed: string;
    /** The user asked to remove the saved webhook. */
    remove: boolean;
}

/** The typed address; blanks alone are no address (the server would read them as "off"). */
function typedAddress(draft: WebhookDraft): string | null {
    const typed = draft.typed.trim();

    return typed === '' ? null : typed;
}

/** What a save sends about the webhook: nothing, a new address, or null for "off". */
export function webhookChange(draft: WebhookDraft): { discord_webhook_url?: string | null } {
    const typed = typedAddress(draft);

    if (typed !== null) {
        return { discord_webhook_url: typed };
    }

    return draft.remove ? { discord_webhook_url: null } : {};
}

/** Whether saving turns Discord off — removed, and no new address typed to replace it. */
export function webhookRemovedOnSave(draft: WebhookDraft): boolean {
    return draft.remove && typedAddress(draft) === null;
}

/**
 * Which webhook the test button would message: the typed one, else the saved
 * one (null — the server's), or none at all when there is nothing to test.
 */
export function webhookToTest(draft: WebhookDraft, saved: boolean): { webhook_url: string | null } | undefined {
    const typed = typedAddress(draft);

    if (typed !== null) {
        return { webhook_url: typed };
    }

    return saved && !draft.remove ? { webhook_url: null } : undefined;
}
