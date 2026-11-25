import type { route as ziggyRoute } from 'ziggy-js';

declare global {
    // Ziggy's own function, set as the global by app.ts.
    var route: typeof ziggyRoute;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof ziggyRoute;
    }
}
