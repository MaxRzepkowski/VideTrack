<?php

use Illuminate\Testing\TestResponse;
use Inertia\Inertia;

/*
 * Requests a test makes of a page the way its front end does.
 */

/**
 * The deferred follow-up request of a page, asking for its props (a comma
 * list). The asset version is known once the test has drawn the page itself.
 */
function deferredProp(string $url, string $component, string $prop): TestResponse
{
    return test()->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $prop,
    ]);
}
