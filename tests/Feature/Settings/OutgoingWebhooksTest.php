<?php

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Integrations\OutgoingWebhooks\WebhookSignature;
use App\Models\OutgoingWebhook;
use App\Models\User;
use App\Support\Delivery\DeliveryAnswer;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The webhooks page: adding, changing, switching off, testing and removing a
 * webhook. Its address and secret are credentials — stored encrypted, never
 * sent back to a page.
 */

const HOME_ASSISTANT = 'http://192.168.1.20:8123/api/webhook/library';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookForm(array $overrides = []): array
{
    return ['label' => '', 'url' => HOME_ASSISTANT, 'secret' => WebhookSignature::newSecret(), 'events' => ['ratings', 'lists'], ...$overrides];
}

test('the page lists the webhooks by name and host, never their address or secret, and offers a fresh secret', function () {
    $user = User::factory()->create();
    OutgoingWebhook::factory()->for($user)->listeningTo([WebhookEventGroup::Ratings])->create(['url' => 'https://n8n.example.com/webhook/s3cr3t-path', 'last_failure' => DeliveryAnswer::Refused]);

    $this->actingAs($user)->get(route('settings.webhooks.index'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/Webhooks')
        ->has('page.webhooks', 1)
        ->where('page.webhooks.0.label', 'n8n')
        ->where('page.webhooks.0.host', 'n8n.example.com')
        ->where('page.webhooks.0.events', ['ratings'])
        ->where('page.webhooks.0.lastFailure', __('settings.delivery_failure_refused'))
        ->missing('page.webhooks.0.url')
        ->missing('page.webhooks.0.secret')
        ->where('page.groups', fn ($groups): bool => collect($groups)->pluck('code')->all() === array_column(WebhookEventGroup::cases(), 'value'))
        ->where('page.newSecret', fn (string $secret): bool => WebhookSignature::isValidSecret($secret))
        ->where('page.limit', OutgoingWebhook::PER_USER));
});

test('a webhook is added encrypted, on the local network too, named after its host unless named', function () {
    Http::fake();
    $user = User::factory()->create();

    $form = webhookForm();

    $this->actingAs($user)->post(route('settings.webhooks.store'), $form)->assertSessionHasNoErrors();

    $webhook = $user->outgoingWebhooks()->sole();
    expect($webhook->label)->toBe('192.168.1.20:8123')
        ->and($webhook->url)->toBe(HOME_ASSISTANT)
        ->and($webhook->secret)->toBe($form['secret'])
        ->and($webhook->events->all())->toBe([WebhookEventGroup::Ratings, WebhookEventGroup::Lists])
        ->and($webhook->enabled)->toBeTrue()
        ->and((string) DB::table('outgoing_webhooks')->value('url'))->not->toContain('192.168')
        ->and((string) DB::table('outgoing_webhooks')->value('secret'))->not->toContain('whsec_');
    // Saving sends nothing.
    Http::assertNothingSent();
});

test('only an http address, a secret of the standard and known events make a webhook', function (array $form, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('settings.webhooks.store'), webhookForm($form))->assertSessionHasErrors($field);

    expect($user->outgoingWebhooks()->count())->toBe(0);
})->with([
    'another scheme' => [['url' => 'ftp://n8n.example.com/hook'], 'url'],
    'no address' => [['url' => ''], 'url'],
    'a password for a secret' => [['secret' => 'hunter2'], 'secret'],
    'no secret' => [['secret' => ''], 'secret'],
    'an unknown event' => [['events' => ['weather']], 'events.0'],
]);

test('an account keeps a limited number of webhooks', function () {
    $user = User::factory()->create();
    OutgoingWebhook::factory()->for($user)->count(OutgoingWebhook::PER_USER)->create();

    $this->actingAs($user)->post(route('settings.webhooks.store'), webhookForm())->assertSessionHasErrors('url');

    expect($user->outgoingWebhooks()->count())->toBe(OutgoingWebhook::PER_USER);
});

test('a change keeps the saved address and secret when left out, a new address forgets its failure', function () {
    $webhook = OutgoingWebhook::factory()->create(['last_failure' => DeliveryAnswer::Gone]);
    $saved = $webhook->secret;
    $this->actingAs($webhook->user);

    $this->patch(route('settings.webhooks.update', $webhook), ['label' => 'Home', 'events' => ['activity']])->assertSessionHasNoErrors();
    $webhook->refresh();
    expect($webhook->label)->toBe('Home')
        ->and($webhook->url)->toBe(OutgoingWebhookFactory::URL)
        ->and($webhook->secret)->toBe($saved)
        ->and($webhook->events->all())->toBe([WebhookEventGroup::Activity])
        ->and($webhook->last_failure)->toBe(DeliveryAnswer::Gone);

    $secret = WebhookSignature::newSecret();
    $this->patch(route('settings.webhooks.update', $webhook), ['url' => HOME_ASSISTANT, 'secret' => $secret, 'label' => ''])->assertSessionHasNoErrors();
    $webhook->refresh();
    expect($webhook->url)->toBe(HOME_ASSISTANT)
        ->and($webhook->secret)->toBe($secret)
        ->and($webhook->label)->toBe('192.168.1.20:8123')
        ->and($webhook->last_failure)->toBeNull();
});

test('the switch alone turns a webhook off and on, the rest as saved', function () {
    $webhook = OutgoingWebhook::factory()->create();
    $this->actingAs($webhook->user);

    $this->patch(route('settings.webhooks.update', $webhook), ['enabled' => false])->assertSessionHasNoErrors();
    expect($webhook->refresh()->enabled)->toBeFalse()
        ->and($webhook->events->all())->toBe(WebhookEventGroup::cases());

    $this->patch(route('settings.webhooks.update', $webhook), ['enabled' => true]);
    expect($webhook->refresh()->enabled)->toBeTrue();
});

test('the test button sends a signed test now and says when it did not arrive', function () {
    Http::fake(['n8n.example.com/*' => Http::sequence()->push(null, 204)->push(null, 500)->push(null, 500)]);
    $webhook = OutgoingWebhook::factory()->create(['last_failure' => DeliveryAnswer::Refused]);
    $this->actingAs($webhook->user);

    $this->post(route('settings.webhooks.test', $webhook))->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request): bool => $request['type'] === 'webhook.test'
        && $request['data']['url'] === url(route('settings.webhooks.index', absolute: false))
        && signedByWebhook($request, $webhook->secret));
    expect($webhook->refresh()->last_failure)->toBeNull();

    $this->post(route('settings.webhooks.test', $webhook))->assertSessionHasErrors('test');
    expect($webhook->refresh()->last_failure)->toBe(DeliveryAnswer::Unavailable);
});

test('a webhook is removed', function () {
    $webhook = OutgoingWebhook::factory()->create();

    $this->actingAs($webhook->user)->delete(route('settings.webhooks.destroy', $webhook))->assertRedirect();

    expect(OutgoingWebhook::query()->count())->toBe(0);
});

test("somebody else's webhook is not found", function () {
    Http::fake();
    $webhook = OutgoingWebhook::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->patch(route('settings.webhooks.update', $webhook), ['enabled' => false])->assertNotFound();
    $this->post(route('settings.webhooks.test', $webhook))->assertNotFound();
    $this->delete(route('settings.webhooks.destroy', $webhook))->assertNotFound();

    expect($webhook->refresh()->enabled)->toBeTrue();
    Http::assertNothingSent();
});
