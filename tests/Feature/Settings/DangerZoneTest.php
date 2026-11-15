<?php

use App\Actions\Library\ClearUserData;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Person;
use App\Models\Play;
use App\Models\Rating;
use App\Models\RecentView;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WatchCycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function seedUserData(User $user): void
{
    $movie = MediaItem::factory()->movie()->create();
    $show = MediaItem::factory()->show()->create();

    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'watched_at' => now()]);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 7]);
    Favorite::query()->create(['user_id' => $user->id, 'media_item_id' => $movie->id]);
    MediaUserState::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'status' => MediaUserStatus::OnHold]);
    $user->watchlist()->items()->create(['media_item_id' => $show->id, 'position' => 1]);
    $user->lists()->create(['title' => 'Faves', 'is_public' => true])
        ->items()->create(['media_item_id' => $movie->id, 'position' => 1]);

    projectLibrary($user);
}

test('the danger zone page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/danger')
        ->assertOk();
});

test('clearing data wipes every media state but keeps the account', function () {
    $user = User::factory()->create(['password' => 'secret-password']);
    seedUserData($user);

    $this->actingAs($user)
        ->delete('/settings/data', ['password' => 'secret-password'])
        ->assertRedirect();

    expect($user->plays()->count())->toBe(0)
        ->and($user->ratings()->count())->toBe(0)
        ->and($user->favorites()->count())->toBe(0)
        ->and($user->mediaStates()->count())->toBe(0)
        // The watchlist is part of the account: it stays, emptied.
        ->and($user->lists()->pluck('system_key')->all())->toBe(['watchlist'])
        ->and($user->fresh())->not->toBeNull();

    expect($user->watchlist()->items()->count())->toBe(0);
});

test('clearing data leaves other users untouched', function () {
    $user = User::factory()->create(['password' => 'secret-password']);
    $other = User::factory()->create();
    seedUserData($other);

    $this->actingAs($user)->delete('/settings/data', ['password' => 'secret-password']);

    expect($other->plays()->count())->toBe(1)
        ->and($other->ratings()->count())->toBe(1)
        ->and($other->lists()->count())->toBe(2);
});

test('clearing data requires the current password', function () {
    $user = User::factory()->create(['password' => 'secret-password']);
    seedUserData($user);

    $this->actingAs($user)
        ->from('/settings/danger')
        ->delete('/settings/data', ['password' => 'wrong'])
        ->assertRedirect('/settings/danger')
        ->assertSessionHasErrors('password');

    expect($user->plays()->count())->toBe(1);
});

test('deleting the account removes its avatar file too', function () {
    Storage::fake('public');
    $user = User::factory()->create(['password' => 'secret-password']);
    $path = UploadedFile::fake()->image('me.png', 200, 200)->store('avatars', 'public');
    $user->forceFill(['avatar_path' => $path])->save();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'secret-password'])
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('deleting the account revokes its API tokens, ends its sessions everywhere and drops its notifications', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'secret-password']);
    $user->createToken('script');
    DB::table('sessions')->insert(['id' => 'elsewhere', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'release', 'data' => []]);

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'secret-password'])
        ->assertRedirect('/');

    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('notifications')->count())->toBe(0);
});

test('clearing data also drops follows, rewatch cycles and recently viewed, and rereads connected accounts', function () {
    $user = User::factory()->create(['password' => 'secret-password']);
    $movie = MediaItem::factory()->movie()->create();
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Follow]);
    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);
    WatchCycle::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'number' => 2, 'started_at' => now()]);
    RecentView::query()->create(['user_id' => $user->id, 'type' => 'movie', 'provider_ref' => '603', 'title' => 'The Matrix', 'viewed_at' => now()]);
    $connection = ServiceConnection::factory()->create([
        'user_id' => $user->id,
        'service' => 'simkl',
        'credentials' => ['access_token' => 'at-1'],
        'status' => ConnectionStatus::Active,
        'state' => ['date_from' => '2026-10-01T00:00:00Z'],
    ]);

    $this->actingAs($user)->delete('/settings/data', ['password' => 'secret-password'])->assertRedirect();

    expect(Follow::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(WatchCycle::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(RecentView::query()->where('user_id', $user->id)->count())->toBe(0)
        // The account stays connected, but its next import reads everything
        // again, so the cleared library can be filled anew.
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($connection->state)->toBeNull();
});

test('every table keyed by a user is either wiped with the library or named as the account\'s', function () {
    $keyed = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table): bool => Schema::hasColumn($table, 'user_id'))
        ->sort()->values()->all();

    expect(collect([...ClearUserData::CLEARED, ...ClearUserData::KEPT])->sort()->values()->all())->toBe($keyed);
});
