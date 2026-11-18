<?php

use App\Integrations\OutgoingWebhooks\WebhookSignature;

/*
 * The vector the Standard Webhooks libraries test themselves against
 * (github.com/standard-webhooks/standard-webhooks, its PHP library's
 * WebhookTest): public test values, nobody's secret. The key is kept, as
 * there, without its prefix.
 */
const VECTOR_KEY = 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'; // gitleaks:allow

const VECTOR_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek'; // gitleaks:allow

test('a message is signed as the Standard Webhooks libraries check it', function () {
    expect(WebhookSignature::sign(WebhookSignature::PREFIX.VECTOR_KEY, VECTOR_ID, 1614265330, '{"test": 2432232314}'))
        ->toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
});

test('a new secret is one the libraries read, and only such a secret passes', function (string $secret, bool $valid) {
    expect(WebhookSignature::isValidSecret($secret))->toBe($valid);
})->with([
    'the standard vector' => [WebhookSignature::PREFIX.VECTOR_KEY, true],
    'without the prefix' => [VECTOR_KEY, false],
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
