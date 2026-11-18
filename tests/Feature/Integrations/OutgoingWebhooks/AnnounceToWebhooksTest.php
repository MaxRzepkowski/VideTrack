<?php

use App\Actions\Library\AddToList;
use App\Actions\Library\DeletePlay;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\RemoveListItem;
use App\Actions\Library\RemoveRating;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Media\SendMediaNotification;
use App\Actions\Users\RecordOutgoingWebhookAnswer;
use App\Catalog\Data\LocalizedTitle;
use App\Enums\MediaUserStatus;
use App\Events\Media\MediaEventDetected;
use App\Integrations\OutgoingWebhooks\AnnounceToWebhooks;
use App\Integrations\OutgoingWebhooks\DeliverOutgoingWebhook;
use App\Integrations\OutgoingWebhooks\WebhookClient;
use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Integrations\OutgoingWebhooks\WebhookMessages;
use App\Library\Data\ListTarget;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\OutgoingWebhook;
use App\Models\Person;
use App\Models\User;
use App\Notifications\MediaEvent;
use App\Notifications\NotificationCopy;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use App\Support\Delivery\DeliveryRetry;
use Carbon\CarbonImmutable;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/*
 * What a webhook hears: the library's changes as the API shows what changed,
 * and the bell's notifications under the API's own ids — each only where its
 * group is ticked, never from an import, signed every time.
 */

/** Every message sent so far, decoded, in order. */
function webhookMessages(): array
{
    return Http::recorded()->map(fn (array $pair): array => json_decode($pair[0]->body(), true))->values()->all();
}

/** A receiver at the factory's address that answers every message the same. */
function fakeReceiver(int $status = 204): void
{
    Http::fake([OutgoingWebhookFactory::URL => Http::response(null, $status)]);
}

test('a rating reaches a webhook that ticked ratings, signed, with the card the API shows', function () {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->listeningTo([WebhookEventGroup::Ratings])->create();
    $movie = MediaItem::factory()->movie()->create(['title' => 'Dune']);

    app(RateItem::class)->execute($webhook->user, $movie, 8);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => signedByWebhook($request, $webhook->secret)
        && $request['type'] === 'rating.changed'
        && $request['source'] === 'ui'
        && $request['data']['media']['title'] === 'Dune'
        && $request['data']['media']['ref'] === $movie->provider_ref
        && array_key_exists('posterUrl', $request['data']['media'])
        && $request['data']['rating'] === 8
        && $request['data']['previous'] === null);
});

test('an episode marked watched is one message with its plays, each with the card of what it closed', function () {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->listeningTo([WebhookEventGroup::Activity])->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $episode = episodeOf($show, 1, 2);

    app(MarkWatched::class)->execute($webhook->user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-10-09 21:00')));

    [$message] = webhookMessages();
    expect($message['type'])->toBe('play.recorded')
        ->and($message['data']['media']['ref'])->toBe($episode->provider_ref)
        ->and($message['data']['plays'])->toHaveCount(1)
        ->and($message['data']['plays'][0]['outcome'])->toBe('completed')
        ->and($message['data']['plays'][0]['cycle'])->toBe(1)
        ->and($message['data']['plays'][0]['media']['ref'])->toBe($episode->provider_ref);
});

test('a title added to a list names the list and the title', function () {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->listeningTo([WebhookEventGroup::Lists])->create();
    $list = MediaList::factory()->for($webhook->user)->create(['title' => 'Weekend']);
    $movie = MediaItem::factory()->movie()->create(['title' => 'Arrival']);

    app(AddToList::class)->execute($webhook->user, $list, ListTarget::item($movie->id));

    [$message] = webhookMessages();
    expect($message['type'])->toBe('list.item_added')
        ->and($message['data']['list'])->toBe(['id' => $list->id, 'title' => 'Weekend'])
        ->and($message['data']['media']['title'])->toBe('Arrival')
        ->and($message['data']['person'])->toBeNull();
});

test('a notification reaches a webhook under the id the API gives it, as the API lists it', function () {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->listeningTo([WebhookEventGroup::Notifications])->create();

    app(SendMediaNotification::class)->execute($webhook->user, premiereEvent());

    $stored = $webhook->user->notifications()->sole();
    Http::assertSent(fn (Request $request): bool => signedByWebhook($request, $webhook->secret)
        && $request->header('webhook-id')[0] === $stored->id
        && $request['type'] === 'notification.created'
        && ! array_key_exists('source', $request->data())
        && $request['data']['notification'] == app(NotificationCopy::class)->entry($stored, 'en')->jsonSerialize());
});

test('only the groups a webhook ticked reach it, and a webhook switched off hears nothing', function () {
    fakeReceiver();
    $user = User::factory()->create();
    OutgoingWebhook::factory()->for($user)->listeningTo([WebhookEventGroup::Ratings])->create();
    OutgoingWebhook::factory()->for($user)->disabled()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(SetFavorite::class)->execute($user, $movie, true);
    expect(webhookMessages())->toBe([]);

    app(RateItem::class)->execute($user, $movie, 6);
    expect(array_column(webhookMessages(), 'type'))->toBe(['rating.changed']);
});

test('every webhook that ticked the group gets the same message, and other accounts get nothing', function () {
    fakeReceiver();
    Queue::fake([DeliverOutgoingWebhook::class]);
    $user = User::factory()->create();
    OutgoingWebhook::factory()->for($user)->count(2)->create();
    OutgoingWebhook::factory()->create();

    app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(), 9);

    Queue::assertPushed(DeliverOutgoingWebhook::class, 2);
    // The address and the secret stay out of the queue.
    expect(serialize(Queue::pushed(DeliverOutgoingWebhook::class)->first()))->not->toContain(OutgoingWebhookFactory::URL)->not->toContain($user->outgoingWebhooks()->firstOrFail()->secret);
});

