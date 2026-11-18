import { router } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';

/** How long "Test message sent." stays beside the button. */
export const TEST_SENT_SHOWN_MS = 3000;

/**
 * "Send a test message" on a saved channel or webhook: posts to its test
 * address once the user clicks, says for a moment that it arrived, or keeps
 * the reason it did not.
 */
export function useTestMessage(address: () => string) {
    const testing = ref(false);
    const sent = ref(false);
    const error = ref<string | undefined>();

    let sentTimer: ReturnType<typeof setTimeout> | undefined;

    onUnmounted(() => clearTimeout(sentTimer));

    function send(): void {
        sent.value = false;
        error.value = undefined;
        router.post(
            address(),
            {},
            {
                preserveScroll: true,
                onStart: () => (testing.value = true),
                onFinish: () => (testing.value = false),
                onError: (errors) => (error.value = errors.test),
                onSuccess: () => {
                    sent.value = true;
                    clearTimeout(sentTimer);
                    sentTimer = setTimeout(() => (sent.value = false), TEST_SENT_SHOWN_MS);
                },
            },
        );
    }

    return { testing, sent, error, send };
}
