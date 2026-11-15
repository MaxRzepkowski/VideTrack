import { trans } from 'laravel-vue-i18n';
import { ref, type Ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Copy a string and say so for a moment. The failure case is real rather
 * than theoretical: the clipboard is denied on insecure origins, which is
 * exactly how a self-hosted instance is often reached. A second click
 * restarts the moment instead of letting the first timer cut it short.
 */
export function useCopyToClipboard(failureMessage?: string): { copied: Ref<boolean>; copy: (text: string) => Promise<void> } {
    const copied = ref(false);
    let timer: number | undefined;

    async function copy(text: string): Promise<void> {
        try {
            await navigator.clipboard.writeText(text);
            copied.value = true;
            window.clearTimeout(timer);
            timer = window.setTimeout(() => (copied.value = false), 2000);
        } catch {
            toast.error(failureMessage ?? trans('app.could_not_copy_that'));
        }
    }

    return { copied, copy };
}
