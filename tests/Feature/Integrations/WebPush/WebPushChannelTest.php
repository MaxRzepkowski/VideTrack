<?php

use App\Actions\Users\TestChannel;
use App\Integrations\WebPush\WebPushChannel;
use App\Integrations\WebPush\WebPushClient;
use App\Integrations\WebPush\WebPushMessages;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\Data\DigestLine;
use App\Notifications\Channels\Data\DigestSection;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Web push: a message encrypted to each browser of the channel and signed
 * with the instance's keys, handed to the browser's push service.
 */

test('without a key pair the instance offers no web push', function () {
    expect(app(ChannelRegistry::class)->has(WebPushChannel::CODE))->toBeFalse();
});

test('with a key pair the channel is offered, but never to the form', function () {
    withWebPushKeys();

    expect(app(ChannelRegistry::class)->kindOf(WebPushChannel::CODE, WebPushChannel::CODE)?->fromDevices)->toBeTrue();
});

test('a message goes to every browser, encrypted and signed', function () {
    $vapid = withWebPushKeys();
    Http::fake(['push.example.com/*' => Http::response(status: 201)]);
    $user = User::factory()->create();
    pushDevice($user, $vapid, 'https://push.example.com/send/one');
    $channel = pushDevice($user, $vapid, 'https://push.example.com/send/two')->channel;

    expect(app(TestChannel::class)->execute($channel))->toBe(DeliveryAnswer::Delivered);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://push.example.com/send/one'
        && str_starts_with($request->header('Authorization')[0] ?? '', 'vapid t=')
        && str_contains($request->header('Authorization')[0] ?? '', 'k='.$vapid->publicKey)
        && $request->header('Content-Encoding') === ['aes128gcm']
        && $request->header('TTL') === [(string) WebPushClient::TTL_SECONDS]
        // Encrypted: the words never travel in the clear.
        && ! str_contains($request->body(), 'test'));
    expect($channel->fresh()?->last_failure)->toBeNull();
});

test('a browser the push service no longer knows is forgotten, the others kept', function () {
    $vapid = withWebPushKeys();
    Http::fake([
        'push.example.com/send/one' => Http::response(status: 410),
        'push.example.com/send/two' => Http::response(status: 201),
    ]);
    $user = User::factory()->create();
    pushDevice($user, $vapid, 'https://push.example.com/send/one');
    $kept = pushDevice($user, $vapid, 'https://push.example.com/send/two');

    expect(app(TestChannel::class)->execute($kept->channel))->toBe(DeliveryAnswer::Delivered)
        ->and(PushSubscription::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('the channel goes with the last browser the push service no longer knows', function () {
    $vapid = withWebPushKeys();
    Http::fake(['push.example.com/*' => Http::response(status: 404)]);
    $user = User::factory()->create();
    $device = pushDevice($user, $vapid);

    expect(app(TestChannel::class)->execute($device->channel))->toBe(DeliveryAnswer::Gone)
        ->and(PushSubscription::query()->exists())->toBeFalse()
        ->and($user->notificationChannels()->exists())->toBeFalse();
});

test('a browser subscribed with an older key pair is not sent to', function () {
    $vapid = withWebPushKeys();
    Http::fake();
    $device = pushDevice(User::factory()->create(), $vapid);
    $device->update(['vapid_key' => 'an-older-key']);

    expect(app(TestChannel::class)->execute($device->channel))->toBe(DeliveryAnswer::Gone);

    Http::assertNothingSent();
    expect($device->fresh())->not->toBeNull();
});

test('a push service that is down leaves the message to be tried again', function () {
    $vapid = withWebPushKeys();
    Http::fake(['push.example.com/*' => Http::response(status: 503)]);
    $device = pushDevice(User::factory()->create(), $vapid);

    expect(app(TestChannel::class)->execute($device->channel))->toBe(DeliveryAnswer::Unavailable);
});

test('a digest is one push of its lines, cut short past the limit, that opens the notifications page', function () {
    $lines = array_map(
        fn (int $number): DigestLine => new DigestLine("Show {$number}", 'https://videtrack.test/show', 'New episode', $number === 1 ? 'S1 E1' : null),
        range(1, WebPushMessages::DIGEST_LINES + 2),
    );

    $message = app(WebPushMessages::class)->digest(new ChannelDigest('Your daily digest', [new DigestSection('Shows', '📺', $lines)], 'Enjoy'));
    $body = explode("\n", (string) $message->body);

    expect($message->title)->toBe('Your daily digest')
        ->and($message->url)->toBe(route('notifications.index'))
        ->and($body)->toHaveCount(WebPushMessages::DIGEST_LINES + 1)
        ->and($body[0])->toBe('Show 1: New episode: S1 E1')
        ->and($body[1])->toBe('Show 2: New episode')
        ->and(end($body))->toBe('…');
});

test('a message shows its cover, or the app icon without one', function () {
    $messages = app(WebPushMessages::class);
    $message = fn (?string $image) => new ChannelMessage('Dune', 'Out now', 'https://videtrack.test/movie/1', $image, 0, now()->toIso8601String());

    expect($messages->message($message('https://image.example.com/dune.jpg'))->icon)->toBe('https://image.example.com/dune.jpg')
        ->and($messages->message($message(null))->icon)->toEndWith('/'.WebPushMessages::ICON)
        ->and($messages->message($message(null))->timestamp)->toBe(now()->getTimestampMs());
});
