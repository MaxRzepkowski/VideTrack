<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Only Vue files are pages here, so a test naming a component that has no
    | .vue file fails instead of matching a stray .ts module of that name.
    |
    */

    'pages' => [
        'paths' => [
            resource_path('js/pages'),
        ],
        'extensions' => ['vue'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-side rendering
    |--------------------------------------------------------------------------
    |
    | The app renders in the browser and ships no SSR bundle. Left on, Inertia
    | would ask the Vite dev server to pre-render every page and fall back
    | after a failed request each time.
    |
    */

    'ssr' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools
    |--------------------------------------------------------------------------
    |
    | Off even locally: the recorder writes every page's props to storage,
    | the signed-in account's e-mail and library included, and serves them
    | on an unauthenticated route.
    |
    */

    'devtools' => [
        'enabled' => false,
    ],

];
