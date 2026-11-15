<?php

use App\Library\MutationContext;

test('the container hands out one shared instance', function () {
    expect(app(MutationContext::class))->toBe(app(MutationContext::class));
});

test('a resolved context starts on the ui source', function () {
    expect(app(MutationContext::class)->current()->value())->toBe('ui');
});
