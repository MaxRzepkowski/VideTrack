import { readonly, shallowRef } from 'vue';

export interface ConfirmRequest {
    /** Short heading; when omitted the message itself heads the dialog. */
    title?: string;
    /** What happens, in one or two sentences. */
    message: string;
    /** The verb on the button that goes ahead, e.g. "Delete". */
    confirmLabel: string;
}

interface PendingConfirm extends ConfirmRequest {
    resolve: (confirmed: boolean) => void;
}

// One dialog for the whole app: ConfirmDialog in the app layout renders
// whatever is pending here.
const pending = shallowRef<PendingConfirm | null>(null);

/**
 * Ask before an action that cannot be undone. Resolves true when the user
 * goes ahead, false when they cancel or dismiss the dialog.
 */
export function confirm(request: ConfirmRequest): Promise<boolean> {
    // A second request while one is open answers the first with "no".
    pending.value?.resolve(false);

    return new Promise((resolve) => {
        pending.value = { ...request, resolve };
    });
}

/** For the dialog host only: the open request and how to answer it. */
export function useConfirmHost() {
    function answer(confirmed: boolean): void {
        const current = pending.value;

        if (current === null) {
            return;
        }

        pending.value = null;
        current.resolve(confirmed);
    }

    return { pending: readonly(pending), answer };
}
