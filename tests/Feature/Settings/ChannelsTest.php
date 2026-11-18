<?php

use App\Integrations\Discord\DiscordChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\DailyDigests;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The notification channels page: adding, changing, switching off, testing
 * and removing a channel. Its address is a credential — stored encrypted,
 * never sent back to a page, never kept in the session.
 */

test('the page lists the channels, never their addresses, and the kinds to add', function () {
    $user = User::factory()->create();
    discordChannel($user)->update(['label' => 'Movie night', 'last_failure' => DeliveryAnswer::Gone]);

    $this->actingAs($user)->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/Channels')
        ->has('page.channels', 1)
        ->where('page.channels.0.label', 'Movie night')
        ->where('page.channels.0.kindLabel', 'Discord')
        ->where('page.channels.0.mode', 'digest')
        ->where('page.labelMax', NotificationChannel::LABEL_MAX)
        ->where('page.digestHour', DailyDigests::HOUR)
        ->where('page.channels.0.lastFailure', __('settings.delivery_failure_gone'))
        ->missing('page.channels.0.config')
        ->where('page.kinds.0.code', DiscordChannel::CODE)
        ->where('page.kinds.0.fields.0.key', DiscordChannel::WEBHOOK));
});

test('a channel is added encrypted, named after its kind unless named', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.channels.index'))
        ->post(route('settings.channels.store'), ['driver' => DiscordChannel::CODE, 'kind' => DiscordChannel::CODE, 'label' => '', 'mode' => 'instant', 'config' => [DiscordChannel::WEBHOOK => DISCORD_WEBHOOK]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.channels.index'));

    $channel = $user->notificationChannels()->sole();

    expect($channel->label)->toBe('Discord')
        ->and($channel->mode)->toBe(ChannelMode::Instant)
        ->and($channel->enabled)->toBeTrue()
        ->and($channel->config)->toBe([DiscordChannel::WEBHOOK => DISCORD_WEBHOOK])
        ->and((string) DB::table('notification_channels')->value('config'))->not->toContain('discord.com');
    // Saving never messages the channel.
    Http::assertNothingSent();
});

test('only a Discord webhook address is a Discord channel, and a kind the instance lacks is refused', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.channels.store'), ['driver' => DiscordChannel::CODE, 'kind' => DiscordChannel::CODE, 'mode' => 'digest', 'config' => [DiscordChannel::WEBHOOK => 'https://example.com/api/webhooks/1/a']])
        ->assertSessionHasErrors('config.'.DiscordChannel::WEBHOOK);

    $this->actingAs($user)
        ->post(route('settings.channels.store'), ['driver' => 'carrier-pigeon', 'mode' => 'digest', 'config' => [DiscordChannel::WEBHOOK => DISCORD_WEBHOOK]])
        ->assertSessionHasErrors('driver');

    expect($user->notificationChannels()->count())->toBe(0);
});

test('a refused save never keeps the typed address in the session', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('settings.channels.index'))
        ->post(route('settings.channels.store'), ['driver' => DiscordChannel::CODE, 'kind' => DiscordChannel::CODE, 'mode' => 'hourly', 'config' => [DiscordChannel::WEBHOOK => DISCORD_WEBHOOK]])
        ->assertSessionHasErrors('mode');

    expect(session()->getOldInput())->toHaveKey('mode')->not->toHaveKey('config');
});

test('a change without an address keeps the saved one, a new one replaces it and forgets its failure', function () {
    $user = User::factory()->create();
    $channel = discordChannel($user);
    $channel->update(['last_failure' => DeliveryAnswer::Gone]);

    $this->actingAs($user)
        ->patch(route('settings.channels.update', $channel), ['label' => 'Friends', 'mode' => 'instant'])
        ->assertSessionHasNoErrors();

    expect($channel->refresh()->label)->toBe('Friends')
        ->and($channel->mode)->toBe(ChannelMode::Instant)
        ->and($channel->config)->toBe([DiscordChannel::WEBHOOK => DISCORD_WEBHOOK])
        ->and($channel->last_failure)->toBe(DeliveryAnswer::Gone);

    // The webhook is a secret: the page never has it, so an empty field keeps it.
    $this->patch(route('settings.channels.update', $channel), ['config' => [DiscordChannel::WEBHOOK => '']])
        ->assertSessionHasNoErrors();
    expect($channel->refresh()->config)->toBe([DiscordChannel::WEBHOOK => DISCORD_WEBHOOK]);

    $this->patch(route('settings.channels.update', $channel), ['config' => [DiscordChannel::WEBHOOK => 'https://discord.com/api/webhooks/9/new']])
        ->assertSessionHasNoErrors();

    expect($channel->refresh()->config)->toBe([DiscordChannel::WEBHOOK => 'https://discord.com/api/webhooks/9/new'])
        ->and($channel->last_failure)->toBeNull();
});

