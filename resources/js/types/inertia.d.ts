import type { SharedData } from '@/types';

// Every page carries the shared props, so Inertia's page props know them by
// name — `page.props.locale` is a string, not an unknown to cast.
declare module '@inertiajs/core' {
    interface PageProps extends SharedData {
        // Inertia's own: a page's other props, which the code reading them declares.
        [key: string]: unknown;
    }
}
