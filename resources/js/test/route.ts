/**
 * A stand-in for Ziggy's `route()`: the app's routes live in PHP, so a test
 * asserts which route and parameters a link asks for, not the URL Laravel
 * would build. `route('users.show', { user: 'alex' })` → `/users.show/alex`.
 */
export function route(name: string, params: Record<string, string | number> | string | number = {}): string {
    const values = typeof params === 'object' ? Object.values(params) : [params];

    return ['', name, ...values.map(String)].join('/');
}
