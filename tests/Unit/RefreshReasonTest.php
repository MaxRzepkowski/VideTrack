<?php

use App\Tasks\CatalogRefresh\RefreshReason;

test('the queue empties in the order: active, changed, cycle', function () {
    $ranked = RefreshReason::cases();
    usort($ranked, fn (RefreshReason $a, RefreshReason $b): int => $a->rank() <=> $b->rank());

    expect($ranked)->toBe([RefreshReason::Active, RefreshReason::Changed, RefreshReason::Cycle]);
});

test('of two reasons the more urgent one stands', function (RefreshReason $reason, ?RefreshReason $other, RefreshReason $stands) {
    expect($reason->strongerOf($other))->toBe($stands);
})->with([
    'nothing before' => [RefreshReason::Cycle, null, RefreshReason::Cycle],
    'a lesser one before' => [RefreshReason::Active, RefreshReason::Cycle, RefreshReason::Active],
    'a stronger one before' => [RefreshReason::Cycle, RefreshReason::Changed, RefreshReason::Changed],
]);
