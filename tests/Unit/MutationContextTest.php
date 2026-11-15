<?php

use App\Library\MutationContext;
use App\Library\MutationSource;

test('defaults to the ui source', function () {
    expect((new MutationContext)->current()->value())->toBe('ui');
});

test('run exposes its source only for the duration of the callback', function () {
    $context = new MutationContext;

    $inside = $context->run(MutationSource::api(), fn () => $context->current()->value());

    expect($inside)->toBe('api')
        ->and($context->current()->value())->toBe('ui');
});

test('run returns the callback result', function () {
    expect((new MutationContext)->run(MutationSource::import('trakt'), fn () => 42))->toBe(42);
});

test('nested runs restore the outer source', function () {
    $context = new MutationContext;
    $seen = [];

    $context->run(MutationSource::import('trakt'), function () use ($context, &$seen) {
        $seen[] = $context->current()->value();

        $context->run(MutationSource::scrobble('jellyfin'), function () use ($context, &$seen) {
            $seen[] = $context->current()->value();
        });

        $seen[] = $context->current()->value();
    });

    expect($seen)->toBe(['import:trakt', 'scrobble:jellyfin', 'import:trakt'])
        ->and($context->current()->value())->toBe('ui');
});

test('a throwing callback does not leak its source', function () {
    $context = new MutationContext;

    expect(fn () => $context->run(
        MutationSource::scrobble('jellyfin'),
        fn () => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class);

    expect($context->current()->value())->toBe('ui');
});
