<?php

use App\Integrations\WebPush\WebPushChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The browsers push reaches: one switched on from the settings page, one
 * taken off the list, one renewed by itself after the instance's keys
 * changed. The first brings the web push channel, the last takes it away.
 */

test('the first browser brings a channel that delivers at once, and the page lists it', function () {
    $vapid = withWebPushKeys();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.channels.index'))
        ->withHeader('User-Agent', 'Mozilla/5.0 (Android 15; Mobile; rv:140.0) Gecko/140.0 Firefox/140.0')
        ->post(route('settings.channels.devices.store'), browserSubscription())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.channels.index'));

    $channel = $user->notificationChannels()->sole();
    $device = PushSubscription::query()->sole();

    expect($channel->driver)->toBe(WebPushChannel::CODE)
        ->and($channel->mode)->toBe(ChannelMode::Instant)
        ->and($channel->label)->toBe(__('settings.browser_notifications'))
        ->and($device->notification_channel_id)->toBe($channel->id)
        ->and($device->vapid_key)->toBe($vapid->publicKey)
        ->and($device->user_agent)->toContain('Firefox/140.0')
        // The address and the keys are credentials.
        ->and((string) DB::table('push_subscriptions')->value('endpoint'))->not->toContain('push.example.com');

    $this->actingAs($user)->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page
        ->where('page.push.publicKey', $vapid->publicKey)
        ->has('page.push.devices', 1)
        ->where('page.push.devices.0.fingerprint', PushSubscription::hashOf('https://push.example.com/send/one'))
        ->where('page.push.devices.0.stale', false)
        ->missing('page.push.devices.0.endpoint')
        ->where('page.channels.0.kindLabel', __('settings.browser_notifications'))
        // Devices make the channel, the form never does.
        ->where('page.kinds', fn ($kinds) => collect($kinds)->doesntContain('driver', WebPushChannel::CODE)));
});

test('a second browser joins the same channel, and one subscribed again keeps its row', function () {
    withWebPushKeys();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('settings.channels.devices.store'), browserSubscription('https://push.example.com/send/one'));
    $this->actingAs($user)->post(route('settings.channels.devices.store'), browserSubscription('https://push.example.com/send/two'));
    $first = PushSubscription::query()->orderBy('id')->firstOrFail();
    $again = browserSubscription('https://push.example.com/send/one');
    $this->actingAs($user)->post(route('settings.channels.devices.store'), $again);

    expect($user->notificationChannels()->count())->toBe(1)
        ->and(PushSubscription::query()->count())->toBe(2)
        ->and($first->fresh()?->public_key)->toBe($again['public_key']);
});

test('a browser another account subscribed moves to the one at it now', function () {
    $vapid = withWebPushKeys();
    $before = User::factory()->create();
    pushDevice($before, $vapid);
    $now = User::factory()->create();

    $this->actingAs($now)->post(route('settings.channels.devices.store'), browserSubscription())->assertSessionHasNoErrors();

    expect(PushSubscription::query()->sole()->channel->user_id)->toBe($now->id)
        ->and($before->notificationChannels()->exists())->toBeFalse();
});

test('the instance posts to no address of the server itself, and to none without https', function (string $endpoint) {
    withWebPushKeys();

    $this->actingAs(User::factory()->create())
        ->post(route('settings.channels.devices.store'), browserSubscription($endpoint))
        ->assertSessionHasErrors('endpoint');

    expect(PushSubscription::query()->exists())->toBeFalse();
})->with(['https://localhost/push', 'https://apprise/push', 'http://push.example.com/send/one']);

test('an instance without keys has no push: no devices to add, none on the page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('settings.channels.devices.store'), browserSubscription())->assertNotFound();
    $this->actingAs($user)->get(route('settings.channels.devices.key'))->assertNotFound();
    $this->actingAs($user)->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page->where('page.push', null));
});

test('the form adds no web push channel: browsers do', function () {
    withWebPushKeys();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.channels.store'), ['driver' => WebPushChannel::CODE, 'kind' => WebPushChannel::CODE, 'config' => []])
        ->assertSessionHasErrors('kind');

    expect($user->notificationChannels()->exists())->toBeFalse();
});

test('a browser taken off the list stops getting pushes, and the channel goes with the last', function () {
    $vapid = withWebPushKeys();
    $user = User::factory()->create();
    $first = pushDevice($user, $vapid, 'https://push.example.com/send/one');
    $last = pushDevice($user, $vapid, 'https://push.example.com/send/two');

    $this->actingAs($user)->delete(route('settings.channels.devices.destroy', $first))->assertRedirect();
    expect($user->notificationChannels()->exists())->toBeTrue();

    $this->actingAs($user)->delete(route('settings.channels.devices.destroy', $last))->assertRedirect();
    expect(PushSubscription::query()->exists())->toBeFalse()
        ->and($user->notificationChannels()->exists())->toBeFalse();
});

test('somebody else\'s browser is not there to remove', function () {
    $device = pushDevice(User::factory()->create(), withWebPushKeys());

    $this->actingAs(User::factory()->create())->delete(route('settings.channels.devices.destroy', $device))->assertNotFound();

    expect($device->fresh())->not->toBeNull();
});

test('the app learns the key its browsers subscribe with', function () {
    $vapid = withWebPushKeys();

    $this->actingAs(User::factory()->create())
        ->getJson(route('settings.channels.devices.key'))
        ->assertOk()
        ->assertExactJson(['data' => ['publicKey' => $vapid->publicKey]]);
});

test('after new keys a browser of the user renews its subscription in place of the old one', function () {
    $vapid = withWebPushKeys();
    $user = User::factory()->create();
    $old = pushDevice($user, $vapid, 'https://push.example.com/send/old');
    $old->update(['vapid_key' => 'an-older-key']);

    $this->actingAs($user)
        ->postJson(route('settings.channels.devices.renew'), [...browserSubscription('https://push.example.com/send/new'), 'previous_endpoint' => 'https://push.example.com/send/old'])
        ->assertNoContent();

    $device = PushSubscription::query()->sole();
    expect($device->endpoint)->toBe('https://push.example.com/send/new')
        ->and($device->vapid_key)->toBe($vapid->publicKey)
        ->and($device->channel->is($old->channel))->toBeTrue();
});

test('a renewal takes over no browser of another account', function () {
    $vapid = withWebPushKeys();
    pushDevice(User::factory()->create(), $vapid, 'https://push.example.com/send/old');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.channels.devices.renew'), [...browserSubscription('https://push.example.com/send/new'), 'previous_endpoint' => 'https://push.example.com/send/old'])
        ->assertNotFound();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and($user->notificationChannels()->exists())->toBeFalse();
});
