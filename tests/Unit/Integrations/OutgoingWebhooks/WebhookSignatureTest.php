<?php

use App\Integrations\OutgoingWebhooks\WebhookSignature;

test('a message is signed as the Standard Webhooks libraries check it', function () {
    expect(WebhookSignature::sign(WEBHOOK_SECRET, 'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}'))
        ->toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
});

test('a new secret is one the libraries read, and only such a secret passes', function (string $secret, bool $valid) {
    expect(WebhookSignature::isValidSecret($secret))->toBe($valid);
})->with([
    'the standard vector' => [WEBHOOK_SECRET, true],
    'without the prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', false],
    'not base64' => ['whsec_not base64!', false],
    'too short' => ['whsec_'.base64_encode(str_repeat('a', WebhookSignature::MIN_BYTES - 1)), false],
    'too long' => ['whsec_'.base64_encode(str_repeat('a', WebhookSignature::MAX_BYTES + 1)), false],
    'the prefix alone' => ['whsec_', false],
]);

test('every new secret is valid and none repeats', function () {
    $secrets = [WebhookSignature::newSecret(), WebhookSignature::newSecret()];

    expect(WebhookSignature::isValidSecret($secrets[0]))->toBeTrue()
        ->and($secrets[0])->not->toBe($secrets[1]);
});
