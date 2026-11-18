<?php

use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryRetry;

test('a busy receiver is tried again a few seconds on, every try the queue allows', function () {
    $delay = DeliveryRetry::after(DeliveryAnswer::Throttled, 1);

    expect($delay)->toBeInt()->toBeGreaterThan(0)->toBeLessThan(DeliveryRetry::UNAVAILABLE_AFTER[0])
        ->and(DeliveryRetry::after(DeliveryAnswer::Throttled, DeliveryRetry::TRIES - 1))->not->toBeNull()
        ->and(DeliveryRetry::after(DeliveryAnswer::Throttled, DeliveryRetry::TRIES))->toBeNull();
});

test('a receiver that was not there is waited out longer, a few times only', function () {
    expect(DeliveryRetry::after(DeliveryAnswer::Unavailable, 1))->toBe(DeliveryRetry::UNAVAILABLE_AFTER[0])
        ->and(DeliveryRetry::after(DeliveryAnswer::Unavailable, 2))->toBe(DeliveryRetry::UNAVAILABLE_AFTER[1])
        ->and(DeliveryRetry::after(DeliveryAnswer::Unavailable, count(DeliveryRetry::UNAVAILABLE_AFTER) + 1))->toBeNull();
});

test('an answer that will not change stands', function (DeliveryAnswer $answer) {
    expect(DeliveryRetry::after($answer, 1))->toBeNull();
})->with([DeliveryAnswer::Delivered, DeliveryAnswer::Gone, DeliveryAnswer::Refused]);
