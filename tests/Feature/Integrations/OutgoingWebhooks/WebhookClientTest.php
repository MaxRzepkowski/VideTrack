<?php

use App\Integrations\OutgoingWebhooks\WebhookClient;
use App\Integrations\OutgoingWebhooks\WebhookEventType;
use App\Integrations\OutgoingWebhooks\WebhookMessage;
use App\Integrations\OutgoingWebhooks\WebhookSignature;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const WEBHOOK_URL = OutgoingWebhookFactory::URL;

function testMessage(): WebhookMessage
{
    return new WebhookMessage('msg-1', WebhookEventType::Test, '2026-10-10T12:00:00+00:00', ['message' => 'Hi']);
}

test('a message goes signed under its own id and the time of the try, as JSON', function () {
    Http::fake([WEBHOOK_URL => Http::response(null, 204)]);

    app(WebhookClient::class)->send(WEBHOOK_URL, WEBHOOK_SECRET, testMessage());

    Http::assertSent(fn (Request $request): bool => $request->header('webhook-id')[0] === 'msg-1'
        && (int) $request->header('webhook-timestamp')[0] === now()->getTimestamp()
        && $request->header('webhook-signature')[0] === WebhookSignature::sign(WEBHOOK_SECRET, 'msg-1', now()->getTimestamp(), $request->body())
        && $request->header('Content-Type')[0] === 'application/json'
        && $request->body() === testMessage()->body()
        && $request->header('User-Agent')[0] === config('tracker.http.user_agent'));
});

test("the receiver's answers read as delivered, gone, throttled, refused or unavailable", function (int $status, DeliveryAnswer $answer) {
    Sleep::fake();
    Http::fake([WEBHOOK_URL => Http::response(null, $status)]);

    expect(app(WebhookClient::class)->send(WEBHOOK_URL, WEBHOOK_SECRET, testMessage()))->toBe($answer);
})->with([
    'ok' => [200, DeliveryAnswer::Delivered],
    'accepted' => [202, DeliveryAnswer::Delivered],
    'not found' => [404, DeliveryAnswer::Gone],
    'gone' => [410, DeliveryAnswer::Gone],
    'too many' => [429, DeliveryAnswer::Throttled],
    'unauthorized' => [401, DeliveryAnswer::Refused],
    'moved' => [301, DeliveryAnswer::Refused],
    // Spaced out by the queue's longer waits: a receiver behind a proxy that is down.
    'bad gateway' => [502, DeliveryAnswer::Unavailable],
    'gateway timeout' => [504, DeliveryAnswer::Unavailable],
]);

test('an answered refusal is asked once, an outage a few times', function () {
    Sleep::fake();
    $message = testMessage();
    Http::fake(['n8n.example.com/refused' => Http::response(null, 400), 'n8n.example.com/down' => Http::response(null, 503)]);

    app(WebhookClient::class)->send('http://n8n.example.com/refused', WEBHOOK_SECRET, $message);
    Http::assertSentCount(1);

    app(WebhookClient::class)->send('http://n8n.example.com/down', WEBHOOK_SECRET, $message);
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/down')))->toHaveCount(DeliveryHttpClient::TRIES);
});
