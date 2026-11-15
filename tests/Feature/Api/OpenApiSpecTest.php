<?php

use Illuminate\Support\Facades\Route;

/**
 * The committed spec is what a client reads before it ever talks to an
 * instance, so it has to describe every route v1 actually serves. A route
 * added without re-exporting fails here.
 */
test('the committed spec covers every v1 route', function () {
    $spec = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true);

    expect($spec)->toBeArray()->and($spec['paths'] ?? null)->toBeArray();

    $documented = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $served = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        // Scramble documents paths relative to the api_path prefix.
        $path = '/'.substr($route->uri(), strlen('api/v1/'));

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $served[] = "{$method} {$path}";
        }
    }

    sort($served);
    sort($documented);

    expect(array_diff($served, $documented))->toBe([]);
});

test('the spec says every call carries a bearer token', function () {
    $spec = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true);

    expect($spec['components']['securitySchemes'] ?? [])->not->toBeEmpty()
        ->and($spec['security'] ?? [])->not->toBeEmpty();
});

test('the instance serves no docs pages, only the API itself', function () {
    $this->get('/docs/api')->assertNotFound();
    $this->get('/docs/api.json')->assertNotFound();
    $this->get('/_scramble/dev-tools/devtools.js')->assertNotFound();
});
