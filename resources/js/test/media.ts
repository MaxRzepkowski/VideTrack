import { vi } from 'vitest';

/**
 * The screen a test runs on, as the media queries a component asks see it:
 * each query matches when `matches(query)` says so. `vi.unstubAllGlobals()`
 * puts the real one back.
 */
export function stubMediaQueries(matches: (query: string) => boolean): void {
    vi.stubGlobal('matchMedia', (query: string) => ({
        matches: matches(query),
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    }));
}
