<?php

use App\Integrations\OutgoingWebhooks\WebhookClient;
use App\Integrations\OutgoingWebhooks\WebhookEventType;
use App\Integrations\OutgoingWebhooks\WebhookMessage;
use App\Integrations\OutgoingWebhooks\WebhookSignature;
use App\Models\OutgoingWebhook;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const WEBHOOK_URL = OutgoingWebhookFactory::URL;

/** One secret for the whole file: a test signs with it and checks against it. */
function clientSecret(): string
{
    static $secret = null;

    return $secret ??= WebhookSignature::newSecret();
}

/** A message sent to a webhook of an ordinary account at $url, signed with the file's secret. */
function sendToWebhook(string $url, WebhookMessage $message): DeliveryAnswer
{
    $webhook = OutgoingWebhook::factory()->create(['url' => $url, 'secret' => clientSecret()]);

    return app(WebhookClient::class)->send($webhook, $webhook->user, $message);
}

function testMessage(): WebhookMessage
{
    return new WebhookMessage('msg-1', WebhookEventType::Test, '2026-10-10T12:00:00+00:00', ['message' => 'Hi']);
}

test('a message goes signed under its own id and the time of the try, as JSON', function () {
    Http::fake([WEBHOOK_URL => Http::response(null, 204)]);

    sendToWebhook(WEBHOOK_URL, testMessage());

    Http::assertSent(fn (Request $request): bool => $request->header('webhook-id')[0] === 'msg-1'
        && (int) $request->header('webhook-timestamp')[0] === now()->getTimestamp()
        && $request->header('webhook-signature')[0] === WebhookSignature::sign(clientSecret(), 'msg-1', now()->getTimestamp(), $request->body())
        && $request->header('Content-Type')[0] === 'application/json'
        && $request->body() === testMessage()->body()
        && $request->header('User-Agent')[0] === config('tracker.http.user_agent'));
});

test("the receiver's answers read as delivered, gone, throttled, refused or unavailable", function (int $status, DeliveryAnswer $answer) {
    Sleep::fake();
    Http::fake([WEBHOOK_URL => Http::response(null, $status)]);

    expect(sendToWebhook(WEBHOOK_URL, testMessage()))->toBe($answer);
})->with([
    'ok' => [200, DeliveryAnswer::Delivered],
    'accepted' => [202, DeliveryAnswer::Delivered],
    'not found' => [404, DeliveryAnswer::Gone],
    'closed for good' => [410, DeliveryAnswer::Closed],
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

    sendToWebhook('http://n8n.example.com/refused', $message);
    Http::assertSentCount(1);

    sendToWebhook('http://n8n.example.com/down', $message);
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/down')))->toHaveCount(DeliveryHttpClient::TRIES);
});

test('a message goes to the IP the check approved and follows no redirect', function () {
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(null, 307, ['Location' => 'http://localhost:2019/stop']);
    });

    expect(sendToWebhook(WEBHOOK_URL, testMessage()))->toBe(DeliveryAnswer::Refused)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['n8n.example.com:80:93.184.215.14']);
    Http::assertSentCount(1);
});

test('an address the owner may no longer reach is not called', function (string $url, DeliveryAnswer $answer) {
    Http::fake();

    // Saved some other way, or a host taken off the operator's list.
    expect(sendToWebhook($url, testMessage()))->toBe($answer);
    Http::assertNothingSent();
})->with([
    'the server itself' => ['http://localhost:2019/stop', DeliveryAnswer::NotAllowed],
    'a container beside it' => ['http://apprise:8000/notify', DeliveryAnswer::NotAllowed],
    'a name that resolves to nothing' => ['http://nowhere.example.com/hook', DeliveryAnswer::Unavailable],
]);
