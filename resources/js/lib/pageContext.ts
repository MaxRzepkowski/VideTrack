import type { SharedData, User } from '@/types';
import type { IdentityProviderOption, TimeFormat } from '@/types/generated';
import { usePage } from '@inertiajs/vue3';

/*
 * What a formatter may know about the page it formats for — the UI language,
 * the viewer's clock preferences, where images live — read in this one place
 * from the props every page shares. The lib functions that need it ask here
 * rather than each reaching into the page on its own.
 */

function shared(): SharedData {
    return usePage<SharedData>().props;
}

/**
 * The signed-in user, on a page behind the login (every app page is). Asked
 * for where a guest can never be, it fails by name instead of reading null.
 */
export function signedInUser(): User {
    const user = shared().auth.user;

    if (user === null) {
        throw new Error('This page is for a signed-in user, and nobody is signed in.');
    }

    return user;
}

/** Whose panels "my profile" means: the signed-in user's username. */
export function viewerUsername(): string {
    return signedInUser().username;
}

/** The UI locale, for any Intl formatter (dates, region names, …). */
export function pageLocale(): string {
    return shared().locale;
}

/** The viewer's timezone preference; undefined = the browser's own (Intl default). */
export function viewerTimeZone(): string | undefined {
    return shared().auth.user?.preferences.timezone ?? undefined;
}

/** The viewer's "Time format" preference; guests get the locale's own. */
export function viewerTimeFormat(): TimeFormat {
    return shared().auth.user?.preferences.time_format ?? 'auto';
}

/** Base URL of the image CDN that provider-relative image paths hang off. */
export function imageBase(): string {
    return shared().imageBase;
}

/** Whether a password signs in here; false where identity providers are the only way in. */
export function passwordLoginEnabled(): boolean {
    return shared().passwordLogin;
}

/** The identity providers this instance signs in with. */
export function identityProviders(): IdentityProviderOption[] {
    return shared().identityProviders;
}