test('an import tells no webhook, a scrobble does and says so', function () {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $context = app(MutationContext::class);

    $context->run(MutationSource::import('trakt'), fn () => app(RateItem::class)->execute($webhook->user, $movie, 7));
    Http::assertNothingSent();

    $context->run(MutationSource::scrobble('jellyfin'), fn () => app(MarkWatched::class)->execute($webhook->user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-10-09 21:00'))));
    expect(array_column(webhookMessages(), 'source'))->toBe(['scrobble:jellyfin']);
});

test('a receiver that says the address is closed for good switches the webhook off; a 404 only tells', function (int $status, DeliveryAnswer $failure, bool $enabled) {
    Sleep::fake();
    fakeReceiver($status);
    $webhook = OutgoingWebhook::factory()->create();

    app(RateItem::class)->execute($webhook->user, MediaItem::factory()->movie()->create(), 5);

    expect($webhook->refresh()->last_failure)->toBe($failure)
        ->and($webhook->enabled)->toBe($enabled);
})->with([
    'gone for good' => [410, DeliveryAnswer::Closed, false],
    'not found' => [404, DeliveryAnswer::Gone, true],
    'refused' => [400, DeliveryAnswer::Refused, true],
]);

test('a receiver that is down is tried again later with the same message, a refusal is not', function () {
    Sleep::fake();
    // Down for every quick try of the first delivery, then a refusal.
    $answers = Http::sequence();
    foreach (range(1, DeliveryHttpClient::TRIES) as $try) {
        $answers->push(null, 503);
    }
    Http::fake([OutgoingWebhookFactory::URL => $answers->push(null, 400)]);
    $webhook = OutgoingWebhook::factory()->create();
    Queue::fake([DeliverOutgoingWebhook::class]);
    app(RateItem::class)->execute($webhook->user, MediaItem::factory()->movie()->create(), 5);
    $job = Queue::pushed(DeliverOutgoingWebhook::class)->first();

    $retried = $job->withFakeQueueInteractions();
    $retried->handle(app(WebhookClient::class), app(RecordOutgoingWebhookAnswer::class));
    $retried->assertReleased(DeliveryRetry::UNAVAILABLE_AFTER[0]);

    $refused = $job->withFakeQueueInteractions();
    $refused->handle(app(WebhookClient::class), app(RecordOutgoingWebhookAnswer::class));
    $refused->assertNotReleased();
});

test('every change the library announces either reaches webhooks or is named as staying inside', function () {
    $staysInside = ['CreatorBackingWithdrawn', 'CreatorTitlesBacked', 'FollowChanged', 'LibraryCleared', 'LibraryProjected', 'ListCreated', 'ListDeleted', 'ListItemsReordered', 'ListsReordered', 'ListUpdated', 'PlayAmountChanged', 'RewatchCancelled'];
    $announced = array_map(class_basename(...), array_keys(WebhookMessages::TYPES));
    $events = array_map(fn (string $file): string => basename($file, '.php'), glob(app_path('Library/Events/*.php')) ?: []);
    $named = [...$announced, ...$staysInside];
    sort($events);
    sort($named);

    expect($named)->toBe($events);
});

test('each change says what changed, in the shapes the API uses', function (Closure $write, string $type, Closure $data) {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['title' => 'Heat']);

    $write($webhook->user, $movie);

    $message = collect(webhookMessages())->firstWhere('type', $type);
    expect($message)->not->toBeNull()
        ->and($data($message['data'], $movie))->toBeTrue();
})->with([
    'a play removed' => [
        function (User $user, MediaItem $movie): void {
            app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-10-09 21:00')));
            app(DeletePlay::class)->execute($user, $user->plays()->sole());
        },
        'play.removed',
        fn (array $data, MediaItem $movie): bool => $data['media']['title'] === 'Heat' && count($data['playIds']) === 1,
    ],
    'progress logged' => [
        fn (User $user) => app(LogProgress::class)->execute($user, MediaItem::factory()->book()->create(['title' => 'Heat']), 40, null),
        'progress.logged',
        fn (array $data): bool => $data['media']['title'] === 'Heat' && $data['added'] === 40 && $data['set'] === null && $data['progress'] === 40,
    ],
    'a rating removed' => [
        function (User $user, MediaItem $movie): void {
            app(RateItem::class)->execute($user, $movie, 7);
            app(RemoveRating::class)->execute($user, $movie);
        },
        'rating.removed',
        fn (array $data): bool => $data['media']['title'] === 'Heat',
    ],
    'a favorite' => [
        fn (User $user, MediaItem $movie) => app(SetFavorite::class)->execute($user, $movie, true),
        'favorite.changed',
        fn (array $data): bool => $data['media']['title'] === 'Heat' && $data['favorite'] === true,
    ],
    'a status' => [
        fn (User $user, MediaItem $movie) => app(SetMediaStatus::class)->execute($user, $movie, MediaUserStatus::OnHold),
        'status.changed',
        fn (array $data): bool => $data['media']['title'] === 'Heat' && $data['status'] === 'on_hold' && $data['previous'] === null,
    ],
    'a rewatch' => [
        function (User $user): void {
            $show = makeShowTree([1 => ['2020-01-01']]);
            $show->update(['title' => 'Heat']);
            app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-10-09 21:00')));
            app(StartRewatch::class)->execute($user, $show);
        },
        'rewatch.started',
        fn (array $data): bool => $data['media']['title'] === 'Heat' && $data['cycle'] === 2,
    ],
    'a title taken off a list' => [
        function (User $user, MediaItem $movie): void {
            $list = MediaList::factory()->for($user)->create(['title' => 'Weekend']);
            app(AddToList::class)->execute($user, $list, ListTarget::item($movie->id));
            app(RemoveListItem::class)->execute($list, $list->items()->sole());
        },
        'list.item_removed',
        fn (array $data): bool => $data['list']['title'] === 'Weekend' && $data['media']['title'] === 'Heat',
    ],
    'a person on a list' => [
        function (User $user): void {
            $list = MediaList::factory()->for($user)->create(['title' => 'Directors']);
            app(AddToList::class)->execute($user, $list, ListTarget::person(Person::factory()->create(['name' => 'Michael Mann'])->id));
        },
        'list.item_added',
        fn (array $data): bool => $data['list']['title'] === 'Directors' && $data['media'] === null && $data['person']['name'] === 'Michael Mann' && $data['company'] === null,
    ],
]);

test("a card names its title in the owner's language, whoever's request made the change", function () {
    fakeReceiver();
    $owner = User::factory()->create(['locale' => 'pl']);
    OutgoingWebhook::factory()->for($owner)->listeningTo([WebhookEventGroup::Ratings])->create();
    $movie = MediaItem::factory()->movie()->create(['localized_titles' => ['en' => new LocalizedTitle('Heat'), 'pl' => new LocalizedTitle('Gorączka')]]);

    app(MutationContext::class)->run(MutationSource::api(), fn () => app(RateItem::class)->execute($owner, $movie, 9));

    [$message] = webhookMessages();
    expect($message['data']['media']['title'])->toBe('Gorączka')
        // The request's own language is back for whatever it does next.
        ->and(App::getLocale())->toBe('en');
});

test('a notification deleted from the bell before the queue got to it is not sent', function () {
    fakeReceiver();
    $webhook = OutgoingWebhook::factory()->listeningTo([WebhookEventGroup::Notifications])->create();
    $notification = new MediaEvent(premiereEvent());
    $webhook->user->notify($notification);
    $id = $notification->id ?? throw new LogicException('A media event has its id from the start.');
    $webhook->user->notifications()->delete();

    app(AnnounceToWebhooks::class)->handleNotification(new MediaEventDetected($webhook->user->id, premiereEvent(), $id));

    Http::assertNothingSent();
});

test('a test the receiver answers with closed for good switches the webhook off, as a delivery would', function () {
    fakeReceiver(410);
    $webhook = OutgoingWebhook::factory()->create();

    $this->actingAs($webhook->user)->post(route('settings.webhooks.test', $webhook))->assertSessionHasErrors('test');

    expect($webhook->refresh()->enabled)->toBeFalse()
        ->and($webhook->last_failure)->toBe(DeliveryAnswer::Closed);
});

test('a change nobody listens to is not queued at all', function () {
    Queue::fake();
    $user = User::factory()->create();
    OutgoingWebhook::factory()->for($user)->listeningTo([WebhookEventGroup::Lists])->create();
    OutgoingWebhook::factory()->for($user)->disabled()->create();

    app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(), 8);

    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === AnnounceToWebhooks::class);
});
