import { apiReferenceOptions } from '@/lib/apiDocs';
import { sessionFetch } from '@/lib/http';
import { describe, expect, it } from 'vitest';

describe('apiReferenceOptions', () => {
    const options = apiReferenceOptions({ openapi: '3.1.0' }) as Record<string, unknown>;

    it('renders the document it is given, in the app theme', () => {
        expect(options.content).toEqual({ openapi: '3.1.0' });
        expect(options.theme).toBe('none');
        expect(options.forceDarkModeState).toBe('dark');
    });

    it('reaches nothing beyond this server', () => {
        expect(options.proxyUrl).toBeUndefined();
        expect(options.withDefaultFonts).toBe(false);
        expect(options.hideClientButton).toBe(true);
        expect(options.showDeveloperTools).toBe('never');
        expect(options.telemetry).toBe(false);
        expect(options.agent).toEqual({ disabled: true });
        expect(options.mcp).toEqual({ disabled: true });
    });

    it('sends "Try it" as the signed-in session', () => {
        expect(options.customFetch).toBe(sessionFetch);
    });
});
