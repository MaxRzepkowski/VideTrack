<?php

use App\Integrations\OutgoingWebhooks\WebhookEventType;
use App\Integrations\OutgoingWebhooks\WebhookMessage;

test('the body names the event, its time and its data, its text as written', function () {
    $message = new WebhookMessage('id-1', WebhookEventType::Test, '2026-10-10T12:00:00+00:00', ['title' => 'Amélie', 'url' => 'https://tracker.example.com/movie/1']);

    expect($message->body())->toBe('{"type":"webhook.test","timestamp":"2026-10-10T12:00:00+00:00","data":{"title":"Amélie","url":"https://tracker.example.com/movie/1"}}');
});
