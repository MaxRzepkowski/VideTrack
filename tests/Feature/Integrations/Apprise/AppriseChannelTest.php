<?php

use App\Actions\Media\SendMediaNotification;
use App\Integrations\Apprise\AppriseChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeHostResolver;

/*
 * Apprise channels through the instance's apprise-api container: offered only
 * when the instance names one, every service it describes a kind with its own
 * fields (https://github.com/caronc/apprise-api, `/details`), the Apprise URL
 * put together from them, and a message posted as the stateless notify call.
 */

// Apprise reads the colon of a bot token encoded, as it writes it itself.
const APPRISE_TELEGRAM = 'tgram://123456789%3AABCdef-ghi_jkl/-100123';

/** The instance names an apprise-api container, faked: its description of the services and its answer to a message. */
function enableApprise(int $notifyStatus = 200): void
{
    config(['tracker.apprise.url' => 'http://apprise:8000/']);
    Http::fake([
        'apprise:8000/details/*' => Http::response(jsonFixture('apprise/details')),
        'apprise:8000/notify' => Http::response(['success' => $notifyStatus === 200], $notifyStatus),
    ]);
}

function appriseChannel(User $user, ChannelMode $mode = ChannelMode::Digest): NotificationChannel
{
    return NotificationChannel::factory()->for($user)->create([
        'driver' => AppriseChannel::CODE,
        'kind' => 'tgram',
        'label' => 'Telegram',
        'mode' => $mode,
        'config' => ['bot_token' => '123456789:ABCdef-ghi_jkl', 'targets' => '-100123', AppriseChannel::URLS => APPRISE_TELEGRAM],
    ]);
}

/**
 * @param  array<string, string>  $config
 */
function addAppriseChannel(string $kind, array $config): TestResponse
{
    return test()->post(route('settings.channels.store'), ['driver' => AppriseChannel::CODE, 'kind' => $kind, 'mode' => 'instant', 'config' => $config]);
}

test('without an apprise-api address no Apprise service is offered', function () {
    $this->actingAs(User::factory()->create());

    expect(app(ChannelRegistry::class)->has(AppriseChannel::CODE))->toBeFalse();
    addAppriseChannel('tgram', ['bot_token' => '123456789:ABCdef'])->assertSessionHasErrors('driver');
});

test('every service the container describes is a kind with its own fields, Discord and the desktop ones left out', function () {
    enableApprise();

    $this->actingAs(User::factory()->create())->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page
        ->where('page.kinds', fn ($kinds): bool => collect($kinds)->where('driver', AppriseChannel::CODE)->pluck('label')->all() === ['E-Mail', 'JSON', 'ntfy', 'Pushover', 'Telegram'])
        ->where('page.kinds', function ($kinds): bool {
            $telegram = collect($kinds)->firstWhere('code', 'tgram');

            return $telegram['popular'] === true
                && $telegram['setupUrl'] === 'https://appriseit.com/services/telegram/'
                && $telegram['fields'][0] === ['key' => 'bot_token', 'label' => 'Bot Token', 'type' => 'password', 'help' => null, 'optional' => false, 'secret' => true]
                && $telegram['fields'][1]['label'] === 'Target Chat ID'
                && $telegram['fields'][1]['optional'] === true;
        }));
});

test('the fields make the Apprise URL, each value in its place', function () {
    enableApprise();
    $user = User::factory()->create();
    $this->actingAs($user);

    addAppriseChannel('tgram', ['bot_token' => '123456789:ABCdef-ghi_jkl', 'targets' => '-100123'])->assertSessionHasNoErrors();
    addAppriseChannel('ntfys', ['topic' => 'videtrack-48213'])->assertSessionHasNoErrors();

    expect($user->notificationChannels()->orderBy('id')->get()->map(fn (NotificationChannel $channel): array => [$channel->kind, $channel->label, $channel->config])->all())->toBe([
        ['tgram', 'Telegram', ['bot_token' => '123456789:ABCdef-ghi_jkl', 'targets' => '-100123', AppriseChannel::URLS => APPRISE_TELEGRAM]],
        ['ntfys', 'ntfy', ['topic' => 'videtrack-48213', AppriseChannel::URLS => 'ntfys://videtrack-48213']],
    ]);
});

test('a value the service does not take, or too few to send with, is refused under its field', function () {
    enableApprise();
    $this->actingAs(User::factory()->create());

    addAppriseChannel('tgram', ['bot_token' => 'not a token'])->assertSessionHasErrors(['config.bot_token' => __('settings.the_service_does_not_take_this_value')]);
    addAppriseChannel('ntfys', [])->assertSessionHasErrors(['config' => __('settings.fill_in_what_the_service_needs')]);
    addAppriseChannel('pigeon', [])->assertSessionHasErrors(['kind' => __('settings.this_service_is_not_offered')]);

    expect(NotificationChannel::query()->count())->toBe(0);
});

