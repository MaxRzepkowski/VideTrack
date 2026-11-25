<?php

return [

    // The front bundles Ziggy's `route()` itself (app.ts), so the page
    // carries only the route map, not a second copy of the function.
    'skip-route-function' => true,

    // The token API answers clients outside the browser; the front reaches
    // the same routes under `ui.*`, so their names would only weigh down
    // every page.
    'except' => ['api.*'],

];
