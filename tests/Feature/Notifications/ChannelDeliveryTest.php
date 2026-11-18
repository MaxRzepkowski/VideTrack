<?php

use App\Actions\Media\SendMediaNotification;
use App\Integrations\Discord\DiscordChannel;
use App\Jobs\DeliverChannelMessage;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMessages;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\DailyDigests;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

/*
 * Where a notification goes once the bell has it: each instant channel at
 * once, in the queue, and each digest channel in its owner's daily summary.
 * A channel that fails remembers how, and stops nothing else.
 */

test('an instant channel gets an embed alongside the database notification', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create();
    discordChannel($user, ChannelMode::Instant);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    expect($user->notifications()->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === DISCORD_WEBHOOK
        && $request['embeds'][0]['title'] === 'Out today: Dune'
        && str_contains($request['embeds'][0]['url'], 'tmdb:1')
        // The movie accent color from the design system.
        && $request['embeds'][0]['color'] === 0x2E90FA);
});

test('a digest channel, a disabled one and a user without channels get nothing at once', function () {
    Http::fake();
    $digest = User::factory()->create();
    discordChannel($digest);
    $disabled = User::factory()->create();
    NotificationChannel::factory()->for($disabled)->instant()->disabled()->create();
    $none = User::factory()->create();

    foreach ([$digest, $disabled, $none] as $user) {
        app(SendMediaNotification::class)->execute($user, premiereEvent());
    }

    expect($digest->notifications()->count() + $disabled->notifications()->count() + $none->notifications()->count())->toBe(3);
    Http::assertNothingSent();
});

test('every instant channel gets the message, and one that failed tells how without stopping the other', function () {
    Http::fake([
        'discord.com/api/webhooks/1/*' => Http::response(null, 404),
        'discord.com/api/webhooks/2/*' => Http::response(null, 204),
    ]);
    $user = User::factory()->create();
    $gone = discordChannel($user, ChannelMode::Instant, 'https://discord.com/api/webhooks/1/a');
    $working = discordChannel($user, ChannelMode::Instant, 'https://discord.com/api/webhooks/2/b');
    $working->update(['last_failure' => DeliveryAnswer::Unavailable]);

    expect(app(SendMediaNotification::class)->execute($user, premiereEvent()))->toBeTrue();

    Http::assertSentCount(2);
    expect($gone->refresh()->last_failure)->toBe(DeliveryAnswer::Gone)
        // A message that arrived forgets the earlier failure.
        ->and($working->refresh()->last_failure)->toBeNull()
        ->and($user->notifications()->count())->toBe(1);
});

test('an instant message waits in the queue for its own delivery and carries no address', function () {
    Queue::fake([DeliverChannelMessage::class]);
    $user = User::factory()->create();
    discordChannel($user, ChannelMode::Instant);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    Queue::assertPushed(DeliverChannelMessage::class, 1);
    expect(serialize(Queue::pushed(DeliverChannelMessage::class)->first()))->not->toContain('discord.com');
});

test('a message the service could not take is tried again later, one to an address gone is not', function () {
    Sleep::fake();
    Http::fake([
        'discord.com/api/webhooks/1/*' => Http::response(null, 503),
        'discord.com/api/webhooks/2/*' => Http::response(null, 404),
    ]);
    $user = User::factory()->create();
    $unavailable = discordChannel($user, ChannelMode::Instant, 'https://discord.com/api/webhooks/1/a');
    $gone = discordChannel($user, ChannelMode::Instant, 'https://discord.com/api/webhooks/2/b');
    $message = app(ChannelMessages::class)->message(premiereEvent(), $user);

    $retried = (new DeliverChannelMessage($unavailable, $message))->withFakeQueueInteractions();
    $retried->handle(app(ChannelRegistry::class));
    $retried->assertReleased(DeliverChannelMessage::RETRY_AFTER[0]);

    $dropped = (new DeliverChannelMessage($gone, $message))->withFakeQueueInteractions();
    $dropped->handle(app(ChannelRegistry::class));
    $dropped->assertNotReleased();

    expect($unavailable->refresh()->last_failure)->toBeNull()
        ->and($gone->refresh()->last_failure)->toBe(DeliveryAnswer::Gone);
});

test('a throttled message comes back a moment later instead of being lost', function () {
    Sleep::fake();
    Http::fake(['discord.com/*' => Http::response(['retry_after' => 1.5], 429)]);
    $channel = discordChannel(User::factory()->create(), ChannelMode::Instant);

    $throttled = (new DeliverChannelMessage($channel, app(ChannelMessages::class)->message(premiereEvent(), $channel->user)))->withFakeQueueInteractions();
    $throttled->handle(app(ChannelRegistry::class));

    $throttled->assertReleased();
    // An answered 429 is not tried again on the spot.
    Http::assertSentCount(1);
});

