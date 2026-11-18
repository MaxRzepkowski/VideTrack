<?php

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Integrations\OutgoingWebhooks\WebhookEventType;
use App\Integrations\OutgoingWebhooks\WebhookMessage;

test('the body names the event, its time and its data, its text as written', function () {
    $message = new WebhookMessage('id-1', WebhookEventType::Test, '2026-10-10T12:00:00+00:00', ['title' => 'Amélie', 'url' => 'https://tracker.example.com/movie/1']);

    expect($message->body())->toBe('{"type":"webhook.test","timestamp":"2026-10-10T12:00:00+00:00","data":{"title":"Amélie","url":"https://tracker.example.com/movie/1"}}');
});

test('a change to the library says where it came from, after its time', function () {
    $message = new WebhookMessage('id-2', WebhookEventType::RatingChanged, '2026-10-10T12:00:00+00:00', [], 'scrobble:jellyfin');

    expect(array_keys(json_decode($message->body(), true)))->toBe(['type', 'timestamp', 'source', 'data'])
        ->and(json_decode($message->body(), true)['source'])->toBe('scrobble:jellyfin');
});

test('every event but the test belongs to a group a receiver can tick', function () {
    foreach (WebhookEventType::cases() as $type) {
        expect($type->group() === null)->toBe($type === WebhookEventType::Test);
    }

    expect(WebhookEventType::PlayRecorded->group())->toBe(WebhookEventGroup::Activity)
        ->and(WebhookEventType::FavoriteChanged->group())->toBe(WebhookEventGroup::Favorites)
        ->and(WebhookEventType::NotificationCreated->group())->toBe(WebhookEventGroup::Notifications)
        // Every group a receiver can tick tells of something.
        ->and(collect(WebhookEventType::cases())->map(fn (WebhookEventType $type) => $type->group())->filter()->unique()->count())->toBe(count(WebhookEventGroup::cases()));
});
