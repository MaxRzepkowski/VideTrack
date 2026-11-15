import { sessionFetch } from '@/lib/http';
import type { createApiReference } from '@scalar/api-reference';

type ApiReferenceOptions = Parameters<typeof createApiReference>[1];

/**
 * How the API docs viewer runs on an instance: dark, in the app's own colours
 * (css/api-docs.css), and with nothing that reaches beyond this server. The
 * viewer can route "Try it" through a hosted proxy, pull fonts, open an AI
 * agent or an MCP link, and hand requests to its hosted client; the page's
 * Content-Security-Policy would block all of them, so they are switched off
 * rather than left as buttons that fail.
 */
export function apiReferenceOptions(spec: Record<string, unknown>): ApiReferenceOptions {
    return {
        content: spec,
        theme: 'none',
        forceDarkModeState: 'dark',
        hideDarkModeToggle: true,
        withDefaultFonts: false,
        hideClientButton: true,
        showDeveloperTools: 'never',
        telemetry: false,
        agent: { disabled: true },
        mcp: { disabled: true },
        customFetch: sessionFetch,
    };
}
