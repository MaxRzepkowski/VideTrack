<?php

use App\Accounts\Preferences\DiscordMode;
use App\Actions\Media\SendMediaNotification;
use App\Integrations\Discord\DiscordClient;
use App\Jobs\DeliverDiscordMessage;
use App\Jobs\SendDiscordDigests;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\DiscordDigestComposer;
use App\Notifications\MediaEventPayload;
use App\Notifications\NotificationCopy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

const WEBHOOK = 'https://discord.com/api/webhooks/123/abc';

function runDiscordDigests(): void
{
    (new SendDiscordDigests(TaskRun::queueTask('discord-digest')))
        ->handle(app(DiscordDigestComposer::class), app(DiscordClient::class));
}

test('instant mode posts an embed to the webhook alongside the database notification', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    $user = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['discord_mode' => 'instant'],
    ]);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    expect($user->notifications()->count())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === WEBHOOK
            && $request['embeds'][0]['title'] === 'Out today: Dune'
            && str_contains($request['embeds'][0]['url'], 'tmdb:1')
            // The movie accent color from the design system.
            && $request['embeds'][0]['color'] === 0x2E90FA;
    });
});

test('digest mode does not ping instantly', function () {
    Http::fake();

    $user = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['discord_mode' => 'digest'],
    ]);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    expect($user->notifications()->count())->toBe(1);

    Http::assertNothingSent();
});

test('a dead webhook never blocks the database notification', function () {
    Http::fake(['discord.com/*' => Http::response(null, 500)]);

    $user = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['discord_mode' => 'instant'],
    ]);

    expect(app(SendMediaNotification::class)->execute($user, premiereEvent()))->toBeTrue()
        ->and($user->notifications()->count())->toBe(1);
});

test('the digest fires once the local clock passes the digest hour, honoring the timezone', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    // 00:00 UTC = 09:00 in Tokyo (past the 8:00 slot), 02:00 in Warsaw (before it).
    $this->travelTo('2026-07-22 23:00:00');

    $tokyo = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['timezone' => 'Asia/Tokyo'],
    ]);
    $warsaw = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['timezone' => 'Europe/Warsaw'],
    ]);

    foreach ([$tokyo, $warsaw] as $user) {
        app(SendMediaNotification::class)->execute($user, premiereEvent());
        app(SendMediaNotification::class)->execute($user, premiereEvent('Arrival', 'tmdb:2'));
    }

    $this->travelTo('2026-07-23 00:00:00');

    runDiscordDigests();

    // One plain-text message for Tokyo (both movies under one section, each
    // line labeled with its event), nothing for Warsaw yet.
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $content = $request['content'];

        return ! isset($request['embeds'])
            && str_contains($content, 'Movies')
            && str_contains($content, '[Dune]')
            && str_contains($content, '[Arrival]')
            && substr_count($content, 'Out today') === 2;
    });

    expect($tokyo->refresh()->discord_digest_at)->not->toBeNull()
        ->and($warsaw->refresh()->discord_digest_at)->toBeNull();

    // A catch-up re-run the same day never doubles the digest.
    runDiscordDigests();

    Http::assertSentCount(1);
});

test('a digest Discord could not take is sent on the next run, not lost', function () {
    $down = true;
    Http::fake(['discord.com/*' => function () use (&$down) {
        return $down ? Http::response(null, 503) : Http::response(null, 204);
    }]);
    Sleep::fake();

    $this->travelTo('2026-07-22 23:00:00');
    $tokyo = User::factory()->create(['discord_webhook_url' => WEBHOOK, 'preferences' => ['timezone' => 'Asia/Tokyo']]);
    app(SendMediaNotification::class)->execute($tokyo, premiereEvent());

    $this->travelTo('2026-07-23 00:00:00');
    runDiscordDigests();

    expect($tokyo->refresh()->discord_digest_at)->toBeNull();

    $down = false;
    $this->travelTo('2026-07-23 01:00:00');
    runDiscordDigests();

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['content'] ?? ''), '[Dune]'));
    expect($tokyo->refresh()->discord_digest_at)->not->toBeNull();
});

test('the timezone the browser reported stands in for an unset preference', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    // 00:00 UTC = 09:00 in Tokyo. Only the reported zone puts the clock past
    // the 8:00 slot; the instance timezone (UTC) would still read 00:00.
    $this->travelTo('2026-07-23 00:00:00');

    $user = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['detected_timezone' => 'Asia/Tokyo'],
    ]);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    runDiscordDigests();

    Http::assertSentCount(1);

    // An explicit preference still wins over the reported zone.
    $ignored = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => [
            'timezone' => 'Europe/Warsaw',
            'detected_timezone' => 'Asia/Tokyo',
        ],
    ]);

    app(SendMediaNotification::class)->execute($ignored, premiereEvent());

    runDiscordDigests();

    // 00:00 UTC is 02:00 in Warsaw — before the slot, so nothing new went out.
    Http::assertSentCount(1);

    expect($ignored->refresh()->discord_digest_at)->toBeNull();
});