test('a server of your own is reached unencrypted only when asked, and the server itself only by an administrator', function () {
    enableApprise();
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($user);
    addAppriseChannel('jsons', ['host' => '127.0.0.1'])->assertSessionHasErrors(['config.host' => __('common.this_address_leads_to_the_server')]);
    addAppriseChannel('jsons', ['host' => 'apprise'])->assertSessionHasErrors(['config.host' => __('common.this_address_leads_to_the_server')]);
    addAppriseChannel('jsons', ['host' => 'https://example.com/path'])->assertSessionHasErrors(['config.host' => __('settings.this_is_not_a_server_name')]);
    addAppriseChannel('jsons', ['host' => '192.168.1.10', 'port' => '8080', AppriseChannel::PLAIN_CONNECTION => '1'])->assertSessionHasNoErrors();

    $this->actingAs($admin);
    addAppriseChannel('jsons', ['host' => '127.0.0.1', 'port' => '8080', AppriseChannel::PLAIN_CONNECTION => '1'])->assertSessionHasNoErrors();

    expect($user->notificationChannels()->sole()->config[AppriseChannel::URLS])->toBe('json://192.168.1.10:8080')
        ->and($admin->notificationChannels()->sole()->config[AppriseChannel::URLS])->toBe('json://127.0.0.1:8080');
});

test('a notification is one stateless notify call: the saved URL, the headline, the facts and the link', function () {
    enableApprise();
    $user = User::factory()->create();
    appriseChannel($user, ChannelMode::Instant);

    app(SendMediaNotification::class)->execute($user, premiereEvent());

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://apprise:8000/notify'
        && $request->isJson()
        && $request->hasHeader('User-Agent', config()->string('tracker.http.user_agent'))
        && $request->data() === [
            'urls' => APPRISE_TELEGRAM,
            'title' => 'Out today: Dune',
            'body' => route('movie.details', ['ref' => 'tmdb:1']),
            'type' => 'info',
            'format' => 'text',
        ]);
});

test('the digest is one plain text message, a link under each line', function () {
    enableApprise();
    $this->travelTo('2026-07-23 07:00:00');
    $user = User::factory()->create(['preferences' => ['timezone' => 'UTC']]);
    appriseChannel($user);
    app(SendMediaNotification::class)->execute($user, premiereEvent());

    $this->travelTo('2026-07-23 09:00:00');
    runChannelDigests();

    Http::assertSent(fn (Request $request): bool => $request['title'] === 'Your daily digest'
        && $request['body'] === implode("\n", [
            '🎬 Movies',
            '• Dune: Out today',
            '  '.route('movie.details', ['ref' => 'tmdb:1']),
            '',
            'Enjoy your media!',
        ]));
});

test('a channel stays listed while the container cannot describe its services', function () {
    config(['tracker.apprise.url' => 'http://apprise:8000/']);
    Http::fake(['apprise:8000/*' => Http::response(null, 503)]);
    Sleep::fake();
    $user = User::factory()->create();
    appriseChannel($user);

    $this->actingAs($user)->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page
        ->where('page.channels.0.label', 'Telegram')
        ->where('page.channels.0.kindLabel', null));
});

test("apprise-api's answers read as delivered, gone, throttled, refused or unavailable", function (int $status, DeliveryAnswer $answer) {
    enableApprise($status);
    Sleep::fake();
    $channel = appriseChannel(User::factory()->create());

    $this->actingAs($channel->user)->post(route('settings.channels.test', $channel));

    expect($channel->refresh()->last_failure)->toBe($answer->failure());
})->with([
    'sent' => [200, DeliveryAnswer::Delivered],
    'no usable URL' => [400, DeliveryAnswer::Gone],
    'a service did not take it' => [424, DeliveryAnswer::Refused],
    'access denied' => [403, DeliveryAnswer::Refused],
    'rate limited' => [429, DeliveryAnswer::Throttled],
    'worker down' => [503, DeliveryAnswer::Unavailable],
]);

test('an answered refusal is asked once, an outage a couple of times', function () {
    config(['tracker.apprise.url' => 'http://apprise:8000/']);
    Sleep::fake();
    $status = 424;
    Http::fake([
        'apprise:8000/details/*' => Http::response(jsonFixture('apprise/details')),
        'apprise:8000/notify' => function () use (&$status) {
            return Http::response(null, $status);
        },
    ]);
    $channel = appriseChannel(User::factory()->create());

    $this->actingAs($channel->user)->post(route('settings.channels.test', $channel));
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/notify')))->toHaveCount(1);

    $status = 503;
    $this->post(route('settings.channels.test', $channel));
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/notify')))->toHaveCount(1 + DeliveryHttpClient::TRIES);
});

test('changing a channel shows its plain fields again and keeps a secret left empty', function () {
    enableApprise();
    $user = User::factory()->create();
    $channel = appriseChannel($user);

    $this->actingAs($user)->get(route('settings.channels.index'))->assertInertia(fn (Assert $page) => $page
        ->where('page.channels.0.values', ['targets' => '-100123']));

    $this->patch(route('settings.channels.update', $channel), ['config' => ['bot_token' => '', 'targets' => '-100999']])->assertSessionHasNoErrors();

    expect($channel->refresh()->config)->toBe([
        'bot_token' => '123456789:ABCdef-ghi_jkl',
        'targets' => '-100999',
        AppriseChannel::URLS => 'tgram://123456789%3AABCdef-ghi_jkl/-100999',
    ]);
});

test('an e-mail through Apprise comes from the instance, not from Apprise', function () {
    enableApprise();
    config(['app.name' => 'VideTrack']);
    FakeHostResolver::$hosts['mail.example.com'] = ['93.184.215.14'];
    $this->actingAs(User::factory()->create());

    addAppriseChannel('mailtos', ['host' => 'mail.example.com', 'user' => 'alex', 'password' => 'app password', 'targets' => 'alex+videtrack@example.com'])->assertSessionHasNoErrors();

    expect(NotificationChannel::query()->sole()->config[AppriseChannel::URLS])->toBe('mailtos://alex:app%20password@mail.example.com/alex%2Bvidetrack%40example.com?from=VideTrack');
});