test('a channel switched off while its message waited is not messaged', function () {
    Http::fake();
    $channel = discordChannel(User::factory()->create(), ChannelMode::Instant);
    $message = app(ChannelMessages::class)->message(premiereEvent(), $channel->user);
    $channel->update(['enabled' => false]);

    (new DeliverChannelMessage($channel, $message))->handle(app(ChannelRegistry::class));

    Http::assertNothingSent();
});

test('an instant message links to the instance, not to the host of the request that caused it', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    config(['app.url' => 'https://tracker.example.com']);
    // A refresh runs inside someone's request; its host is theirs to pick.
    URL::forceRootUrl('https://evil.example');
    $user = User::factory()->create();
    discordChannel($user, ChannelMode::Instant);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    Http::assertSent(fn (Request $request): bool => str_starts_with($request['embeds'][0]['url'], 'https://tracker.example.com/'));
});

test('the digest fires once the local clock passes the digest hour, honoring the timezone', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    // 00:00 UTC = 09:00 in Tokyo (past the digest slot), 02:00 in Warsaw (before it).
    $this->travelTo('2026-07-22 23:00:00');
    $tokyo = User::factory()->create(['preferences' => ['timezone' => 'Asia/Tokyo']]);
    $warsaw = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    $tokyoChannel = discordChannel($tokyo);
    $warsawChannel = discordChannel($warsaw);

    foreach ([$tokyo, $warsaw] as $user) {
        app(SendMediaNotification::class)->execute($user, premiereEvent());
        app(SendMediaNotification::class)->execute($user, premiereEvent('Arrival', 'tmdb:2'));
    }

    $this->travelTo('2026-07-23 00:00:00');
    runChannelDigests();

    // One plain-text message for Tokyo (both movies under one section, each
    // line labeled with its event), nothing for Warsaw yet.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ! isset($request['embeds'])
        && str_contains($request['content'], 'Movies')
        && str_contains($request['content'], '[Dune]')
        && str_contains($request['content'], '[Arrival]')
        && substr_count($request['content'], 'Out today') === 2);

    expect($tokyoChannel->refresh()->digest_sent_at)->not->toBeNull()
        ->and($warsawChannel->refresh()->digest_sent_at)->toBeNull();

    // A catch-up re-run the same day never doubles the digest.
    runChannelDigests();

    Http::assertSentCount(1);
});

test('each digest channel keeps its own slot', function () {
    Http::fake([
        'discord.com/api/webhooks/1/*' => Http::response(null, 204),
        'discord.com/api/webhooks/2/*' => Http::response(null, 204),
    ]);
    $this->travelTo('2026-07-23 07:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    $first = discordChannel($user, webhook: 'https://discord.com/api/webhooks/1/a');
    app(SendMediaNotification::class)->execute($user, premiereEvent());

    $this->travelTo('2026-07-23 09:00:00');
    runChannelDigests();
    // A second channel added after today's digest went out gets its own.
    $second = discordChannel($user, webhook: 'https://discord.com/api/webhooks/2/b');
    runChannelDigests();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/1/') && str_contains($request['content'], '[Dune]'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2/') && str_contains($request['content'], '[Dune]'));
    expect($first->refresh()->digest_sent_at)->not->toBeNull()
        ->and($second->refresh()->digest_sent_at)->not->toBeNull();
});

test('a digest the service could not take is sent on the next run, not lost', function () {
    $down = true;
    Http::fake(['discord.com/*' => function () use (&$down) {
        return $down ? Http::response(null, 503) : Http::response(null, 204);
    }]);
    Sleep::fake();

    $this->travelTo('2026-07-22 23:00:00');
    $tokyo = User::factory()->create(['preferences' => ['timezone' => 'Asia/Tokyo']]);
    $channel = discordChannel($tokyo);
    app(SendMediaNotification::class)->execute($tokyo, premiereEvent());

    $this->travelTo('2026-07-23 00:00:00');
    runChannelDigests();

    expect($channel->refresh()->digest_sent_at)->toBeNull()
        ->and($channel->last_failure)->toBe(DeliveryAnswer::Unavailable);

    $down = false;
    $this->travelTo('2026-07-23 01:00:00');
    runChannelDigests();

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['content'] ?? ''), '[Dune]'));
    expect($channel->refresh()->digest_sent_at)->not->toBeNull()
        ->and($channel->last_failure)->toBeNull();
});

