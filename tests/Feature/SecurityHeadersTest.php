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

test('HTTPS pins this host alone, never its sibling subdomains', function () {
    $response = $this->get('https://localhost/up');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
});

test('plain HTTP pins nothing', function () {
    expect($this->get('http://localhost/up')->headers->has('Strict-Transport-Security'))->toBeFalse();
});
