import { userPreferences, userState } from '@/test/factories';
import { reactive } from 'vue';

/**
 * The page Inertia would hand to `usePage()`: shared props a test can set.
 * Starts as an English page of a signed-in user with default preferences.
 */
export const testPage = reactive({
    props: {} as Record<string, unknown>,
    url: '/',
    component: 'Test',
});

let visits = 0;

/**
 * A fresh page for every test — at an address of its own, so the library
 * store, which follows the page, starts the test empty.
 */
export function resetPage(): void {
    visits += 1;
    // Props first: a real visit swaps both at once, and the store must not
    // take the old page's states in under the new address.
    testPage.props = {
        locale: 'en',
        translations: '/lang/en.0123456789abcdef.json',
        imageBase: 'https://image.tmdb.org/t/p',
        auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences(), password_sign_in: true } },
        mediaTypes: [],
        untouchedState: userState(),
        passwordLogin: true,
        identityProviders: [],
        errors: {},
    };
    testPage.url = `/test-page-${visits}`;
}

/** Moving to another page: what the old one held is forgotten. */
export function visitPage(props: Record<string, unknown> = {}): void {
    resetPage();
    Object.assign(testPage.props, props);
}

export function setPageProps(props: Record<string, unknown>): void {
    Object.assign(testPage.props, props);
}

/** A partial reload landing: Inertia lays the answered props over the ones it had. */
export function reloadProps(props: Record<string, unknown>): void {
    testPage.props = { ...testPage.props, ...props };
}