test('a digest the service refused is not sent again the same day', function () {
    Http::fake(['discord.com/*' => Http::response(null, 404)]);
    $this->travelTo('2026-07-23 07:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    $channel = discordChannel($user);
    app(SendMediaNotification::class)->execute($user, premiereEvent());

    $this->travelTo('2026-07-23 09:00:00');
    runChannelDigests();
    runChannelDigests();

    Http::assertSentCount(1);
    expect($channel->refresh()->last_failure)->toBe(DeliveryAnswer::Gone);
});

test('the timezone the browser reported stands in for an unset preference', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    // 00:00 UTC = 09:00 in Tokyo. Only the reported zone puts the clock past
    // the digest slot; the instance timezone (UTC) would still read 00:00.
    $this->travelTo('2026-07-23 00:00:00');
    $user = User::factory()->create(['preferences' => ['detected_timezone' => 'Asia/Tokyo']]);
    discordChannel($user);
    app(SendMediaNotification::class)->execute($user, premiereEvent());

    runChannelDigests();

    Http::assertSentCount(1);

    // An explicit preference still wins over the reported zone.
    $ignored = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw', 'detected_timezone' => 'Asia/Tokyo']]);
    $channel = discordChannel($ignored);
    app(SendMediaNotification::class)->execute($ignored, premiereEvent());

    runChannelDigests();

    // 00:00 UTC is 02:00 in Warsaw — before the slot, so nothing new went out.
    Http::assertSentCount(1);
    expect($channel->refresh()->digest_sent_at)->toBeNull();
});

test('an empty window claims the day without sending a message', function () {
    Http::fake();
    $channel = discordChannel(User::factory()->create(['preferences' => ['timezone' => 'UTC']]));
    $this->travelTo(today()->setHour(DailyDigests::HOUR + 1));

    runChannelDigests();

    Http::assertNothingSent();
    expect($channel->refresh()->digest_sent_at)->not->toBeNull();
});

test('instant and disabled channels are left out of the digest', function () {
    Http::fake();
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    $instant = discordChannel($user, ChannelMode::Instant);
    $disabled = NotificationChannel::factory()->for($user)->disabled()->create();
    $this->travelTo(today()->setHour(DailyDigests::HOUR + 1));

    runChannelDigests();

    Http::assertNothingSent();
    expect($instant->refresh()->digest_sent_at)->toBeNull()
        ->and($disabled->refresh()->digest_sent_at)->toBeNull();
});

test('a channel of a kind the instance no longer offers is passed over', function () {
    Http::fake();
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    $channel = NotificationChannel::factory()->for($user)->create(['driver' => 'retired']);
    $instant = NotificationChannel::factory()->for($user)->instant()->create(['driver' => 'retired']);
    $this->travelTo(today()->setHour(DailyDigests::HOUR + 1));

    app(SendMediaNotification::class)->execute($user, premiereEvent());
    runChannelDigests();

    Http::assertNothingSent();
    expect($channel->refresh()->digest_sent_at)->toBeNull()
        ->and($instant->refresh()->last_failure)->toBeNull()
        ->and(app(ChannelRegistry::class)->codes())->toContain(DiscordChannel::CODE);
});

test('the digest tells the day in the order it happened', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $this->travelTo('2026-07-22 20:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    discordChannel($user);

    app(SendMediaNotification::class)->execute($user, premiereEvent('Aaa First', 'tmdb:1'));
    $this->travelTo('2026-07-22 21:00:00');
    app(SendMediaNotification::class)->execute($user, premiereEvent('Zzz Second', 'tmdb:2'));

    $this->travelTo('2026-07-23 09:00:00');
    runChannelDigests();

    Http::assertSent(fn (Request $request): bool => strpos((string) $request['content'], 'Aaa First') < strpos((string) $request['content'], 'Zzz Second'));
});

test('a channel switched back on after a long pause starts again without the pause', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $this->travelTo('2026-07-01 09:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    $channel = discordChannel($user);
    $channel->update(['enabled' => false, 'digest_sent_at' => now()]);
    app(SendMediaNotification::class)->execute($user, premiereEvent('Long Ago', 'tmdb:1'));

    $this->travelTo('2026-07-22 20:00:00');
    app(SendMediaNotification::class)->execute($user, premiereEvent('Yesterday', 'tmdb:2'));
    $this->actingAs($user)->patch(route('settings.channels.update', $channel), ['enabled' => true]);

    $this->travelTo('2026-07-23 09:00:00');
    runChannelDigests();

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['content'], 'Yesterday')
        && ! str_contains((string) $request['content'], 'Long Ago'));
    expect(now()->subDays(DailyDigests::MAX_WINDOW_DAYS)->isAfter('2026-07-01 09:00:00'))->toBeTrue();
});

test('two digest channels of one user read the notifications once', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $this->travelTo('2026-07-23 07:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    discordChannel($user, webhook: 'https://discord.com/api/webhooks/1/a');
    discordChannel($user, webhook: 'https://discord.com/api/webhooks/2/b');
    app(SendMediaNotification::class)->execute($user, premiereEvent());
    $this->travelTo('2026-07-23 09:00:00');

    DB::enableQueryLog();
    runChannelDigests();
    $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], '"notifications"'));

    expect($reads)->toHaveCount(1);
    Http::assertSentCount(2);
});