test('an empty window claims the day without sending a message', function () {
    Http::fake();

    User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['timezone' => 'UTC'],
    ]);

    $this->travelTo(today()->setHour(9));

    runDiscordDigests();

    Http::assertNothingSent();

    expect(User::query()->firstOrFail()->discord_digest_at)->not->toBeNull();
});

test('instant-mode users are left out of the digest', function () {
    Http::fake();

    $user = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['discord_mode' => 'instant', 'timezone' => 'UTC'],
    ]);

    $this->travelTo(today()->setHour(9));

    runDiscordDigests();

    Http::assertNothingSent();

    expect($user->refresh()->discord_digest_at)->toBeNull();
});

test('the test button posts to the webhook and leaves its name and avatar alone', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    $this->actingAs(User::factory()->create())
        ->from('/settings/preferences')
        ->post('/settings/discord/test', ['webhook_url' => WEBHOOK])
        ->assertRedirect('/settings/preferences')
        ->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WEBHOOK
        && $request['content'] === __('settings.test_notification_your_webhook_works'));
});

test('the test button surfaces a rejected webhook as a validation error', function () {
    Http::fake(['discord.com/*' => Http::response(null, 404)]);

    $this->actingAs(User::factory()->create())
        ->from('/settings/preferences')
        ->post('/settings/discord/test', ['webhook_url' => WEBHOOK])
        ->assertSessionHasErrors('webhook_url');
});

test('only Discord webhook URLs pass validation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->post('/settings/discord/test', ['webhook_url' => 'https://example.com/api/webhooks/1/a'])
        ->assertSessionHasErrors('webhook_url');

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['discord_webhook_url' => 'https://example.com/api/webhooks/1/a'])
        ->assertSessionHasErrors('discord_webhook_url');
});

test('the webhook URL and mode persist through preferences, empty URL turns Discord off', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['discord_webhook_url' => WEBHOOK, 'discord_mode' => 'instant']);

    $user->refresh();

    expect($user->discordWebhookUrl())->toBe(WEBHOOK)
        ->and($user->preferences->discordMode())->toBe(DiscordMode::Instant);

    // Saving, re-saving and clearing the URL never touch Discord.
    $this->actingAs($user)
        ->patch('/settings/preferences', ['discord_webhook_url' => WEBHOOK]);

    $this->actingAs($user)
        ->patch('/settings/preferences', ['discord_webhook_url' => '']);

    Http::assertNothingSent();

    expect($user->refresh()->discordWebhookUrl())->toBeNull()
        // The mode survives independently of the URL.
        ->and($user->preferences->discordMode())->toBe(DiscordMode::Instant);
});

test('the webhook URL is stored encrypted and never shared with every page', function () {
    Http::fake([...providerDictionaryFakes(), 'discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['discord_webhook_url' => WEBHOOK])
        ->assertSessionHasNoErrors();

    expect((string) DB::table('users')->where('id', $user->id)->value('discord_webhook_url'))->not->toContain('discord.com')
        ->and($user->refresh()->preferences->toStored())->not->toHaveKey('discord_webhook_url');

    // Never back to any page: Preferences shows only its last characters.
    $this->get('/settings/preferences')->assertInertia(fn (Assert $page) => $page
        ->missing('auth.user.discord_webhook_url')
        ->missing('auth.user.preferences.discord_webhook_url')
        ->missing('auth.user.password')
        ->missing('discordWebhookUrl')
        ->where('discordWebhookEnding', substr(WEBHOOK, -4)));
});

test('a save without a webhook keeps the saved one', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create();
    $user->forceFill(['discord_webhook_url' => WEBHOOK])->save();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['discord_mode' => 'instant'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->discordWebhookUrl())->toBe(WEBHOOK);
    Http::assertNothingSent();
});

test('the test button without a typed address messages the saved webhook', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create();
    $user->forceFill(['discord_webhook_url' => WEBHOOK])->save();

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->post('/settings/discord/test', [])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === WEBHOOK);
});

test('the test button with nothing typed and nothing saved asks for an address', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->from('/settings/preferences')
        ->post('/settings/discord/test', [])
        ->assertSessionHasErrors(['webhook_url' => 'Paste a webhook address first.']);

    Http::assertNothingSent();
});

test('a status change reads in the language of the message, not the provider', function () {
    $event = MediaEventPayload::fromStored(['event' => 'show_status', 'status' => 'ended', 'oldStatus' => 'returning_series', 'media' => ['type' => 'show', 'ref' => '1', 'title' => 'Fargo']]);

    expect(app(NotificationCopy::class)->plainDetail($event, 'en'))->toBe('Returning series → Ended')
        ->and(app(NotificationCopy::class)->plainDetail($event, 'pl'))->toBe('Powracający serial → Zakończony');
});

