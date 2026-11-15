<?php

use App\Library\Settlement;

test('a leaf is settled by an entry of its own or by its season being dropped', function () {
    $run = new Settlement(completions: [1 => 1, 2 => 0], drops: [20 => '2026-10-01']);

    expect($run->settles(1, 10))->toBeTrue()
        ->and($run->settles(2, 10))->toBeTrue()
        ->and($run->settles(3, 20))->toBeTrue()
        ->and($run->settles(4, 10))->toBeFalse()
        ->and($run->settles(5, null))->toBeFalse()
        ->and($run->watched(1))->toBeTrue()
        ->and($run->watched(2))->toBeFalse();
});

test('a container is completed when every aired leaf is settled and one was watched', function () {
    $watchedOne = new Settlement(completions: [1 => 1, 2 => 0], drops: [20 => true]);
    $onlySkipped = new Settlement(completions: [2 => 0], drops: [20 => true]);

    expect($watchedOne->completes([1 => 10, 2 => 10, 3 => 20]))->toBeTrue()
        ->and($watchedOne->completes([1 => 10, 4 => 10]))->toBeFalse()
        ->and($onlySkipped->completes([2 => 10, 3 => 20]))->toBeFalse()
        ->and($watchedOne->completes([]))->toBeFalse();
});

test('a container marked itself no longer counts its own drop', function () {
    $run = (new Settlement(completions: [], drops: [20 => true]))->withoutDropOf(20);

    expect($run->settles(3, 20))->toBeFalse();
});
