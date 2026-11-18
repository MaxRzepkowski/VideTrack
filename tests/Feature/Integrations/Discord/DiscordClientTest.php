<?php

use App\Integrations\Discord\DiscordClient;
use App\Notifications\Channels\ChannelHttpClient;
use App\Notifications\Channels\DeliveryAnswer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test("Discord's answers read as delivered, gone, throttled, refused or unavailable", function (int $status, DeliveryAnswer $answer) {
    Sleep::fake();
    Http::fake(['discord.com/*' => Http::response(null, $status)]);

    expect(app(DiscordClient::class)->send(DISCORD_WEBHOOK, ['content' => 'Hi']))->toBe($answer);
})->with([
    'no content' => [204, DeliveryAnswer::Delivered],
    'not found' => [404, DeliveryAnswer::Gone],
    'unauthorized' => [401, DeliveryAnswer::Gone],
    'too many' => [429, DeliveryAnswer::Throttled],
    'malformed' => [400, DeliveryAnswer::Refused],
    'bad gateway' => [502, DeliveryAnswer::Unavailable],
]);

test('an answered refusal is asked once, an outage a few times', function () {
    Sleep::fake();
    Http::fake([
        'discord.com/api/webhooks/1/*' => Http::response(null, 400),
        'discord.com/api/webhooks/2/*' => Http::response(null, 503),
    ]);

    app(DiscordClient::class)->send('https://discord.com/api/webhooks/1/a', ['content' => 'Hi']);
    Http::assertSentCount(1);

    app(DiscordClient::class)->send('https://discord.com/api/webhooks/2/b', ['content' => 'Hi']);
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/2/')))->toHaveCount(ChannelHttpClient::TRIES);
});