test('parts a collection gained at once are counted, previewed and lead to the collection', function () {
    $parts = array_map(fn (int $n): array => ['type' => 'movie', 'ref' => (string) $n, 'title' => "Part {$n}"], range(1, 6));
    $event = MediaEventPayload::fromStored(['event' => 'collection_new_item', 'collectionTitle' => 'Dune', 'collectionProvider' => 'tmdb', 'collectionRef' => '9', 'media' => $parts[0], 'items' => $parts]);
    $copy = app(NotificationCopy::class);

    expect($copy->title($event, 'en'))->toBe('6 new parts of Dune')
        ->and($copy->title($event, 'pl'))->toBe('6 nowych części serii Dune')
        ->and($copy->plainDetail($event, 'en'))->toBe('Part 1 – Part 2 – Part 3 – 3 more')
        ->and($copy->url($event))->toBe(route('collections.show', ['provider' => 'tmdb', 'ref' => '9']));
});

test('parts without a collection address lead to the first of them', function () {
    $parts = array_map(fn (int $n): array => ['type' => 'movie', 'ref' => (string) $n, 'title' => "Part {$n}"], range(1, 2));
    $event = MediaEventPayload::fromStored(['event' => 'collection_new_item', 'collectionTitle' => 'Dune', 'collectionId' => 9, 'media' => $parts[0], 'items' => $parts]);

    expect(app(NotificationCopy::class)->url($event))->toBe(route('movie.details', ['ref' => '1']));
});

test('an instant ping waits in the queue for its own delivery and carries no webhook address', function () {
    Queue::fake([DeliverDiscordMessage::class]);
    $user = User::factory()->create(['discord_webhook_url' => WEBHOOK, 'preferences' => ['discord_mode' => 'instant']]);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    Queue::assertPushed(DeliverDiscordMessage::class, 1);
    expect(serialize(Queue::pushed(DeliverDiscordMessage::class)->first()))->not->toContain('discord.com');
});

test('a ping Discord could not take is tried again later, one to a webhook gone is not', function () {
    Sleep::fake();
    Http::fake([
        'discord.com/api/webhooks/1/*' => Http::response(null, 503),
        'discord.com/api/webhooks/2/*' => Http::response(null, 404),
    ]);
    Log::spy();
    $unavailable = User::factory()->create(['discord_webhook_url' => 'https://discord.com/api/webhooks/1/a']);
    $gone = User::factory()->create(['discord_webhook_url' => 'https://discord.com/api/webhooks/2/b']);

    $retried = (new DeliverDiscordMessage($unavailable, ['content' => 'Hi']))->withFakeQueueInteractions();
    $retried->handle(app(DiscordClient::class));
    $retried->assertReleased(60);

    $dropped = (new DeliverDiscordMessage($gone, ['content' => 'Hi']))->withFakeQueueInteractions();
    $dropped->handle(app(DiscordClient::class));
    $dropped->assertNotReleased();
    Log::shouldHaveReceived('warning')->once()->with('Discord did not take an instant notification.', ['user' => $gone->id, 'answer' => 'Gone']);
});

test('a ping Discord throttled comes back a moment later instead of being lost', function () {
    Sleep::fake();
    Http::fake(['discord.com/*' => Http::response(['retry_after' => 1.5], 429)]);
    Log::spy();
    $user = User::factory()->create(['discord_webhook_url' => WEBHOOK]);

    $throttled = (new DeliverDiscordMessage($user, ['content' => 'Hi']))->withFakeQueueInteractions();
    $throttled->handle(app(DiscordClient::class));

    $throttled->assertReleased();
    Log::shouldNotHaveReceived('warning');
    // An answered 429 is not tried again on the spot.
    Http::assertSentCount(1);
});

test('the digest tells the day in the order it happened', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $this->travelTo('2026-07-22 20:00:00');
    $user = User::factory()->create(['discord_webhook_url' => WEBHOOK, 'preferences' => ['timezone' => 'UTC']]);

    app(SendMediaNotification::class)->execute($user, premiereEvent('Aaa First', 'tmdb:1'));
    $this->travelTo('2026-07-22 21:00:00');
    app(SendMediaNotification::class)->execute($user, premiereEvent('Zzz Second', 'tmdb:2'));

    $this->travelTo('2026-07-23 09:00:00');
    runDiscordDigests();

    Http::assertSent(function (Request $request): bool {
        $content = (string) $request['content'];

        return strpos($content, 'Aaa First') < strpos($content, 'Zzz Second');
    });
});

test('testing the saved webhook does not brand it again, and the button takes five clicks a minute', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    $user = User::factory()->create(['discord_webhook_url' => WEBHOOK]);

    foreach (range(1, 5) as $click) {
        $this->actingAs($user)->from('/settings/preferences')->post('/settings/discord/test', [])->assertSessionHasNoErrors();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    $this->actingAs($user)->from('/settings/preferences')->post('/settings/discord/test', [])->assertStatus(429);
});

test('an instant message links to the instance, not to the host of the request that caused it', function () {
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
    config(['app.url' => 'https://tracker.example.com']);
    // A refresh runs inside someone's request; its host is theirs to pick.
    URL::forceRootUrl('https://evil.example');

    $user = User::factory()->create([
        'discord_webhook_url' => WEBHOOK,
        'preferences' => ['discord_mode' => DiscordMode::Instant->value],
    ]);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    Http::assertSent(fn (Request $request): bool => str_starts_with($request['embeds'][0]['url'], 'https://tracker.example.com/'));
});