test('the switch alone turns a channel off and on, the rest as saved', function () {
    $user = User::factory()->create();
    $channel = discordChannel($user, ChannelMode::Instant);
    $channel->update(['label' => 'Friends']);

    $this->actingAs($user)->patch(route('settings.channels.update', $channel), ['enabled' => false])->assertSessionHasNoErrors();

    expect($channel->refresh()->enabled)->toBeFalse()
        ->and($channel->label)->toBe('Friends')
        ->and($channel->mode)->toBe(ChannelMode::Instant);

    $this->patch(route('settings.channels.update', $channel), ['enabled' => true])->assertSessionHasNoErrors();

    expect($channel->refresh()->enabled)->toBeTrue();
});

test('the test button messages the channel and says when it did not arrive', function () {
    Http::fake([
        'discord.com/api/webhooks/1/*' => Http::response(null, 204),
        'discord.com/api/webhooks/2/*' => Http::response(null, 404),
    ]);
    $user = User::factory()->create();
    $working = discordChannel($user, webhook: 'https://discord.com/api/webhooks/1/a');
    $gone = discordChannel($user, webhook: 'https://discord.com/api/webhooks/2/b');

    $this->actingAs($user)
        ->from(route('settings.channels.index'))
        ->post(route('settings.channels.test', $working))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.channels.index'));

    $this->post(route('settings.channels.test', $gone))
        ->assertSessionHasErrors(['test' => __('settings.the_test_message_did_not_arrive')]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/1/')
        && $request['embeds'][0]['title'] === __('settings.test_notification_this_channel_works')
        && $request['embeds'][0]['url'] === route('settings.channels.index'));
    expect($gone->refresh()->last_failure)->toBe(DeliveryAnswer::Gone);
});

test('the test button takes a few clicks a minute', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create();
    $channel = discordChannel($user);

    foreach (range(1, config()->integer('tracker.rate_limits.channel_test')) as $click) {
        $this->actingAs($user)->post(route('settings.channels.test', $channel))->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->post(route('settings.channels.test', $channel))->assertStatus(429);
});

test('a channel is removed', function () {
    $user = User::factory()->create();
    $channel = discordChannel($user);

    $this->actingAs($user)->delete(route('settings.channels.destroy', $channel))->assertSessionHasNoErrors();

    expect(NotificationChannel::query()->count())->toBe(0);
});

test("somebody else's channel is not found", function () {
    Http::fake();
    $channel = discordChannel(User::factory()->create());
    $this->actingAs(User::factory()->create());

    $this->patch(route('settings.channels.update', $channel), ['enabled' => false])->assertNotFound();
    $this->post(route('settings.channels.test', $channel))->assertNotFound();
    $this->delete(route('settings.channels.destroy', $channel))->assertNotFound();

    expect($channel->refresh()->enabled)->toBeTrue();
    Http::assertNothingSent();
});

test('a channel of a kind this instance no longer offers is not found', function () {
    Http::fake();
    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->create(['driver' => 'retired']);
    $this->actingAs($user);

    $this->patch(route('settings.channels.update', $channel), ['enabled' => false])->assertNotFound();
    $this->post(route('settings.channels.test', $channel))->assertNotFound();

    Http::assertNothingSent();
});

test('a name cleared goes back to the name of its kind', function () {
    $user = User::factory()->create();
    $channel = discordChannel($user);
    $channel->update(['label' => 'Friends']);

    $this->actingAs($user)->patch(route('settings.channels.update', $channel), ['label' => ''])->assertSessionHasNoErrors();

    expect($channel->refresh()->label)->toBe('Discord');
});
