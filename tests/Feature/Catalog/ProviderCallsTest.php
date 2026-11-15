<?php

use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderCalls;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;

test('a section with nothing at the provider is empty, not degraded', function () {
    $calls = new ProviderCalls;

    expect($calls->attempt(fn () => throw new ItemNotFoundException('none'), []))->toBe([])
        ->and($calls->failed())->toBeFalse();
});

test('an outage or a refusal degrades the page to its fallback', function (Throwable $failure) {
    $calls = new ProviderCalls;

    expect($calls->attempt(fn () => throw $failure, 'fallback'))->toBe('fallback')
        ->and($calls->failed())->toBeTrue();
})->with([
    'outage' => [new ProviderException('down')],
    'refusal' => [new ProviderRejected('no')],
]);
