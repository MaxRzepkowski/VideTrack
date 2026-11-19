import { renewPushSubscription } from '@/lib/push';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';
import { Workbox } from 'workbox-window';

// The app never reloads by itself, so a tab left open for days would not otherwise hear of a deploy.
export const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;

/**
 * Installs the service worker and offers a waiting new version to the user: the switch reloads the tab, so it
 * happens when they say so, not under a half-filled form. Once installed, a push subscription to an older key pair
 * is renewed.
 */
export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const workbox = new Workbox('/sw.js');

    workbox.addEventListener('waiting', () => offerUpdate(workbox));

    void workbox.register().then((registration) => {
        if (registration !== undefined) {
            setInterval(() => void registration.update(), UPDATE_CHECK_INTERVAL_MS);
            void renewPushSubscription(registration);
        }
    });
}

/**
 * The development server has no service worker, but one left by a local production build would keep answering
 * for the origin; it is removed so the two do not mix.
 */
export function removeServiceWorker(): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    void navigator.serviceWorker.getRegistrations().then((registrations) => {
        for (const registration of registrations) {
            void registration.unregister();
        }
    });
}

function offerUpdate(workbox: Workbox): void {
    workbox.addEventListener('controlling', () => window.location.reload());

    toast(trans('app.new_version_ready'), {
        duration: Infinity,
        action: { label: trans('app.reload_new_version'), onClick: () => workbox.messageSkipWaiting() },
    });
}
