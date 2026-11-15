<?php

// Every response carries the baseline headers; pages get a script policy
// whose nonce is the one their own inline scripts wear.

test('a page allows only its own scripts, the inline ones by this request\'s nonce', function () {
    $response = $this->get('/login')->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);

    expect($policy)->toContain("object-src 'none'", "frame-ancestors 'none'", "script-src 'self'")
        ->and($nonce[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$nonce[1].'"');
});

test('responses outside the web pages carry the headers too', function () {
    $this->getJson('/api/v1/me')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Permissions-Policy');
});

test('the API docs run only the bundled viewer from this origin, with no script of their own', function () {
    // Rendering the docs parses the whole codebase (see OpenApiSpecTest), so
    // the view the app overrides is read as it stands. The viewer arrives
    // through @vite, which carries the request's nonce; the page itself holds
    // only data, which no script policy has to allow.
    $view = (string) file_get_contents(resource_path('views/vendor/scramble/scalar.blade.php'));

    preg_match_all('/<script\b[^>]*>/', $view, $scripts);

    expect(config('scramble.renderer'))->toBe('scalar')
        ->and($view)->toContain("@vite('resources/js/api-docs.ts')")
        ->and($view)->not->toMatch('/(src|href)="https?:/')
        ->and($scripts[0])->toBe(['<script type="application/json" id="api-spec">']);
});

test('HTTPS pins this host alone, never its sibling subdomains', function () {
    $response = $this->get('https://localhost/up');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
});

test('plain HTTP pins nothing', function () {
    expect($this->get('http://localhost/up')->headers->has('Strict-Transport-Security'))->toBeFalse();
});
