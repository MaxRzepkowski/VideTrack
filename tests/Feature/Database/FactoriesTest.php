<?php

use App\Models\Company;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Person;
use App\Models\Play;
use App\Models\Rating;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WatchCycle;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Model;

// A new NOT NULL column breaks the factory here, once, instead of in every
// test that builds the model.
test('every factory builds a row the schema accepts', function (string $model) {
    /** @var class-string<Model> $model */
    $created = $model::factory()->create();

    expect($created->exists)->toBeTrue();

    projectEveryLibrary();
})->with([
    User::class, MediaItem::class, Play::class, Follow::class, MediaUserState::class, Person::class,
    Company::class, Rating::class, MediaList::class, WatchCycle::class, ServiceConnection::class, WebhookEndpoint::class,
]);

test('user states mean what they say', function () {
    expect(User::factory()->admin()->create()->isAdmin())->toBeTrue()
        ->and(User::factory()->blocked()->create()->isBlocked())->toBeTrue()
        ->and(User::factory()->private()->create()->is_private)->toBeTrue();
});
