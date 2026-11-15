// The API docs page (/docs/api): its own entry, so the viewer's weight is
// paid only there. The document comes inlined by the page.
import '@fontsource-variable/roboto-flex';
import '@scalar/api-reference/style.css';
import '../css/api-docs.css';

import { apiReferenceOptions } from '@/lib/apiDocs';
import { createApiReference } from '@scalar/api-reference';

const spec = document.getElementById('api-spec')?.textContent;

if (!spec) {
    throw new Error('The API docs page carries no OpenAPI document.');
}

createApiReference('#api-docs', apiReferenceOptions(JSON.parse(spec) as Record<string, unknown>));
