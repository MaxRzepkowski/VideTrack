import {
    applicationServerKey,
    currentPushEnvironment,
    fingerprintOf,
    pushSupport,
    subscribedWith,
    subscriptionBody,
    type PushSubscriptionBody,
    type PushSupport,
} from '@/lib/push';
import { onMounted, ref } from 'vue';

/** A subscription made on a click, and the one of an older key pair it took the place of. */
export interface NewPushSubscription {
    body: PushSubscriptionBody;
    /** The address of this browser's subscription to an older key pair; null when it had none. */
    replaced: string | null;
}

/**
 * Push in the browser the settings page is open in: whether it can be
 * switched on here, which listed device this browser is, and the
 * subscription itself. The browser asks the user only from subscribe(),
 * which a click calls: never on its own.
 */
export function usePushSubscription(publicKey: () => string) {
    /** Null while the browser is being asked. */
    const support = ref<PushSupport | null>(null);
    /** The hash of this browser's subscription address, whatever key it was made with; null without one. */
    const fingerprint = ref<string | null>(null);

    onMounted(async () => {
        const verdict = pushSupport(currentPushEnvironment());

        if (verdict !== 'ready' && verdict !== 'blocked') {
            support.value = verdict;

            return;
        }

        const registration = await navigator.serviceWorker.getRegistration();
        support.value = registration === undefined ? 'unsupported' : verdict;
        const subscription = await registration?.pushManager.getSubscription();
        fingerprint.value = subscription ? await fingerprintOf(subscription.endpoint) : null;
    });

    /** Null when the user did not allow it. */
    async function subscribe(): Promise<NewPushSubscription | null> {
        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            support.value = permission === 'denied' ? 'blocked' : support.value;

            return null;
        }

        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        let replaced: string | null = null;

        if (subscription !== null && !subscribedWith(subscription, publicKey())) {
            replaced = subscription.endpoint;
            await subscription.unsubscribe();
            subscription = null;
        }

        subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationServerKey(publicKey()) });
        fingerprint.value = await fingerprintOf(subscription.endpoint);

        return { body: subscriptionBody(subscription), replaced };
    }

    /** This browser stops listening, as its row leaves the list. */
    async function unsubscribe(): Promise<void> {
        const registration = await navigator.serviceWorker.getRegistration();
        await (await registration?.pushManager.getSubscription())?.unsubscribe();
        fingerprint.value = null;
    }

    return { support, fingerprint, subscribe, unsubscribe };
}
